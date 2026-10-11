<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Exception\AdmissionDeferredException;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\ReplayPreparation;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Policy\RetryOptions;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\RetryBudgetFinalizationStatus;
use Enaxon\Reqtide\State\RetryReservationStatus;
use Enaxon\Reqtide\Telemetry\AdmissionRejected;
use Enaxon\Reqtide\Telemetry\RetryScheduled;
use Enaxon\Reqtide\Tests\Pipeline\Support\DispatchSpy;
use Enaxon\Reqtide\Tests\Pipeline\Support\Harness;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Retry policy behavior through the pipeline. */
final class RetryTest extends TestCase
{
    private static function ms(int $milliseconds): int
    {
        return $milliseconds * 1_000_000;
    }

    public function testRetryA01AttemptLimitIncludesTheInitialDispatchAndRethrowsTheLastException(): void
    {
        $h = new Harness(false, false, false, true);
        $last = new \RuntimeException('third');
        $spy = DispatchSpy::of(
            $h->log,
            static fn(): never => throw new \RuntimeException('first'),
            static fn(): never => throw new \RuntimeException('second'),
            static fn(): never => throw $last,
        );
        $pipeline = $h->pipeline(Harness::retry(3));

        try {
            $pipeline->execute($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));
            self::fail('The last exception must be rethrown.');
        } catch (\RuntimeException $thrown) {
            self::assertSame($last, $thrown);
        }
        self::assertSame(3, $spy->dispatches);
        self::assertSame([self::ms(100), self::ms(200)], $h->scheduler->delays);
        self::assertCount(2, $h->sink->of(RetryScheduled::class));
        self::assertSame([2, 3], array_map(static fn(RetryScheduled $e): int => $e->nextDispatchedAttemptNumber, $h->sink->of(RetryScheduled::class)));
    }

    public function testRetryA01ExponentialDelaySaturatesAtTheMaximum(): void
    {
        $h = new Harness(false, false, false, true);
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline(Harness::retry(6, 100, 1_000))->executeDetailed($spy->callable(), $h->context(Duration::seconds(600), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertSame(6, $spy->dispatches);
        self::assertSame([self::ms(100), self::ms(200), self::ms(400), self::ms(800), self::ms(1_000)], $h->scheduler->delays);
    }

    public function testRetryA01ConstantBackoffRepeatsTheBaseDelay(): void
    {
        $h = new Harness(false, false, false, true);
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $h->pipeline(Harness::retry(4, 250, 5_000, BackoffMode::Constant))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame([self::ms(250), self::ms(250), self::ms(250)], $h->scheduler->delays);
    }

    public function testRetryA01ArithmeticNeverOverflowsAtTheMaximumDomain(): void
    {
        $h = new Harness(false, false, false, true);
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);
        $half = 1 << 62;
        $options = new RetryOptions(new PositiveInt(4), BackoffMode::Exponential, new Duration($half), new Duration(PHP_INT_MAX), JitterMode::None, Duration::zero());

        $contract = OperationSafety::replayableRead(new ReplaySafetyContract(
            'contract-1',
            'identity-1',
            'scope-1',
            'safe-repeat',
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            new \DateTimeImmutable('2200-01-01T00:00:00+00:00'),
            new Duration(PHP_INT_MAX),
            ReplayPreparation::CallableAssertion,
            ['transient'],
        ));
        $completion = $h->pipeline($options)->executeDetailed($spy->callable(), $h->context(new Duration(5_000_000_000_000_000_000), null, $contract));

        // The first delay (2^62) fits; doubling saturates at I64_MAX, which cannot fit the remaining time.
        self::assertSame([$half], $h->scheduler->delays);
        self::assertSame(2, $spy->dispatches);
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
    }

    public function testRetryA01FullJitterDrawsFromZeroToTheComputedDelay(): void
    {
        $high = new Harness(false, false, false, true, false, true);
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $high->log);
        $high->pipeline(Harness::retry(3, 100, 10_000, BackoffMode::Exponential, JitterMode::Full))
            ->executeDetailed($spy->callable(), $high->context(Duration::seconds(60), null, Harness::replayable()));
        self::assertSame([[0, self::ms(100)], [0, self::ms(200)]], $high->random->ranges);
        self::assertSame([self::ms(100), self::ms(200)], $high->scheduler->delays);

        $low = new Harness(false, false, false, true, false, false);
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $low->log);
        $completion = $low->pipeline(Harness::retry(3, 100, 10_000, BackoffMode::Exponential, JitterMode::Full))
            ->executeDetailed($spy->callable(), $low->context(Duration::seconds(60), null, Harness::replayable()));
        self::assertSame([], $low->scheduler->delays, 'a zero draw means an immediate retry');
        self::assertSame(3, $spy->dispatches);
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
    }

    public function testRetryA01RetryAfterIsALowerBoundPlusJitterAndIsNeverClampedDown(): void
    {
        $h = new Harness(false, false, false, true, true, false);
        $h->classifier->retryAfter = Duration::seconds(2);
        $spy = DispatchSpy::of($h->log, static fn(): never => throw new \RuntimeException('transient'), static fn(): string => 'ok');

        $completion = $h->pipeline(Harness::retry(3, 100, 10_000, BackoffMode::Exponential, JitterMode::Full))
            ->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertSame([Duration::seconds(2)->nanoseconds], $h->scheduler->delays, 'minimum jitter leaves exactly the lower bound');
        self::assertEquals([Duration::seconds(2)], $h->cooldown->extensions, 'the shared cooldown is extended by the lower bound');
    }

    public function testRetryA01RetryAfterPlusMaximumJitterIsCappedOnlyAboveTheLowerBound(): void
    {
        $h = new Harness(false, false, false, true, false, true);
        $h->classifier->retryAfter = Duration::seconds(2);
        $spy = DispatchSpy::of($h->log, static fn(): never => throw new \RuntimeException('transient'), static fn(): string => 'ok');

        $h->pipeline(Harness::retry(3, 100, 2_050, BackoffMode::Exponential, JitterMode::Full))
            ->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame([self::ms(2_050)], $h->scheduler->delays, 'L + 100 ms jitter is capped at the 2.05 s maximum, which is still >= L');
    }

    public function testRetryA01RetryAfterAboveTheConfiguredMaximumDelayIsDeferredNotClamped(): void
    {
        $h = new Harness(false, false, false, true, true);
        $h->classifier->retryAfter = Duration::seconds(5);
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);
        $pipeline = $h->pipeline(Harness::retry(3, 100, 1_000));

        $completion = $pipeline->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Deferred, $completion->disposition);
        self::assertInstanceOf(AdmissionDeferredException::class, $completion->policyCause);
        self::assertEquals(Duration::seconds(5), $completion->policyCause->decision->retryAfter);
        self::assertSame(1, $spy->dispatches);
        self::assertSame([], $h->scheduler->delays);
        self::assertEquals([Duration::seconds(5)], $h->cooldown->extensions, 'the cooldown is extended even though this operation defers');

        $this->expectException(AdmissionDeferredException::class);
        $pipeline->execute($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));
    }

    public function testRetryA01RetryAfterThatCannotFitTheDeadlineIsDeferredWithoutEnqueueingWork(): void
    {
        $h = new Harness(false, false, false, true, true);
        $h->classifier->retryAfter = Duration::seconds(2);
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline(Harness::retry(3, 100, 10_000))->executeDetailed($spy->callable(), $h->context(Duration::seconds(1), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Deferred, $completion->disposition);
        self::assertSame(1, $spy->dispatches);
        self::assertSame([], $h->scheduler->delays);
        self::assertSame(0, $h->budget->reserved);
    }

    public function testRetryA01RetryAfterIsRecordedEvenForAnOutcomeThatIsNotRetryEligible(): void
    {
        $h = new Harness(false, false, false, true, true);
        $h->classifier->retryAfter = Duration::seconds(3);
        $h->classifier->exceptionRetry = \Enaxon\Reqtide\Outcome\RetryEligibility::Ineligible;
        $spy = DispatchSpy::throwing(new \RuntimeException('not retryable'), $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertSame(1, $spy->dispatches);
        self::assertEquals([Duration::seconds(3)], $h->cooldown->extensions);
    }

    public function testRetryA01OutcomeCodeOutsideTheReplayAllowlistIsNotRetried(): void
    {
        $h = new Harness(false, false, false, true);
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable('some-other-code')));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertSame(1, $spy->dispatches);
    }

    public function testRetryA01MinimumRemainingAttemptAllowanceMustFitStrictlyAfterTheDelay(): void
    {
        // delay + max(allowance, 1ns) < remaining, strictly: 100 ms + 500 ms must leave room beyond 600 ms.
        foreach ([[601, 2], [600, 1], [599, 1]] as [$budgetMilliseconds, $dispatches]) {
            $h = new Harness(false, false, false, true);
            $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);
            $h->pipeline(Harness::retry(2, 100, 10_000, BackoffMode::Exponential, JitterMode::None, 500))
                ->executeDetailed($spy->callable(), $h->context(Duration::milliseconds($budgetMilliseconds), null, Harness::replayable()));
            self::assertSame($dispatches, $spy->dispatches, $budgetMilliseconds . ' ms budget');
        }
    }

    public function testRetryA02ExhaustedBudgetStopsTheRetryBeforeAnyOtherAdmission(): void
    {
        $h = new Harness(true, true, true, true);
        $h->budget->onCall = static function (string $name) use ($h): void {
            if ($name === 'budget.reserve') {
                $h->budget->status = RetryReservationStatus::Exhausted;
            }
        };
        $first = new \RuntimeException('transient');
        $spy = DispatchSpy::throwing($first, $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(1, $spy->dispatches, 'no extra dispatch when the budget is exhausted');
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertSame($first, $completion->terminalOutcome?->exception());
        self::assertSame(1, $h->circuit->acquired, 'the declined retry never reaches circuit admission');
        self::assertSame(1, $h->log->count('concurrency.acquire'));
        self::assertSame(1, $h->rate->consumed);
        self::assertSame(0, $h->budget->committed);
        self::assertSame(0, $completion->summary->admittedNotDispatched);
        self::assertCount(1, $h->sink->of(AdmissionRejected::class));
    }

    public function testRetryA02UnavailableReservationStateIsAnInfrastructureTermination(): void
    {
        $h = new Harness(true, true, true, true);
        $h->budget->reserveFailure = new StateUnavailableException('budget down');
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertInstanceOf(StateUnavailableException::class, $completion->policyCause);
        self::assertSame(1, $spy->dispatches);
        self::assertSame(1, $h->circuit->acquired);
    }

    public function testRetryA02ReservationIsReleasedWhenALaterAdmissionRejects(): void
    {
        $h = new Harness(true, true, true, true);
        $h->budget->onCall = static function (string $name) use ($h): void {
            if ($name === 'budget.reserve') {
                $h->circuit->status = CircuitAdmissionStatus::Open;
            }
        };
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Rejected, $completion->disposition);
        self::assertInstanceOf(AdmissionRejectedException::class, $completion->policyCause);
        self::assertSame(1, $spy->dispatches);
        self::assertSame(1, $h->budget->released, 'an uncommitted reservation is released');
        self::assertSame(0, $h->budget->committed);
        self::assertNotNull($completion->terminalOutcome, 'the earlier settled attempt is retained');
    }

    /** @return iterable<string, array{RetryBudgetFinalizationStatus}> */
    public static function nonAuthorizingCommitResults(): iterable
    {
        foreach ([
            RetryBudgetFinalizationStatus::AlreadyCommitted,
            RetryBudgetFinalizationStatus::Released,
            RetryBudgetFinalizationStatus::AlreadyReleased,
            RetryBudgetFinalizationStatus::Expired,
            RetryBudgetFinalizationStatus::StaleOwnership,
        ] as $status) {
            yield $status->value => [$status];
        }
    }

    #[DataProvider('nonAuthorizingCommitResults')]
    public function testRetryA03OnlyTheFirstPendingToCommittedTransitionAuthorizesADispatch(RetryBudgetFinalizationStatus $status): void
    {
        $h = new Harness(true, true, true, true);
        $h->budget->commitStatus = $status;
        $first = new \RuntimeException('transient');
        $spy = DispatchSpy::throwing($first, $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(1, $spy->dispatches, 'a duplicate or non-committed result never authorizes a second send');
        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertSame($first, $completion->terminalOutcome?->exception());
        self::assertSame(0, $h->budget->released, 'a definite commit answer needs no release');
        self::assertSame(2, $h->rate->consumed);
        self::assertSame(1, $completion->summary->admittedNotDispatched);
        self::assertSame(1, $h->circuit->abandoned);
        self::assertSame(2, $h->concurrency->released);
    }

    public function testRetryA03UnavailableCommitPreventsDispatchAndNeverReleasesAGuessedReservation(): void
    {
        $h = new Harness(true, true, true, true);
        $h->budget->commitFailure = new StateUnavailableException('commit failed');
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(1, $spy->dispatches);
        self::assertInstanceOf(StateUnavailableException::class, $completion->policyCause);
        self::assertSame(0, $h->budget->released);
    }

    public function testRetryA03EachRetryReservesAndCommitsExactlyOnce(): void
    {
        $h = new Harness(false, false, false, true);
        $spy = DispatchSpy::of($h->log, static fn(): never => throw new \RuntimeException('a'), static fn(): never => throw new \RuntimeException('b'), static fn(): string => 'c');

        $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(2, $h->budget->reserved);
        self::assertSame(2, $h->budget->committed);
        self::assertSame(0, $h->budget->released);
        self::assertSame(3, $spy->dispatches);
    }
}
