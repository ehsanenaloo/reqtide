<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\DeadlineExceededException;
use Enaxon\Reqtide\Exception\OperationCancelledException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Runtime\AttemptOperation;
use Enaxon\Reqtide\Runtime\PreparedAttempt;
use Enaxon\Reqtide\Testing\RecordingAttemptOperation;
use Enaxon\Reqtide\Tests\Pipeline\Support\Harness;
use Enaxon\Reqtide\Tests\Pipeline\Support\SpyOperation;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The deadline, the cancellation and the minimum attempt allowance are read again after every step that
 * can take time before a retry is dispatched (preparation, reservation, commit), and the dispatch context
 * carries the fresh remaining time.
 */
final class FinalDispatchBarrierTest extends TestCase
{
    private const MS = 1_000_000;

    /**
     * @param ExecutionCompletion<mixed> $completion
     * @return list<string>
     */
    private static function reasons(ExecutionCompletion $completion): array
    {
        return array_map(static fn(Diagnostic $d): string => $d->reasonCode, $completion->diagnostics);
    }

    /** @return RecordingAttemptOperation<mixed> */
    private static function failingOnce(): RecordingAttemptOperation
    {
        /** @var RecordingAttemptOperation<mixed> $operation */
        $operation = new RecordingAttemptOperation(static function (AttemptContext $context): string {
            if ($context->dispatchedAttemptNumber === 1) {
                throw new \RuntimeException('transient');
            }

            return 'ok';
        });

        return $operation;
    }

    public function testACommitThatConsumesTheDeadlineStopsTheRetryBeforeDispatch(): void
    {
        $h = new Harness(true, true, true, true);
        $h->budget->onCall = static function (string $name) use ($h): void {
            if ($name === 'budget.commit') {
                $h->clock->advance(61 * 1_000 * self::MS);
            }
        };
        $operation = self::failingOnce();

        $completion = $h->pipeline(Harness::retry(3))->executeOperationDetailed($operation, $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::DeadlineExceeded, $completion->disposition);
        self::assertInstanceOf(DeadlineExceededException::class, $completion->policyCause);
        self::assertSame(1, $operation->invokeCount(), 'the second attempt never started');
        self::assertSame(1, $h->budget->committed, 'a committed token is not refunded');
        self::assertSame(0, $h->budget->released);
        self::assertSame([], $operation->discardedOutcomes(), 'the previous outcome stays the terminal outcome');
        self::assertCount(1, $completion->attempts);
        self::assertSame(1, $completion->summary->admittedNotDispatched);
        self::assertSame(1, $h->circuit->abandoned, 'the held circuit permit was ended');
    }

    public function testACommitThatRequestsCancellationStopsTheRetryBeforeDispatch(): void
    {
        $h = new Harness(true, true, true, true);
        $h->budget->onCall = static function (string $name) use ($h): void {
            if ($name === 'budget.commit') {
                $h->cancel->cancel();
            }
        };
        $operation = self::failingOnce();

        $completion = $h->pipeline(Harness::retry(3))->executeOperationDetailed($operation, $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Cancelled, $completion->disposition);
        self::assertInstanceOf(OperationCancelledException::class, $completion->policyCause);
        self::assertSame(1, $operation->invokeCount());
        self::assertSame(1, $h->budget->committed);
        self::assertSame([], $operation->discardedOutcomes());
        self::assertSame(1, $completion->summary->admittedNotDispatched);
    }

