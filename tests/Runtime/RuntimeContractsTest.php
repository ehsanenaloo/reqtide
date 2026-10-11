<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Runtime;

use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Completion\SettlementOverride;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\OperationSafetyKind;
use Enaxon\Reqtide\Execution\ReplayPreparation;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Runtime\AttemptTimeoutCapability;
use Enaxon\Reqtide\Runtime\CancellationCapability;
use Enaxon\Reqtide\Runtime\DetachedCompletionCapability;
use Enaxon\Reqtide\Runtime\InvocationForm;
use Enaxon\Reqtide\Runtime\NonblockingCapability;
use Enaxon\Reqtide\Runtime\PhysicalStopCapability;
use Enaxon\Reqtide\Runtime\QueueOwnership;
use Enaxon\Reqtide\Runtime\RuntimeCapabilities;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Runtime\SettlementCapability;
use Enaxon\Reqtide\Runtime\SettlementObservation;
use Enaxon\Reqtide\Tests\Support\FakeClock;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Time\NeverCancelled;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;
use PHPUnit\Framework\TestCase;

final class RuntimeContractsTest extends TestCase
{
    private static function capabilities(
        InvocationForm $invocation,
        SettlementCapability $settlement,
        QueueOwnership $queue,
        SchedulingCapability $scheduling,
        CancellationCapability $cancellation = CancellationCapability::CheckpointOnly,
    ): RuntimeCapabilities {
        return new RuntimeCapabilities(
            $invocation,
            AttemptTimeoutCapability::TransportEnforced,
            $scheduling,
            $queue,
            $cancellation,
            $settlement,
            DetachedCompletionCapability::Unsupported,
            NonblockingCapability::NotClaimed,
            PhysicalStopCapability::NotGuaranteed,
        );
    }

    public function testValidCapabilityCombinations(): void
    {
        $sync = self::capabilities(InvocationForm::Synchronous, SettlementCapability::InlineReturnOrThrow, QueueOwnership::None, SchedulingCapability::BoundedBlocking);
        self::assertSame(InvocationForm::Synchronous, $sync->invocation);
        $promise = self::capabilities(InvocationForm::ObservedPromise, SettlementCapability::TerminalPromiseSettlement, QueueOwnership::AdapterOwned, SchedulingCapability::AdapterDrivenQueue, CancellationCapability::TransportRequest);
        self::assertSame(QueueOwnership::AdapterOwned, $promise->queueOwnership);
        $noSched = self::capabilities(InvocationForm::Synchronous, SettlementCapability::InlineReturnOrThrow, QueueOwnership::None, SchedulingCapability::None);
        self::assertSame(SchedulingCapability::None, $noSched->scheduling);
    }

    public function testIncompatibleCapabilityCombinationsAreRejected(): void
    {
        $cases = [
            'sync with promise settlement' => [InvocationForm::Synchronous, SettlementCapability::TerminalPromiseSettlement, QueueOwnership::None, SchedulingCapability::None],
            'promise with inline settlement' => [InvocationForm::ObservedPromise, SettlementCapability::InlineReturnOrThrow, QueueOwnership::AdapterOwned, SchedulingCapability::AdapterDrivenQueue],
            'owned queue without driven scheduling' => [InvocationForm::ObservedPromise, SettlementCapability::TerminalPromiseSettlement, QueueOwnership::AdapterOwned, SchedulingCapability::BoundedBlocking],
            'driven scheduling without queue' => [InvocationForm::Synchronous, SettlementCapability::InlineReturnOrThrow, QueueOwnership::None, SchedulingCapability::AdapterDrivenQueue],
            'sync with queue' => [InvocationForm::Synchronous, SettlementCapability::InlineReturnOrThrow, QueueOwnership::AdapterOwned, SchedulingCapability::AdapterDrivenQueue],
            'promise without queue' => [InvocationForm::ObservedPromise, SettlementCapability::TerminalPromiseSettlement, QueueOwnership::None, SchedulingCapability::None],
        ];
        foreach ($cases as $label => [$invocation, $settlement, $queue, $scheduling]) {
            try {
                self::capabilities($invocation, $settlement, $queue, $scheduling);
                self::fail('Should reject: ' . $label);
            } catch (InvalidConfigurationException $e) {
                self::assertInstanceOf(InvalidConfigurationException::class, $e);
            }
        }
        $this->expectException(InvalidConfigurationException::class);
        self::capabilities(InvocationForm::Synchronous, SettlementCapability::InlineReturnOrThrow, QueueOwnership::None, SchedulingCapability::None, CancellationCapability::TransportRequest);
    }

