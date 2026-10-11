<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Unit;

use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Redis\Client\BridgeCapabilities;
use Enaxon\Reqtide\Redis\Client\PortReply;
use Enaxon\Reqtide\Redis\Client\RedisBridge;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRetryBudgetBackend;
use Enaxon\Reqtide\Redis\Tests\Support\ScriptedPort;
use Enaxon\Reqtide\State\RetryBudgetFinalizationStatus;
use Enaxon\Reqtide\State\RetryBudgetReservation;
use Enaxon\Reqtide\State\RetryReservationStatus;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Tests\Contract\ContractIdentities;
use Enaxon\Reqtide\Value\BackendClockKind;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The Redis retry-budget backend against a scripted port: argument encoding, reply parsing, fault mapping. No server. */
final class RedisRetryBudgetReplyTest extends TestCase
{
    private const INCARNATION = '0123456789abcdef0123456789abcdef';

    private ?ContractIdentities $identities = null;

    private function ids(): ContractIdentities
    {
        return $this->identities ??= new ContractIdentities();
    }

    private function backend(ScriptedPort $port): RedisRetryBudgetBackend
    {
        return new RedisRetryBudgetBackend(
            new RedisConnectionOptions('127.0.0.1', 1.0, 1.0),
            new RedisRegistry('unit', 'unit-identity', 'a-host-managed-secret-of-32-bytes!!'),
            new RedisBridge($port, new BridgeCapabilities(1.0)),
        );
    }

    private function policy(string $subject = 'subject'): RetryBudgetPolicy
    {
        return new RetryBudgetPolicy(ContractIdentities::identity($subject), new PositiveInt(4), new PositiveInt(2), Duration::nanoseconds(60_000_000_001), Duration::seconds(10));
    }

