<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Execution;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\OperationSafetyKind;
use Enaxon\Reqtide\Execution\ReplayPreparation;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Pipeline\RuntimeEnvironment;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Runtime\SynchronousRuntimeAdapter;
use Enaxon\Reqtide\State\Memory\MemoryRetryBudgetBackend;
use Enaxon\Reqtide\Telemetry\NullEventSink;
use Enaxon\Reqtide\Tests\Pipeline\Support\CallLog;
use Enaxon\Reqtide\Tests\Pipeline\Support\DispatchSpy;
use Enaxon\Reqtide\Tests\Pipeline\Support\FakeScheduler;
use Enaxon\Reqtide\Tests\Pipeline\Support\PipelineClock;
use Enaxon\Reqtide\Tests\Pipeline\Support\ScriptedRandom;
use Enaxon\Reqtide\Time\Clock;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ReplaySafetyContract::forReadOnlyCallable(): a bounded, UTC, clock-correct caller assertion. */
final class ReadOnlyCallableContractTest extends TestCase
{
    /** A clock whose wall time is a fixed instant; the monotonic reading is irrelevant here. */
    private static function clockAt(string $wall): Clock
    {
        return new class ($wall) implements Clock {
            public function __construct(private readonly string $wall) {}

            public function monotonicNow(): int
            {
                return 1;
            }

            public function wallNow(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->wall);
            }
        };
    }

    public function testTheContractIsAnchoredOnTheGivenClockAndCoversExactlyTheStatedWindow(): void
    {
        $contract = ReplaySafetyContract::forReadOnlyCallable('get-customer', Duration::seconds(3600), self::clockAt('2030-05-06T07:08:09.123456+00:00'));

        self::assertSame('2030-05-06T07:08:09.123456+00:00', $contract->establishedAt->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('2030-05-06T08:08:09.123456+00:00', $contract->validUntil->format('Y-m-d\TH:i:s.uP'));
        self::assertSame(3_600_000_000_000, $contract->retention->nanoseconds);
        self::assertSame(ReplayPreparation::CallableAssertion, $contract->preparation);
        self::assertSame('get-customer', $contract->identityValue);
        self::assertSame(0, $contract->establishedAt->getOffset());
        self::assertSame(0, $contract->validUntil->getOffset());
    }

    public function testANonUtcClockIsNormalisedToUtc(): void
    {
        $contract = ReplaySafetyContract::forReadOnlyCallable('x', Duration::seconds(10), self::clockAt('2030-01-01T12:00:00+05:30'));

        self::assertSame('2030-01-01T06:30:00+00:00', $contract->establishedAt->format('c'));
    }

    public function testWithoutAClockTheSystemWallTimeIsUsed(): void
    {
        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $contract = ReplaySafetyContract::forReadOnlyCallable('x', Duration::seconds(10));
        $after = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        self::assertGreaterThanOrEqual($before, $contract->establishedAt);
        self::assertLessThanOrEqual($after, $contract->establishedAt);
    }

    public function testSubMicrosecondWindowsRoundUpSoTheContractStaysValid(): void
    {
        $contract = ReplaySafetyContract::forReadOnlyCallable('x', Duration::nanoseconds(1), self::clockAt('2030-01-01T00:00:00+00:00'));

        self::assertSame('00:00:00.000001', $contract->validUntil->format('H:i:s.u'));
    }

    public function testTheDefaultRetryableCodeIsWhatTheExceptionClassifierEmits(): void
    {
        $contract = ReplaySafetyContract::forReadOnlyCallable('x', Duration::seconds(10));

        self::assertSame([ReasonCode::ExceptionRetryable->value], $contract->retryableOutcomeCodes);
    }

    public function testCustomCodesReplaceTheDefaultAndAreValidatedByTheConstructor(): void
    {
        $contract = ReplaySafetyContract::forReadOnlyCallable('x', Duration::seconds(10), null, ['http_503', 'timeout']);
        self::assertSame(['http_503', 'timeout'], $contract->retryableOutcomeCodes);

        $this->expectException(InvalidConfigurationException::class);
        ReplaySafetyContract::forReadOnlyCallable('x', Duration::seconds(10), null, ['bad code with spaces']);
    }

    /** @return iterable<string, array{string, Duration, string}> */
    public static function invalidInputs(): iterable
    {
        yield 'empty label' => ['', Duration::seconds(1), 'operationName'];
        yield 'label too long' => [str_repeat('a', 129), Duration::seconds(1), 'operationName'];
        yield 'label with control character' => ["a\nb", Duration::seconds(1), 'operationName'];
        yield 'label with non ascii' => ['caf' . "\xc3\xa9", Duration::seconds(1), 'operationName'];
        yield 'zero window' => ['x', Duration::zero(), 'validFor must be positive'];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputsAreRejectedWithAMessageNamingTheArgument(string $label, Duration $validFor, string $fragment): void
    {
        try {
            ReplaySafetyContract::forReadOnlyCallable($label, $validFor);
            self::fail('InvalidConfigurationException expected.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString($fragment, $e->getMessage());
        }
    }

    /**
     * Pipeline over the Harness fakes' clock (wall 2026-10-08) with real memory budget, ExceptionClassifier and the
     * builder; the first call fails twice with a retryable exception.
     *
     * @return array{DispatchSpy, \Closure(?Clock): \Enaxon\Reqtide\Completion\ExecutionCompletion<mixed>}
     */
    private static function retryingPipeline(): array
    {
        $clock = new PipelineClock();
        $log = new CallLog();
        $builder = PipelineBuilder::profile('contract', 'v1')
            ->partition('app', 'test', 'subject')
            ->attemptTimeout(Duration::seconds(5), AttemptTimeoutRequirement::Cooperative)
            ->retry(3, BackoffMode::Constant, Duration::milliseconds(10), Duration::milliseconds(10), JitterMode::None)
            ->retryBudget(5, 5, Duration::seconds(60), Duration::seconds(10));
        $definition = $builder->definition();
        $environment = new RuntimeEnvironment(
            $clock,
            new FakeScheduler($clock, $log),
            new ScriptedRandom(false),
            ExceptionClassifier::create()->retryOn(\RuntimeException::class),
            new SynchronousRuntimeAdapter(SchedulingCapability::BoundedBlocking),
            new NullEventSink(),
            null,
            null,
            null,
            new MemoryRetryBudgetBackend($clock),
            null,
        );
        $pipeline = $definition->compile($environment);
        $spy = DispatchSpy::of(
            $log,
            static fn(): never => throw new \RuntimeException('first'),
            static fn(): never => throw new \RuntimeException('second'),
            static fn(): string => 'third',
        );

        return [$spy, static function (?Clock $contractClock) use ($pipeline, $spy): \Enaxon\Reqtide\Completion\ExecutionCompletion {
            $contract = ReplaySafetyContract::forReadOnlyCallable('read', Duration::seconds(3600), $contractClock);

            return $pipeline->executeDetailed($spy->callable(), $pipeline->newContext(Duration::seconds(60), OperationSafety::replayableRead($contract)));
        }];
    }

    public function testAContractFromThePipelineClockMakesARetryableExceptionRetryUntilItSucceeds(): void
    {
        [$spy, $run] = self::retryingPipeline();

        $completion = $run(self::clockAt('2026-10-08T00:00:00+00:00'));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertSame(3, $spy->dispatches);
    }

    public function testTheContractIsWrappedAsAReplayableReadAssertion(): void
    {
        $contract = ReplaySafetyContract::forReadOnlyCallable('x', Duration::seconds(1));

        $safety = OperationSafety::replayableRead($contract);

        self::assertSame(OperationSafetyKind::ReplayableRead, $safety->kind);
        self::assertSame($contract, $safety->contract);
    }

    public function testAContractFromAnotherClockDeniesRetryInsteadOfSilentlyRetrying(): void
    {
        [$spy, $run] = self::retryingPipeline();

        // The pipeline's wall clock reads 2026-10-08; a contract established in 2030 lies in its future.
        $completion = $run(self::clockAt('2030-01-01T00:00:00+00:00'));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertSame(1, $spy->dispatches, 'the first attempt runs, nothing is repeated');
        $reasons = array_map(static fn($d): string => $d->reasonCode, $completion->diagnostics);
        self::assertContains(ReasonCode::ReplayContractNotValid->value, $reasons);
    }

    public function testAWindowShorterThanTheDeadlineDeniesRetryAndSaysSo(): void
    {
        $clock = new PipelineClock();
        $log = new CallLog();
        $pipeline = PipelineBuilder::profile('contract', 'v1')
            ->partition('app', 'test', 'subject')
            ->attemptTimeout(Duration::seconds(5), AttemptTimeoutRequirement::Cooperative)
            ->retry(2, BackoffMode::Constant, Duration::milliseconds(10), Duration::milliseconds(10), JitterMode::None)
            ->retryBudget(5, 5, Duration::seconds(60), Duration::seconds(10))
            ->compile(new RuntimeEnvironment(
                $clock,
                new FakeScheduler($clock, $log),
                new ScriptedRandom(false),
                ExceptionClassifier::create()->retryOn(\RuntimeException::class),
                new SynchronousRuntimeAdapter(SchedulingCapability::BoundedBlocking),
                new NullEventSink(),
                null,
                null,
                null,
                new MemoryRetryBudgetBackend($clock),
                null,
            ));
        $spy = DispatchSpy::throwing(new \RuntimeException('down'), $log);
        $contract = ReplaySafetyContract::forReadOnlyCallable('read', Duration::seconds(10), self::clockAt('2026-10-08T00:00:00+00:00'));

        try {
            $pipeline->newContext(Duration::seconds(60), OperationSafety::replayableRead($contract));
            self::fail('newContext() refuses a contract that cannot cover the deadline.');
        } catch (InvalidConfigurationException $refusal) {
            self::assertStringContainsString('validFor', $refusal->getMessage());
        }

        // A context built by hand still runs once and says why it did not retry.
        $context = new \Enaxon\Reqtide\Execution\ExecutionContext(
            new \Enaxon\Reqtide\Value\OperationId('op-1'),
            new \Enaxon\Reqtide\Value\PartitionIdentity(new \Enaxon\Reqtide\Value\ApplicationId('app'), new \Enaxon\Reqtide\Value\EnvironmentId('test'), null, new \Enaxon\Reqtide\Value\SubjectId('subject')),
            \Enaxon\Reqtide\Time\Deadline::after(Duration::seconds(60), $clock),
            new \Enaxon\Reqtide\Time\NeverCancelled(),
            new \Enaxon\Reqtide\Execution\OperationMetadata(OperationSafety::replayableRead($contract), \Enaxon\Reqtide\Execution\BoundedMetadata::empty()),
            null,
        );
        $completion = $pipeline->executeDetailed($spy->callable(), $context);

        self::assertSame(1, $spy->dispatches);
        $reasons = array_map(static fn($d): string => $d->reasonCode, $completion->diagnostics);
        self::assertContains(ReasonCode::ReplayHorizonInadequate->value, $reasons);
    }
}
