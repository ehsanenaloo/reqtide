<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Completion;

use Enaxon\Reqtide\Admission\AdmissionStatus;
use Enaxon\Reqtide\Completion\CompletionDisposition as D;
use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\AdmissionDeferredException;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Exception\ClassificationFailedException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Fallback\FallbackScope;
use Enaxon\Reqtide\Outcome\Acceptability;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\Tests\Pipeline\Support\ClosureFallback;
use Enaxon\Reqtide\Tests\Pipeline\Support\DispatchSpy;
use Enaxon\Reqtide\Tests\Pipeline\Support\Harness;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;

/** The disposition matrix with its exact messages, and the causes a problem() assembles. */
final class ExecutionCompletionInvariantsTest extends TestCase
{
    private const PREFIX = 'Invalid ExecutionCompletion: ';

    /** @param \Closure(): mixed $build */
    private function assertInvalidBecause(string $reason, \Closure $build): void
    {
        try {
            $build();
        } catch (InvalidConfigurationException $e) {
            self::assertSame(self::PREFIX . $reason, $e->getMessage());

            return;
        }
        self::fail('Expected: ' . $reason);
    }

    /** @return Outcome<string> */
    private static function value(): Outcome
    {
        return Outcome::fromValue('fb', BoundedMetadata::empty());
    }

    private static function refusal(): AdmissionRejectedException
    {
        return new AdmissionRejectedException(Fixtures::decision(AdmissionStatus::Rejected));
    }

    public function testPolicyAndClassifierCausesCannotCoexist(): void
    {
        $record = Fixtures::record(1, true, CircuitVerdict::Neutral);
        $this->assertInvalidBecause('Policy and classifier causes cannot coexist.', static fn() => Fixtures::completion(
            D::Failed,
            [$record],
            policy: new StateUnavailableException('x'),
            classifier: new ClassificationFailedException($record->outcome, new \RuntimeException('c')),
        ));
    }

    public function testAttemptsMustBeAListOfAtMostSixtyFourRecords(): void
    {
        $records = [];
        for ($i = 1; $i <= 65; ++$i) {
            $records[] = Fixtures::record(min($i, 64), false, CircuitVerdict::Failure);
        }
        $this->assertInvalidBecause('Attempts must be a list of at most 64 records.', static fn() => new ExecutionCompletion(D::Failed, $records[64]->outcome, null, null, null, $records, [], Fixtures::summary(D::Failed, array_slice($records, 0, 64))));

        $this->assertInvalidBecause('Attempts must be a list of at most 64 records.', static fn() => new ExecutionCompletion(
            D::Failed,
            $records[0]->outcome,
            null,
            null,
            null,
            [1 => $records[0]], // @phpstan-ignore argument.type
            [],
            Fixtures::summary(D::Failed, [$records[0]]),
        ));
    }

    public function testDiagnosticsMustBeAListOfAtMostThirtyTwoEntries(): void
    {
        $records = [Fixtures::record(1, true, CircuitVerdict::Success)];
        $this->assertInvalidBecause('Diagnostics must be a list of at most 32 entries.', static fn() => new ExecutionCompletion(
            D::Succeeded,
            $records[0]->outcome,
            null,
            null,
            null,
            $records,
            [5 => new Diagnostic('stage', 'reason')], // @phpstan-ignore argument.type
            Fixtures::summary(D::Succeeded, $records),
        ));
    }

    public function testSummaryMustAgreeOnDispositionDispatchCountAndEveryCounter(): void
    {
        $records = [Fixtures::record(1, true, CircuitVerdict::Success)];
        $this->assertInvalidBecause('Summary disposition must equal completion disposition.', static fn() => Fixtures::completion(D::Succeeded, $records, summaryDisposition: D::Failed));
        $this->assertInvalidBecause('There must be exactly one attempt record per dispatch.', static fn() => new ExecutionCompletion(
            D::Succeeded,
            $records[0]->outcome,
            null,
            null,
            null,
            $records,
            [],
            Fixtures::summary(D::Succeeded, []),
        ));
        // Same dispatch count, but the summary claims a failure where the record says success, and an exception for a value.
        $this->assertInvalidBecause('Summary counters must match the attempt records.', static fn() => new ExecutionCompletion(
            D::Succeeded,
            $records[0]->outcome,
            null,
            null,
            null,
            $records,
            [],
            Fixtures::summary(D::Succeeded, [Fixtures::record(1, false, CircuitVerdict::Failure)]),
        ));
    }

