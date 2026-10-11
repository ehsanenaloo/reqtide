<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Error;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Error\Category;
use Enaxon\Reqtide\Error\Problem;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Error\Retryability;
use Enaxon\Reqtide\Error\Severity;
use Enaxon\Reqtide\Error\Stage;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\Tests\Pipeline\Support\ClosureFallback;
use Enaxon\Reqtide\Tests\Pipeline\Support\DispatchSpy;
use Enaxon\Reqtide\Tests\Pipeline\Support\Harness;
use Enaxon\Reqtide\Tests\Pipeline\Support\ThrowingRuntimeAdapter;
use Enaxon\Reqtide\Time\DelayDisposition;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;

/** ExecutionCompletion::problem() across every disposition; no foreign message ever reaches a problem. */
final class ExecutionCompletionProblemTest extends TestCase
{
    private const CANARY = 'CANARY-9c1d https://api.internal.example.com/charge?apikey=SECRET-KEY Bearer zzz.yyy';

    /** @return ExecutionCompletion<mixed> */
    private static function execute(Harness $h, DispatchSpy $spy, ?\Enaxon\Reqtide\Policy\RetryOptions $retry = null, ?ClosureFallback $fallback = null): ExecutionCompletion
    {
        return $h->pipeline($retry)->executeDetailed(
            $spy->callable(),
            $h->context(Duration::seconds(60), $fallback, $retry !== null ? Harness::replayable() : null),
        );
    }