    /** @return Outcome<string> */
    private static function outcome(): Outcome
    {
        return Outcome::fromValue('v', BoundedMetadata::empty());
    }

    public function testSettlementObservationTimingInvariant(): void
    {
        $ok = new SettlementObservation(self::outcome(), SettlementOverride::None, 5, new Duration(1), true, []);
        self::assertTrue($ok->accountingComplete);
        $degraded = new SettlementObservation(self::outcome(), SettlementOverride::None, null, null, false, [new Diagnostic('clock', 'unavailable')]);
        self::assertNull($degraded->elapsed);

        foreach ([[true, [new Diagnostic('clock', 'unavailable')]], [false, []]] as [$complete, $diagnostics]) {
            try {
                new SettlementObservation(self::outcome(), SettlementOverride::None, null, null, $complete, $diagnostics);
                self::fail('Unavailable timing needs diagnostic and incomplete accounting.');
            } catch (InvalidConfigurationException $e) {
                self::assertInstanceOf(InvalidConfigurationException::class, $e);
            }
        }
        $this->expectException(InvalidConfigurationException::class);
        new SettlementObservation(self::outcome(), SettlementOverride::None, 5, new Duration(1), true, array_fill(0, 33, new Diagnostic('a', 'b')));
    }

    public function testAttemptContextValidation(): void
    {
        $clock = new FakeClock();
        $deadline = Deadline::after(Duration::seconds(1), $clock);
        $make = static fn(int $number, Duration $timeout): AttemptContext => new AttemptContext(
            new OperationId('op'),
            AttemptId::fromLowerHex(Fixtures::hex(1)),
            $number,
            $deadline,
            new NeverCancelled(),
            $timeout,
        );
        self::assertSame(1, $make(1, new Duration(1))->dispatchedAttemptNumber);
        try {
            $make(0, new Duration(1));
            self::fail('Attempt number zero must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertInstanceOf(InvalidConfigurationException::class, $e);
        }
        $this->expectException(InvalidConfigurationException::class);
        $make(1, Duration::zero());
    }

    // ---- replay contract ----

    /** @param list<string> $codes */
    private static function replay(array $codes = ['http_503'], string $until = '2026-01-02T00:00:00+00:00', string $identity = 'idem-1', Duration $retention = new Duration(1)): ReplaySafetyContract
    {
        return new ReplaySafetyContract(
            'contract-ref',
            $identity,
            'provider-scope',
            'duplicates-are-deduplicated',
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            new \DateTimeImmutable($until),
            $retention,
            ReplayPreparation::BodyFactory,
            $codes,
        );
    }

    public function testOperationSafetyFactoriesCarryContracts(): void
    {
        self::assertNull(OperationSafety::unsafe()->contract);
        self::assertSame(OperationSafetyKind::Unsafe, OperationSafety::unsafe()->kind);
        self::assertSame(OperationSafetyKind::ReplayableRead, OperationSafety::replayableRead(self::replay())->kind);
        self::assertNotNull(OperationSafety::providerIdempotentMutation(self::replay())->contract);
    }

    public function testReplayContractBounds(): void
    {
        self::assertSame(128, strlen(self::replay(identity: str_repeat('i', 128))->identityValue));
        self::assertCount(32, self::replay(array_map(static fn(int $i): string => 'c' . $i, range(1, 32)))->retryableOutcomeCodes);

        $bad = [
            'identity 129' => static fn() => self::replay(identity: str_repeat('i', 129)),
            'identity empty' => static fn() => self::replay(identity: ''),
            '33 codes' => static fn() => self::replay(array_map(static fn(int $i): string => 'c' . $i, range(1, 33))),
            'duplicate codes' => static fn() => self::replay(['a', 'a']),
            'bad code' => static fn() => self::replay(['has space']),
            'validUntil == establishedAt' => static fn() => self::replay(until: '2026-01-01T00:00:00+00:00'),
            'validUntil before' => static fn() => self::replay(until: '2025-01-01T00:00:00+00:00'),
            'non utc' => static fn() => self::replay(until: '2026-01-02T00:00:00+02:00'),
            'zero retention' => static fn() => self::replay(retention: Duration::zero()),
        ];
        foreach ($bad as $label => $build) {
            try {
                $build();
                self::fail('Should reject: ' . $label);
            } catch (InvalidConfigurationException $e) {
                self::assertInstanceOf(InvalidConfigurationException::class, $e);
            }
        }
    }
}