    private function reservation(): RetryBudgetReservation
    {
        $decision = $this->backend(new ScriptedPort([new PortReply(false, ['1', self::INCARNATION, '1', '1893456110', '250000'])]))
            ->reserve($this->policy(), $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
        self::assertNotNull($decision->reservation);

        return $decision->reservation;
    }

    public function testCapabilitiesDeclareASharedAuthorityAFiniteTimeoutAndMicrosecondResolution(): void
    {
        $capabilities = $this->backend(new ScriptedPort([]))->capabilities();
        self::assertSame(StateScope::SharedAuthority, $capabilities->scope);
        self::assertSame(1_000_000_000, $capabilities->ioTimeout?->nanoseconds);
        self::assertSame(1_000, $capabilities->clockResolution->nanoseconds);
    }

    public function testAReservedReplyBuildsASharedReservation(): void
    {
        $reservation = $this->reservation();
        self::assertSame(self::INCARNATION, $reservation->incarnation->lowerHex);
        self::assertSame(1, $reservation->generation->value);
        self::assertSame(BackendClockKind::SharedAuthority, $reservation->expiresAt->clockKind);
        self::assertSame([1893456110, 250000], [$reservation->expiresAt->sharedInstant?->seconds, $reservation->expiresAt->sharedInstant?->microseconds]);
    }

    public function testReserveSendsTheSettingsWithDurationsRoundedUpAndOneCommandOnly(): void
    {
        $port = new ScriptedPort([new PortReply(false, ['0', ''])]);
        $attempt = $this->ids()->attempt();
        $owner = $this->ids()->owner();
        $this->backend($port)->reserve($this->policy(), $attempt, $owner, $this->ids()->command());

        // EVALSHA digest 5 keys..., then ARGV: identity, schema, capacity, refill, interval us, lease us, fingerprint, attempt, owner, incarnation.
        $arguments = array_slice($port->sent[0], 3 + 5);
        self::assertSame(['unit-identity', '1', '4', '2', '60000001', '10000000', '4|2|60000000001|10000000000', $attempt->lowerHex, $owner->lowerHex], array_slice($arguments, 0, 9));
        self::assertMatchesRegularExpression('~^[0-9a-f]{32}\z~', $arguments[9]);
        self::assertCount(1, $port->sent);
    }

    public function testExhaustedReplyCarriesTheWaitOrNone(): void
    {
        $withWait = $this->backend(new ScriptedPort([new PortReply(false, ['0', '600000'])]))->reserve($this->policy(), $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
        self::assertSame(RetryReservationStatus::Exhausted, $withWait->status);
        self::assertSame(600_000_000, $withWait->retryAfter?->nanoseconds);

        $noHeadroom = $this->backend(new ScriptedPort([new PortReply(false, ['0', ''])]))->reserve($this->policy(), $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
        self::assertNull($noHeadroom->retryAfter);
        self::assertNull($noHeadroom->reservation);
    }

    public function testInspectionRepliesBecomeInspections(): void
    {
        $would = $this->backend(new ScriptedPort([new PortReply(false, ['1', '3', ''])]))->inspect($this->policy());
        self::assertTrue($would->wouldReserve);
        self::assertSame(3, $would->availableTokens->value);

        $wait = $this->backend(new ScriptedPort([new PortReply(false, ['0', '0', '1500'])]))->inspect($this->policy());
        self::assertFalse($wait->wouldReserve);
        self::assertSame(1_500_000, $wait->retryAfter?->nanoseconds);

        $none = $this->backend(new ScriptedPort([new PortReply(false, ['0', '0', ''])]))->inspect($this->policy());
        self::assertNull($none->retryAfter);
    }

    public function testEveryFinalizationCodeMapsToItsStatusAndCarriesTheReservationExactly(): void
    {
        $reservation = $this->reservation();
        $codes = ['1' => RetryBudgetFinalizationStatus::Committed, '2' => RetryBudgetFinalizationStatus::Released, '3' => RetryBudgetFinalizationStatus::AlreadyCommitted, '4' => RetryBudgetFinalizationStatus::AlreadyReleased, '5' => RetryBudgetFinalizationStatus::Expired, '6' => RetryBudgetFinalizationStatus::StaleOwnership];
        foreach ($codes as $code => $expected) {
            $port = new ScriptedPort([new PortReply(false, [(string) $code])]);
            self::assertSame($expected, $this->backend($port)->commit($this->policy(), $reservation, $this->ids()->command())->status);
            // ARGV after the 5 keys: identity, schema, capacity, refill, interval, fingerprint, mode, attempt, owner, incarnation, generation, expiry s, expiry us.
            $arguments = array_slice($port->sent[0], 3 + 5);
            self::assertSame('commit', $arguments[6]);
            self::assertSame([self::INCARNATION, '1', '1893456110', '250000'], array_slice($arguments, 9, 4));
        }

        $port = new ScriptedPort([new PortReply(false, ['2'])]);
        $this->backend($port)->release($this->policy(), $reservation, $this->ids()->command());
        self::assertSame('release', array_slice($port->sent[0], 3 + 5)[6]);
    }

    public function testOnlyACommitAuthorizesADispatch(): void
    {
        $reservation = $this->reservation();
        self::assertTrue($this->backend(new ScriptedPort([new PortReply(false, ['1'])]))->commit($this->policy(), $reservation, $this->ids()->command())->authorizesDispatch());
        self::assertFalse($this->backend(new ScriptedPort([new PortReply(false, ['3'])]))->commit($this->policy(), $reservation, $this->ids()->command())->authorizesDispatch());
    }

    public function testAReservationOfAnotherPolicyIsStaleWithoutAnyCommand(): void
    {
        $reservation = $this->reservation();
        $port = new ScriptedPort([]);
        self::assertSame(RetryBudgetFinalizationStatus::StaleOwnership, $this->backend($port)->release($this->policy('another'), $reservation, $this->ids()->command())->status);
        self::assertSame([], $port->sent);
    }

    public function testATransportFailureAfterTheSendIsIndeterminateForMutationsAndUnavailableForInspection(): void
    {
        $reset = new \RuntimeException('reset');
        $reservation = $this->reservation();

        foreach ([
            fn() => $this->backend(new ScriptedPort([$reset]))->reserve($this->policy(), $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command()),
            fn() => $this->backend(new ScriptedPort([$reset]))->commit($this->policy(), $reservation, $this->ids()->command()),
            fn() => $this->backend(new ScriptedPort([$reset]))->release($this->policy(), $reservation, $this->ids()->command()),
        ] as $call) {
            try {
                $call();
                self::fail('Expected an indeterminate outcome.');
            } catch (IndeterminateStateException $outcome) {
                self::assertInstanceOf(\RuntimeException::class, $outcome->getPrevious());
            }
        }

        $this->expectException(StateUnavailableException::class);
        $this->backend(new ScriptedPort([$reset]))->inspect($this->policy());
    }

    public function testACeilingRejectionIsACapacityException(): void
    {
        $this->expectException(StateCapacityExceededException::class);
        $this->backend(new ScriptedPort([new PortReply(true, 'RT_CAPACITY the state registry is at capacity')]))
            ->reserve($this->policy(), $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedReserveReplies(): iterable
    {
        yield 'not a list' => ['1'];
        yield 'empty' => [[]];
        yield 'unknown status' => [['9', 'x']];
        yield 'reserved with a short list' => [['1', self::INCARNATION]];
        yield 'bad incarnation' => [['1', 'NOT-HEX', '1', '1', '1']];
        yield 'zero generation' => [['1', self::INCARNATION, '0', '1', '1']];
        yield 'microseconds out of range' => [['1', self::INCARNATION, '1', '1', '1000000']];
        yield 'exhausted with a zero wait' => [['0', '0']];
        yield 'exhausted with a number' => [['0', 5]];
    }

    #[DataProvider('malformedReserveReplies')]
    public function testAMalformedReserveReplyIsCorruptState(mixed $reply): void
    {
        $this->expectException(CorruptStateException::class);
        $this->backend(new ScriptedPort([new PortReply(false, $reply)]))->reserve($this->policy(), $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedInspectionReplies(): iterable
    {
        yield 'unknown flag' => [['2', '1', '']];
        yield 'would reserve with a wait' => [['1', '1', '5']];
        yield 'two elements' => [['1', '1']];
        yield 'not text' => [['1', 1, '']];
    }

    #[DataProvider('malformedInspectionReplies')]
    public function testAMalformedInspectionReplyIsCorruptState(mixed $reply): void
    {
        $this->expectException(CorruptStateException::class);
        $this->backend(new ScriptedPort([new PortReply(false, $reply)]))->inspect($this->policy());
    }

    public function testAnUnknownFinalizationCodeIsCorruptState(): void
    {
        $reservation = $this->reservation();
        $this->expectException(CorruptStateException::class);
        $this->backend(new ScriptedPort([new PortReply(false, ['7'])]))->commit($this->policy(), $reservation, $this->ids()->command());
    }
}