    public function testTheDispatchContextCarriesTheRemainingTimeAfterTheCommit(): void
    {
        $h = new Harness(false, false, false, true);
        $h->budget->onCall = static function (string $name) use ($h): void {
            if ($name === 'budget.commit') {
                $h->clock->advance(10 * 1_000 * self::MS);
            }
        };
        $seen = [];
        /** @var RecordingAttemptOperation<mixed> $operation */
        $operation = new RecordingAttemptOperation(static function (AttemptContext $context) use (&$seen): string {
            $seen[$context->dispatchedAttemptNumber] = $context->attemptTimeout;
            if ($context->dispatchedAttemptNumber === 1) {
                throw new \RuntimeException('transient');
            }

            return 'ok';
        });

        $completion = $h->pipeline(Harness::retry(3, 100, 10_000, BackoffMode::Exponential, JitterMode::None))
            ->executeOperationDetailed($operation, $h->context(Duration::seconds(20), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        // 20 s minus the 100 ms delay minus the 10 s spent in the commit.
        self::assertSame(20_000 * self::MS - 100 * self::MS - 10_000 * self::MS, $seen[2]->nanoseconds);
    }

    public function testThePreviousOutcomeIsDisposedOnlyWhenTheRetryIsDispatched(): void
    {
        $h = new Harness(false, false, false, true);
        $operation = self::failingOnce();

        $completion = $h->pipeline(Harness::retry(3))->executeOperationDetailed($operation, $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertCount(1, $operation->discardedOutcomes());
        self::assertSame(2, $operation->invokeCount());
    }

    public function testARuntimeThatNeverStartedTheRetryLeavesThePreviousOutcomeOpenAndTerminal(): void
    {
        $h = new Harness(false, false, false, true);
        $h->runtimeOverride = new class implements \Enaxon\Reqtide\Runtime\RuntimeAdapter {
            private int $calls = 0;

            private \Enaxon\Reqtide\Runtime\SynchronousRuntimeAdapter $inner;

            public function __construct()
            {
                $this->inner = new \Enaxon\Reqtide\Runtime\SynchronousRuntimeAdapter(\Enaxon\Reqtide\Runtime\SchedulingCapability::BoundedBlocking);
            }

            public function capabilities(): \Enaxon\Reqtide\Runtime\RuntimeCapabilities
            {
                return $this->inner->capabilities();
            }

            public function invoke(\Enaxon\Reqtide\Runtime\PreparedAttempt $operation, AttemptContext $context, \Enaxon\Reqtide\Runtime\AttemptControls $controls): \Enaxon\Reqtide\Runtime\RuntimeExecution
            {
                if (++$this->calls === 2) {
                    throw new \Enaxon\Reqtide\Exception\ClockFailureException('The start time could not be read; nothing was invoked.');
                }

                return $this->inner->invoke($operation, $context, $controls);
            }
        };
        $operation = self::failingOnce();

        $completion = $h->pipeline(Harness::retry(3))->executeOperationDetailed($operation, $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertInstanceOf(\Enaxon\Reqtide\Exception\ClockFailureException::class, $completion->policyCause);
        self::assertSame(1, $operation->invokeCount());
        self::assertSame([], $operation->discardedOutcomes(), 'the retry never started, so the first outcome is still the result');
        self::assertSame($completion->attempts[0]->outcome, $completion->terminalOutcome);
    }

    public function testASlowDisposalDoesNotDelayTheDispatchOfTheRetry(): void
    {
        $h = new Harness(false, false, false, true);
        $operation = new SpyOperation($h->log);
        $calls = 0;
        $operation->body = static function () use (&$calls): string {
            if (++$calls === 1) {
                throw new \RuntimeException('transient');
            }

            return 'ok';
        };
        $operation->onDiscard = static function () use ($h): void {
            $h->clock->advance(120 * 1_000 * self::MS);
        };

        $completion = $h->pipeline(Harness::retry(3))->executeOperationDetailed($operation, $h->context(Duration::seconds(60), null, Harness::replayable()));

        $dispatches = array_keys($h->log->entries, 'dispatch', true);
        self::assertCount(2, $dispatches);
        self::assertGreaterThan($dispatches[1], $h->log->indexOf('discard'), 'the retry was dispatched before the replaced outcome was disposed');
        self::assertSame('ok', $completion->terminalOutcome?->value());
    }

    public function testARetryThatFailsInTheRuntimeAfterDispatchStillDisposesTheOutcomeItReplaced(): void
    {
        $h = new Harness(false, false, false, true);
        $h->runtimeOverride = new class implements \Enaxon\Reqtide\Runtime\RuntimeAdapter {
            private int $calls = 0;

            private \Enaxon\Reqtide\Runtime\SynchronousRuntimeAdapter $inner;

            public function __construct()
            {
                $this->inner = new \Enaxon\Reqtide\Runtime\SynchronousRuntimeAdapter(\Enaxon\Reqtide\Runtime\SchedulingCapability::BoundedBlocking);
            }

            public function capabilities(): \Enaxon\Reqtide\Runtime\RuntimeCapabilities
            {
                return $this->inner->capabilities();
            }

            public function invoke(\Enaxon\Reqtide\Runtime\PreparedAttempt $operation, AttemptContext $context, \Enaxon\Reqtide\Runtime\AttemptControls $controls): \Enaxon\Reqtide\Runtime\RuntimeExecution
            {
                if (++$this->calls === 2) {
                    throw new \RuntimeException('the runtime failed after the attempt began');
                }

                return $this->inner->invoke($operation, $context, $controls);
            }
        };
        $operation = self::failingOnce();

        $completion = $h->pipeline(Harness::retry(3))->executeOperationDetailed($operation, $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertCount(2, $completion->attempts, 'the second attempt counts as dispatched');
        self::assertCount(1, $operation->discardedOutcomes(), 'the first outcome was replaced by a dispatched retry');
        self::assertSame($completion->attempts[0]->outcome, $operation->discardedOutcomes()[0]);
    }

    public function testTheAllowanceDoesNotApplyToTheFirstAttempt(): void
    {
        $h = new Harness(false, false, false, true);
        $operation = new RecordingAttemptOperation(static fn(): string => 'ok');

        // The allowance (10 s) is longer than the whole budget (5 s): only retries need it.
        $completion = $h->pipeline(Harness::retry(2, 10, 10_000, BackoffMode::Exponential, JitterMode::None, 10_000))
            ->executeOperationDetailed($operation, $h->context(Duration::seconds(5), null, Harness::replayable()));

        self::assertSame(1, $operation->invokeCount());
        self::assertSame('ok', $completion->terminalOutcome?->value());
        self::assertSame(0, $h->budget->committed);
    }

    /** @return iterable<string, array{string}> */
    public static function delayedStep(): iterable
    {
        yield 'reservation' => ['budget.reserve'];
        yield 'commit' => ['budget.commit'];
    }

    #[DataProvider('delayedStep')]
    public function testAnAllowanceConsumedByABackendStepDeclinesTheRetry(string $step): void
    {
        $h = new Harness(true, true, true, true);
        $h->budget->onCall = static function (string $name) use ($h, $step): void {
            if ($name === $step) {
                $h->clock->advance(200 * self::MS);
            }
        };
        $operation = self::failingOnce();

        // 400 ms budget, 10 ms delay, 100 ms allowance: 390 ms remain at preparation, 190 ms after the 200 ms step.
        // The allowance is 250 ms here, so 190 ms is positive but below it.
        $completion = $h->pipeline(Harness::retry(2, 10, 10_000, BackoffMode::Exponential, JitterMode::None, 250))
            ->executeOperationDetailed($operation, $h->context(Duration::milliseconds(400), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertNull($completion->policyCause, 'the retry was declined: the business result is kept');
        self::assertSame(1, $operation->invokeCount());
        self::assertSame([], $operation->discardedOutcomes());
        self::assertContains(ReasonCode::AttemptAllowanceUnavailable->value, self::reasons($completion));
        self::assertSame('transient', $completion->terminalOutcome?->exception()?->getMessage());
        self::assertSame(1, $completion->summary->admittedNotDispatched);
        self::assertSame($step === 'budget.commit' ? 1 : 0, $h->budget->committed);
    }

    public function testAnAllowanceConsumedByPreparationDeclinesTheRetryBeforeAnyReservation(): void
    {
        $h = new Harness(true, true, true, true);
        $invoked = 0;
        $operation = new class ($h, $invoked) implements AttemptOperation {
            public function __construct(private readonly Harness $h, private int &$invoked) {}

            public function prepare(AttemptContext $context): PreparedAttempt
            {
                if ($context->dispatchedAttemptNumber > 1) {
                    $this->h->clock->advance(200 * 1_000_000);
                }

                return new class ($this->invoked) implements PreparedAttempt {
                    public function __construct(private int &$invoked) {}

                    public function verifyReplay(AttemptContext $context): void {}

                    public function invoke(AttemptContext $context): mixed
                    {
                        ++$this->invoked;
                        throw new \RuntimeException('transient');
                    }

                    public function discardOutcome(\Enaxon\Reqtide\Outcome\Outcome $outcome): void {}
                };
            }
        };

        $completion = $h->pipeline(Harness::retry(2, 10, 10_000, BackoffMode::Exponential, JitterMode::None, 250))
            ->executeOperationDetailed($operation, $h->context(Duration::milliseconds(400), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertNull($completion->policyCause);
        self::assertSame(1, $invoked);
        self::assertSame(0, $h->log->count('budget.reserve'), 'no token was reserved for a retry that cannot run');
        self::assertContains(ReasonCode::AttemptAllowanceUnavailable->value, self::reasons($completion));
    }
}