    public function testAFallbackOutcomeNeedsACountedFallbackExecution(): void
    {
        $records = [Fixtures::record(1, false, CircuitVerdict::Failure)];
        $this->assertInvalidBecause('A settled fallback outcome requires a counted fallback execution.', static fn() => new ExecutionCompletion(
            D::FallbackSucceeded,
            $records[0]->outcome,
            self::value(),
            null,
            null,
            $records,
            [],
            Fixtures::summary(D::FallbackSucceeded, $records, 0),
        ));
    }

    public function testAClassifierCauseKeepsTheTerminalOutcomeAndAnAttemptImpliesATerminalOutcome(): void
    {
        $record = Fixtures::record(1, true, CircuitVerdict::Neutral);
        $cause = new ClassificationFailedException($record->outcome, new \RuntimeException('c'));
        $this->assertInvalidBecause('A classifier cause preserves the actual terminal outcome.', static fn() => new ExecutionCompletion(
            D::Failed,
            null,
            null,
            null,
            $cause,
            [],
            [],
            Fixtures::summary(D::Failed, []),
        ));
        $this->assertInvalidBecause('A recorded attempt implies a settled terminal outcome.', static fn() => new ExecutionCompletion(
            D::Failed,
            null,
            null,
            new StateUnavailableException('x'),
            null,
            [$record],
            [],
            Fixtures::summary(D::Failed, [$record]),
        ));
        $this->assertInvalidBecause('The terminal outcome must be the outcome of the last attempt record.', static fn() => new ExecutionCompletion(
            D::Failed,
            self::value(),
            null,
            new StateUnavailableException('x'),
            null,
            [],
            [],
            Fixtures::summary(D::Failed, []),
        ));
    }

    public function testEachDispositionRejectsWhatItMayNotCarryWithAnExactReason(): void
    {
        $success = Fixtures::record(1, true, CircuitVerdict::Success);
        $unacceptableValue = Fixtures::record(1, true, CircuitVerdict::Success, Acceptability::Unacceptable);

        $this->assertInvalidBecause('Succeeded requires the terminal attempt to be classified Acceptable.', static fn() => Fixtures::completion(D::Succeeded, [$unacceptableValue]));
        $this->assertInvalidBecause('Succeeded has no fallback, policy or classifier cause.', static fn() => Fixtures::completion(D::Succeeded, [$success], policy: new StateUnavailableException('x')));
        $this->assertInvalidBecause('Failed has no fallback outcome.', static fn() => Fixtures::completion(D::Failed, [Fixtures::record(1, false, CircuitVerdict::Failure)], self::value()));
        $this->assertInvalidBecause('Failed admits only an infrastructure or preparation policy cause.', static fn() => Fixtures::completion(D::Failed, [], policy: self::refusal()));
        $this->assertInvalidBecause('Failed requires an Exception or Unacceptable terminal outcome, or a classifier or infrastructure cause.', static fn() => Fixtures::completion(D::Failed, [$success]));
        $this->assertInvalidBecause('This disposition has no classifier cause.', static fn() => Fixtures::completion(
            D::Rejected,
            [$success],
            classifier: new ClassificationFailedException($success->outcome, new \RuntimeException('c')),
        ));
        $this->assertInvalidBecause('This disposition has no fallback outcome.', static fn() => Fixtures::completion(D::Rejected, [], self::value(), self::refusal()));
        $this->assertInvalidBecause('This disposition has no fallback outcome.', static fn() => Fixtures::completion(
            D::Deferred,
            [],
            self::value(),
            new AdmissionDeferredException(Fixtures::decision(AdmissionStatus::Deferred)),
        ));
        $this->assertInvalidBecause('Policy cause does not match the disposition.', static fn() => Fixtures::completion(D::Deferred, [], policy: self::refusal()));
    }

    public function testAFallbackDispositionNeedsItsOriginalOutcomeKindAndOnlyAnAdmissionRefusalAsCause(): void
    {
        $failed = Fixtures::record(1, false, CircuitVerdict::Failure);
        $this->assertInvalidBecause('A fallback disposition requires an original terminal outcome.', static fn() => new ExecutionCompletion(
            D::FallbackSucceeded,
            null,
            self::value(),
            null,
            null,
            [],
            [],
            Fixtures::summary(D::FallbackSucceeded, [], 1),
        ));
        $this->assertInvalidBecause('Fallback outcome kind does not match the disposition.', static fn() => Fixtures::completion(D::FallbackFailed, [$failed], self::value()));
        $this->assertInvalidBecause('A fallback disposition admits only an admission refusal as its policy cause.', static fn() => Fixtures::completion(D::FallbackSucceeded, [$failed], self::value(), new StateUnavailableException('x')));
        $this->assertInvalidBecause('A fallback disposition has no classifier cause.', static fn() => Fixtures::completion(
            D::FallbackSucceeded,
            [$failed],
            self::value(),
            null,
            new ClassificationFailedException($failed->outcome, new \RuntimeException('c')),
        ));
    }