    private static function assertSafe(Problem $problem): void
    {
        $json = json_encode($problem, JSON_THROW_ON_ERROR);
        foreach (['CANARY', 'SECRET', 'internal.example.com', 'Bearer', 'apikey'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testSucceededHasNoProblem(): void
    {
        $h = new Harness();

        $completion = self::execute($h, DispatchSpy::returning('ok'));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertNull($completion->problem());
    }

    public function testRejectedReportsTheAdmissionCodeWithOperationContext(): void
    {
        $h = new Harness(true);
        $h->circuit->status = CircuitAdmissionStatus::Open;

        $completion = self::execute($h, DispatchSpy::returning('ok'));

        self::assertSame(CompletionDisposition::Rejected, $completion->disposition);
        $problem = $completion->problem();
        self::assertNotNull($problem);
        self::assertSame(ReasonCode::CircuitOpen, $problem->code);
        self::assertSame(Category::Admission, $problem->category());
        self::assertSame(Retryability::AfterWait, $problem->retryability());
        self::assertSame('op-1', $problem->operationId?->value);
        self::assertSame('v1', $problem->profileVersion?->value);
        self::assertSame('op-1', $problem->correlationId);
        self::assertNull($problem->attemptId, 'nothing was dispatched');
        self::assertSame(Stage::Admission, $problem->stage);
    }

    public function testDeferredByCooldownReportsCooldownActive(): void
    {
        $h = new Harness(false, false, false, false, true);
        $h->cooldown->active = Duration::seconds(5);

        $completion = self::execute($h, DispatchSpy::returning('ok'));

        self::assertSame(CompletionDisposition::Deferred, $completion->disposition);
        self::assertSame(ReasonCode::CooldownActive, $completion->problem()?->code);
        self::assertSame(Retryability::AfterWait, $completion->problem()->retryability());
    }

    public function testCancelledReportsOperationCancelled(): void
    {
        $h = new Harness();
        $h->cancel->cancel();

        $completion = self::execute($h, DispatchSpy::returning('ok'));

        self::assertSame(CompletionDisposition::Cancelled, $completion->disposition);
        self::assertSame(ReasonCode::OperationCancelled, $completion->problem()?->code);
        self::assertSame(Severity::Notice, $completion->problem()->severity());
    }

    public function testDeadlineExceededReportsDeadlineExceededAndCarriesTheBusinessCause(): void
    {
        $h = new Harness(false, false, false, true);
        $h->scheduler->forced = DelayDisposition::DeadlineExceeded;
        $spy = DispatchSpy::throwing(new \RuntimeException(self::CANARY));

        $completion = self::execute($h, $spy, Harness::retry(3));

        self::assertSame(CompletionDisposition::DeadlineExceeded, $completion->disposition);
        $problem = $completion->problem();
        self::assertNotNull($problem);
        self::assertSame(ReasonCode::DeadlineExceeded, $problem->code);
        self::assertSame(Severity::Warning, $problem->severity());
        self::assertSame('op-1', $problem->operationId?->value);
        self::assertNull($problem->attemptId);
        self::assertSafe($problem);
    }

    public function testInfrastructureFailureReportsTheStateCodeAndNeverTheBackendMessage(): void
    {
        $h = new Harness(true);
        $h->circuit->acquireFailure = new StateUnavailableException(self::CANARY, 0, new \RuntimeException(self::CANARY));

        $completion = self::execute($h, DispatchSpy::returning('ok'));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        $problem = $completion->problem();
        self::assertNotNull($problem);
        self::assertSame(ReasonCode::StateUnavailable, $problem->code);
        self::assertSame(Category::Infrastructure, $problem->category());
        self::assertSame(Retryability::Transient, $problem->retryability());
        self::assertSame([['class' => 'RuntimeException', 'code' => null]], $problem->toArray()['causes']);
        self::assertSafe($problem);
    }

    public function testForeignBackendExceptionBecomesIndeterminateWithoutItsMessage(): void
    {
        $h = new Harness(true);
        $h->circuit->acquireFailure = new \UnexpectedValueException(self::CANARY);

        $completion = self::execute($h, DispatchSpy::returning('ok'));

        $problem = $completion->problem();
        self::assertNotNull($problem);
        self::assertSame(ReasonCode::StateIndeterminate, $problem->code);
        self::assertSame(Retryability::Unknown, $problem->retryability());
        self::assertSame([['class' => 'UnexpectedValueException', 'code' => null]], $problem->toArray()['causes']);
        self::assertSafe($problem);
    }

    public function testRuntimeAdapterFailureAfterInvocationIsScopedToTheDispatchedAttempt(): void
    {
        $h = new Harness();
        $h->runtimeOverride = new ThrowingRuntimeAdapter(new \RuntimeException(self::CANARY));

        $completion = self::execute($h, DispatchSpy::returning('never'));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        $problem = $completion->problem();
        self::assertNotNull($problem);
        self::assertSame(ReasonCode::StateIndeterminate, $problem->code);
        self::assertSame($completion->attempts[0]->attemptId->lowerHex, $problem->attemptId?->lowerHex);
        self::assertSafe($problem);
    }

    public function testClassifierFailureReportsTheClassifierCodeForTheLastAttempt(): void
    {
        $h = new Harness();
        $h->classifier->throw = new \RuntimeException(self::CANARY);

        $completion = self::execute($h, DispatchSpy::returning(self::CANARY));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        $problem = $completion->problem();
        self::assertNotNull($problem);
        self::assertSame(ReasonCode::ClassifierFailed, $problem->code);
        self::assertSame(Category::Classification, $problem->category());
        self::assertSame($completion->attempts[0]->attemptId->lowerHex, $problem->attemptId?->lowerHex);
        self::assertSafe($problem);
    }

    public function testAPurelyBusinessFailureHasNoReqtideProblem(): void
    {
        $h = new Harness();

        $completion = self::execute($h, DispatchSpy::throwing(new \RuntimeException(self::CANARY)));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertNull($completion->policyCause);
        self::assertNull($completion->problem(), 'business failures are never described by Reqtide');
    }

    public function testARetryStopReportsTheStopCodeWithTheBusinessExceptionAsClassNameOnly(): void
    {
        $h = new Harness(false, false, false, true);
        $spy = DispatchSpy::throwing(new \DomainException(self::CANARY));

        $completion = self::execute($h, $spy, Harness::retry(2));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        self::assertSame(2, $completion->summary->dispatched);
        $problem = $completion->problem();
        self::assertNotNull($problem);
        self::assertSame(ReasonCode::AttemptsExhausted, $problem->code);
        self::assertSame(Category::Retry, $problem->category());
        self::assertSame(Stage::Retry, $problem->stage);
        self::assertSame([['class' => 'DomainException', 'code' => null]], $problem->toArray()['causes']);
        self::assertSame('op-1', $problem->operationId?->value);
        self::assertSafe($problem);
    }

    public function testFallbackSucceededHasNoProblemBecauseTheCallerReceivedAValue(): void
    {
        $h = new Harness();

        $completion = self::execute($h, DispatchSpy::throwing(new \RuntimeException(self::CANARY)), null, new ClosureFallback(static fn(): string => 'degraded'));

        self::assertSame(CompletionDisposition::FallbackSucceeded, $completion->disposition);
        self::assertNull($completion->problem());
    }

    public function testFallbackFailedReportsBothCausesByClassName(): void
    {
        $h = new Harness();
        $fallback = new ClosureFallback(static fn(): never => throw new \OverflowException(self::CANARY));

        $completion = self::execute($h, DispatchSpy::throwing(new \DomainException(self::CANARY)), null, $fallback);

        self::assertSame(CompletionDisposition::FallbackFailed, $completion->disposition);
        $problem = $completion->problem();
        self::assertNotNull($problem);
        self::assertSame(ReasonCode::FallbackFailed, $problem->code);
        self::assertSame(Category::Fallback, $problem->category());
        self::assertSame(
            [['class' => 'OverflowException', 'code' => null], ['class' => 'DomainException', 'code' => null]],
            $problem->toArray()['causes'],
        );
        self::assertSafe($problem);
    }

    public function testEveryDispositionIsCoveredByTheMatrix(): void
    {
        $covered = [
            CompletionDisposition::Succeeded, CompletionDisposition::Rejected, CompletionDisposition::Deferred,
            CompletionDisposition::Cancelled, CompletionDisposition::DeadlineExceeded, CompletionDisposition::Failed,
            CompletionDisposition::FallbackSucceeded, CompletionDisposition::FallbackFailed,
        ];

        self::assertEqualsCanonicalizing(CompletionDisposition::cases(), $covered);
    }
}