    // ---- problem() ---------------------------------------------------------------------------------------

    /**
     * @param ExecutionCompletion<mixed> $completion
     * @return list<string>
     */
    private static function causeClasses(ExecutionCompletion $completion): array
    {
        $problem = $completion->problem();
        self::assertNotNull($problem);

        return array_map(static fn(array $cause): string => $cause['class'], $problem->toArray()['causes']);
    }

    public function testAFallbackThatReplacedARefusalListsFallbackRefusalThenTheEarlierAttempt(): void
    {
        $h = new Harness(true, false, false, true);
        $h->fallbackScope = FallbackScope::IncludeAdmissionRefusals;
        $fallback = new ClosureFallback(static fn(): never => throw new \OverflowException('fallback'));
        $spy = DispatchSpy::of($h->log, static function () use ($h): never {
            $h->circuit->status = CircuitAdmissionStatus::Open; // the retry will be refused
            throw new \RuntimeException('attempt');
        });

        $completion = $h->pipeline(Harness::retry(2))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), $fallback, Harness::replayable()));

        self::assertSame(D::FallbackFailed, $completion->disposition);
        self::assertSame(['OverflowException', 'AdmissionRejectedException', 'RuntimeException'], self::causeClasses($completion));
    }

    public function testAFallbackThatReplacedAFirstAttemptRefusalListsOnlyFallbackAndRefusal(): void
    {
        $h = new Harness(true);
        $h->fallbackScope = FallbackScope::IncludeAdmissionRefusals;
        $h->circuit->status = CircuitAdmissionStatus::Open;
        $fallback = new ClosureFallback(static fn(): never => throw new \OverflowException('fallback'));

        $completion = $h->pipeline()->executeDetailed(DispatchSpy::returning('ok')->callable(), $h->context(Duration::seconds(60), $fallback));

        self::assertSame(D::FallbackFailed, $completion->disposition);
        self::assertSame(['OverflowException', 'AdmissionRejectedException'], self::causeClasses($completion));
    }

    public function testARetryStopProblemCarriesTheTerminalExceptionOnlyWhenThereIsOne(): void
    {
        $h = new Harness(false, false, false, true);
        $completion = $h->pipeline(Harness::retry(2))->executeDetailed(DispatchSpy::throwing(new \RuntimeException('x'))->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));
        self::assertSame(ReasonCode::AttemptsExhausted, $completion->problem()?->code);
        self::assertSame(['RuntimeException'], self::causeClasses($completion));

        // An unacceptable VALUE that exhausted its attempts has no exception to report.
        $valueHarness = new Harness(false, false, false, true);
        $valueHarness->classifier->custom = static fn(Outcome $o) => new \Enaxon\Reqtide\Outcome\OutcomeClassification(
            \Enaxon\Reqtide\Outcome\RetryEligibility::Eligible,
            CircuitVerdict::Failure,
            \Enaxon\Reqtide\Outcome\FallbackEligibility::Ineligible,
            Acceptability::Unacceptable,
            'transient',
            null,
        );
        $completion = $valueHarness->pipeline(Harness::retry(2))->executeDetailed(DispatchSpy::returning('bad')->callable(), $valueHarness->context(Duration::seconds(60), null, Harness::replayable()));
        self::assertSame(D::Failed, $completion->disposition);
        self::assertSame(ReasonCode::AttemptsExhausted, $completion->problem()?->code);
        self::assertSame([], self::causeClasses($completion));
    }

    public function testADiagnosticWithAnUnregisteredReasonIsNeverTurnedIntoAProblem(): void
    {
        $records = [Fixtures::record(1, false, CircuitVerdict::Failure)];
        $completion = new ExecutionCompletion(
            D::Failed,
            $records[0]->outcome,
            null,
            null,
            null,
            $records,
            [new Diagnostic('retry', 'not_in_the_registry')],
            Fixtures::summary(D::Failed, $records),
        );

        self::assertNull($completion->problem());
    }
}
