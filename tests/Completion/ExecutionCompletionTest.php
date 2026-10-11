<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Completion;

use Enaxon\Reqtide\Admission\AdmissionStatus;
use Enaxon\Reqtide\Completion\ActualAttemptDisposition;
use Enaxon\Reqtide\Completion\AttemptRecord;
use Enaxon\Reqtide\Completion\CompletionDisposition as D;
use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Completion\ExecutionSummary;
use Enaxon\Reqtide\Completion\SettlementOverride;
use Enaxon\Reqtide\Exception\AdmissionDeferredException;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Exception\ClassificationFailedException;
use Enaxon\Reqtide\Exception\DeadlineExceededException;
use Enaxon\Reqtide\Exception\ImpossibleAdmissionException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\OperationCancelledException;
use Enaxon\Reqtide\Exception\PreparationFailedException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Outcome\Acceptability;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\ProfileVersion;
use PHPUnit\Framework\TestCase;

final class ExecutionCompletionTest extends TestCase
{
    /** @return Outcome<string> */
    private static function value(): Outcome
    {
        return Outcome::fromValue('fb', BoundedMetadata::empty());
    }

    /** @return Outcome<string> */
    private static function failure(): Outcome
    {
        return Outcome::fromException(new \RuntimeException('fb'), BoundedMetadata::empty());
    }

    private static function exception(): \RuntimeException
    {
        return new \RuntimeException('c');
    }

    /** @param callable(): ExecutionCompletion<string> $build */
    private function assertInvalid(callable $build): void
    {
        try {
            $build();
        } catch (InvalidConfigurationException $e) {
            self::assertInstanceOf(InvalidConfigurationException::class, $e);

            return;
        }
        self::fail('Expected the disposition matrix to reject this combination.');
    }

    // ---- valid rows ----

    public function testSucceeded(): void
    {
        $c = Fixtures::completion(D::Succeeded, [Fixtures::record(1, true, CircuitVerdict::Success)]);
        self::assertSame('v', $c->terminalOutcome?->value());
        self::assertNull($c->fallbackOutcome);
    }

    public function testFailedByExceptionTerminalEvenWhenAcceptable(): void
    {
        $c = Fixtures::completion(D::Failed, [Fixtures::record(1, false, CircuitVerdict::Failure, Acceptability::Acceptable)]);
        self::assertSame(D::Failed, $c->disposition);
    }

    public function testFailedByUnacceptableValueKeepsTheValue(): void
    {
        $c = Fixtures::completion(D::Failed, [Fixtures::record(1, true, CircuitVerdict::Failure, Acceptability::Unacceptable)]);
        self::assertSame('v', $c->terminalOutcome?->value());
    }

    public function testFailedByClassifierCauseKeepsActualOutcome(): void
    {
        $record = Fixtures::record(1, true, CircuitVerdict::Neutral);
        $cause = new ClassificationFailedException($record->outcome, self::exception());
        $c = Fixtures::completion(D::Failed, [$record], classifier: $cause);
        self::assertSame($cause, $c->classifierCause);
    }

    public function testFailedByInfrastructureAndPreparationCausesWithoutAttempts(): void
    {
        $infra = Fixtures::completion(D::Failed, policy: new StateUnavailableException('down'));
        self::assertNull($infra->terminalOutcome);
        $prep = Fixtures::completion(D::Failed, policy: new PreparationFailedException(self::exception()));
        self::assertInstanceOf(PreparationFailedException::class, $prep->policyCause);
    }

    public function testRejectedDeferredAndImpossible(): void
    {
        self::assertSame(D::Rejected, Fixtures::completion(D::Rejected, policy: new AdmissionRejectedException(Fixtures::decision(AdmissionStatus::Rejected)))->disposition);
        self::assertSame(D::Rejected, Fixtures::completion(D::Rejected, policy: new ImpossibleAdmissionException(Fixtures::decision(AdmissionStatus::Impossible)))->disposition);
        self::assertSame(D::Deferred, Fixtures::completion(D::Deferred, policy: new AdmissionDeferredException(Fixtures::decision(AdmissionStatus::Deferred)))->disposition);
    }

    public function testRejectedMayRetainEarlierSettledAttempt(): void
    {
        $c = Fixtures::completion(
            D::Rejected,
            [Fixtures::record(1, false, CircuitVerdict::Failure)],
            policy: new AdmissionRejectedException(Fixtures::decision(AdmissionStatus::Rejected)),
        );
        self::assertNotNull($c->terminalOutcome);
    }

    public function testCancelledAndDeadlineMayRetainLateOutcomes(): void
    {
        $records = [Fixtures::record(1, true, CircuitVerdict::Neutral, Acceptability::Acceptable, SettlementOverride::Cancelled)];
        $c = Fixtures::completion(D::Cancelled, $records, self::value(), new OperationCancelledException());
        self::assertNotNull($c->fallbackOutcome);
        $d = Fixtures::completion(D::DeadlineExceeded, $records, policy: new DeadlineExceededException());
        self::assertSame(D::DeadlineExceeded, $d->disposition);
        self::assertNull(Fixtures::completion(D::Cancelled, policy: new OperationCancelledException())->terminalOutcome);
    }

    public function testFallbackSucceededAndFailed(): void
    {
        $records = [Fixtures::record(1, false, CircuitVerdict::Failure)];
        self::assertSame(D::FallbackSucceeded, Fixtures::completion(D::FallbackSucceeded, $records, self::value())->disposition);
        self::assertSame(D::FallbackFailed, Fixtures::completion(D::FallbackFailed, $records, self::failure())->disposition);
    }

    // ---- invalid rows ----

    public function testSucceededRejections(): void
    {
        $ok = [Fixtures::record(1, true, CircuitVerdict::Success)];
        $this->assertInvalid(static fn() => Fixtures::completion(D::Succeeded, [Fixtures::record(1, false, CircuitVerdict::Failure)]));
        $this->assertInvalid(static fn() => Fixtures::completion(D::Succeeded, [Fixtures::record(1, true, CircuitVerdict::Success, Acceptability::Unacceptable)]));
        $this->assertInvalid(static fn() => Fixtures::completion(D::Succeeded));
        $this->assertInvalid(static fn() => Fixtures::completion(D::Succeeded, $ok, self::value()));
        $this->assertInvalid(static fn() => Fixtures::completion(D::Succeeded, $ok, policy: new StateUnavailableException('x')));
        $this->assertInvalid(static fn() => Fixtures::completion(D::Succeeded, $ok, classifier: new ClassificationFailedException($ok[0]->outcome, self::exception())));
    }

    public function testFailedRejections(): void
    {
        $this->assertInvalid(static fn() => Fixtures::completion(D::Failed, [Fixtures::record(1, true, CircuitVerdict::Success)]));
        $this->assertInvalid(static fn() => Fixtures::completion(D::Failed));
        $this->assertInvalid(static fn() => Fixtures::completion(D::Failed, [Fixtures::record(1, false, CircuitVerdict::Failure)], self::value()));
        $this->assertInvalid(static fn() => Fixtures::completion(D::Failed, [Fixtures::record(1, false, CircuitVerdict::Failure)], policy: new DeadlineExceededException()));
    }

    public function testPolicyDispositionsRequireMatchingCauseAndNoClassifier(): void
    {
        $rejected = new AdmissionRejectedException(Fixtures::decision(AdmissionStatus::Rejected));
        $record = Fixtures::record(1, true, CircuitVerdict::Neutral);
        $this->assertInvalid(static fn() => Fixtures::completion(D::Rejected));
        $this->assertInvalid(static fn() => Fixtures::completion(D::Rejected, policy: new AdmissionDeferredException(Fixtures::decision(AdmissionStatus::Deferred))));
        $this->assertInvalid(static fn() => Fixtures::completion(D::Deferred, policy: $rejected));
        $this->assertInvalid(static fn() => Fixtures::completion(D::Rejected, [$record], self::value(), $rejected));
        $this->assertInvalid(static fn() => Fixtures::completion(D::Cancelled, policy: new DeadlineExceededException()));
        $this->assertInvalid(static fn() => Fixtures::completion(D::DeadlineExceeded, policy: new OperationCancelledException()));
        $this->assertInvalid(static fn() => Fixtures::completion(D::Cancelled, [$record], classifier: new ClassificationFailedException($record->outcome, self::exception()), policy: null));
    }

    public function testFallbackDispositionRejections(): void
    {
        $records = [Fixtures::record(1, false, CircuitVerdict::Failure)];
        $this->assertInvalid(static fn() => Fixtures::completion(D::FallbackSucceeded, $records, self::failure()));
        $this->assertInvalid(static fn() => Fixtures::completion(D::FallbackFailed, $records, self::value()));
        $this->assertInvalid(static fn() => Fixtures::completion(D::FallbackSucceeded, $records));
        $this->assertInvalid(static fn() => Fixtures::completion(D::FallbackSucceeded, [], self::value()));
        $this->assertInvalid(static fn() => Fixtures::completion(D::FallbackSucceeded, $records, self::value(), new StateUnavailableException('x')));
    }

    public function testAFallbackMayRetainAnAdmissionRefusalAsItsPolicyCause(): void
    {
        $refusals = [
            new AdmissionRejectedException(Fixtures::decision(AdmissionStatus::Rejected)),
            new AdmissionDeferredException(Fixtures::decision(AdmissionStatus::Deferred)),
            new ImpossibleAdmissionException(Fixtures::decision(AdmissionStatus::Impossible)),
        ];
        $earlier = [Fixtures::record(1, false, CircuitVerdict::Failure)];
        foreach ($refusals as $refusal) {
            // First-attempt refusal: no terminal outcome. Later-attempt refusal: the earlier attempt is retained.
            foreach ([[], $earlier] as $records) {
                $succeeded = Fixtures::completion(D::FallbackSucceeded, $records, self::value(), $refusal);
                self::assertSame($refusal, $succeeded->policyCause);
                self::assertSame($records === [] ? null : $records[0]->outcome, $succeeded->terminalOutcome);
                $failed = Fixtures::completion(D::FallbackFailed, $records, self::failure(), $refusal);
                self::assertSame($refusal, $failed->policyCause);
            }
        }
    }

    public function testAFallbackAdmitsNoOtherPolicyCauseAndStillNoClassifierCause(): void
    {
        $records = [Fixtures::record(1, false, CircuitVerdict::Failure)];
        foreach ([
            new DeadlineExceededException(),
            new OperationCancelledException(),
            new StateUnavailableException('x'),
            new PreparationFailedException(self::exception()),
        ] as $cause) {
            $this->assertInvalid(static fn() => Fixtures::completion(D::FallbackSucceeded, [], self::value(), $cause));
            $this->assertInvalid(static fn() => Fixtures::completion(D::FallbackFailed, $records, self::failure(), $cause));
        }
        $refusal = new AdmissionRejectedException(Fixtures::decision(AdmissionStatus::Rejected));
        $this->assertInvalid(static fn() => Fixtures::completion(D::FallbackSucceeded, [], self::value(), $refusal, new ClassificationFailedException(Fixtures::record(1, false, CircuitVerdict::Failure)->outcome, self::exception())));
        // Without a refusal the original terminal outcome is still mandatory.
        $this->assertInvalid(static fn() => Fixtures::completion(D::FallbackSucceeded, [], self::value()));
        // The refusal is the only fallback cause: the other policy dispositions keep rejecting a fallback outcome.
        $this->assertInvalid(static fn() => Fixtures::completion(D::Rejected, [], self::value(), $refusal));
    }

    public function testPolicyAndClassifierCausesCannotCoexist(): void
    {
        $record = Fixtures::record(1, true, CircuitVerdict::Neutral);
        $this->assertInvalid(static fn() => Fixtures::completion(
            D::Failed,
            [$record],
            policy: new StateUnavailableException('x'),
            classifier: new ClassificationFailedException($record->outcome, self::exception()),
        ));
    }

    public function testSummaryMustAgreeWithCompletion(): void
    {
        $records = [Fixtures::record(1, true, CircuitVerdict::Success)];
        $this->assertInvalid(static fn() => Fixtures::completion(D::Succeeded, $records, summaryDisposition: D::Failed));
        $this->assertInvalid(static fn() => new ExecutionCompletion(
            D::Succeeded,
            $records[0]->outcome,
            null,
            null,
            null,
            $records,
            [],
            Fixtures::summary(D::Succeeded, []),
        ));
    }

    public function testTerminalOutcomeMustBeLastAttemptOutcome(): void
    {
        $records = [Fixtures::record(1, true, CircuitVerdict::Success)];
        $this->assertInvalid(static fn() => new ExecutionCompletion(
            D::Succeeded,
            self::value(),
            null,
            null,
            null,
            $records,
            [],
            Fixtures::summary(D::Succeeded, $records),
        ));
    }

    public function testDiagnosticsAreBoundedAtThirtyTwo(): void
    {
        $records = [Fixtures::record(1, true, CircuitVerdict::Success)];
        $make = static fn(int $n): ExecutionCompletion => new ExecutionCompletion(
            D::Succeeded,
            $records[0]->outcome,
            null,
            null,
            null,
            $records,
            array_fill(0, $n, new Diagnostic('stage', 'reason')),
            Fixtures::summary(D::Succeeded, $records),
        );
        self::assertCount(32, $make(32)->diagnostics);
        $this->assertInvalid(static fn() => $make(33));
    }

    public function testSixtyFourAttemptsAccepted(): void
    {
        $records = [];
        for ($i = 1; $i <= 64; ++$i) {
            $records[] = Fixtures::record($i, false, CircuitVerdict::Failure);
        }
        self::assertCount(64, Fixtures::completion(D::Failed, $records)->attempts);
    }

    public function testDiagnosticRejectsMessagesAndLongCodes(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new Diagnostic('stage', 'connection refused: secret');
    }

    // ---- attempt record / summary ----

    public function testAttemptRecordDispositionFollowsUnoverriddenClassification(): void
    {
        $outcome = Outcome::fromValue('v', BoundedMetadata::empty());
        $this->expectException(InvalidConfigurationException::class);
        new AttemptRecord(
            AttemptId::fromLowerHex(Fixtures::hex(1)),
            1,
            ActualAttemptDisposition::Succeeded,
            $outcome,
            Duration::zero(),
            SettlementOverride::None,
            null,
            CircuitVerdict::Neutral,
        );
    }

    public function testOverrideForcesNeutralAppliedVerdictButKeepsActualDisposition(): void
    {
        $record = Fixtures::record(1, true, CircuitVerdict::Success, Acceptability::Acceptable, SettlementOverride::DeadlineExceeded);
        self::assertSame(ActualAttemptDisposition::Succeeded, $record->disposition);
        self::assertSame(CircuitVerdict::Neutral, $record->circuitVerdict);

        $this->expectException(InvalidConfigurationException::class);
        new AttemptRecord(
            AttemptId::fromLowerHex(Fixtures::hex(1)),
            1,
            ActualAttemptDisposition::Succeeded,
            $record->outcome,
            Duration::zero(),
            SettlementOverride::Cancelled,
            $record->classification,
            CircuitVerdict::Success,
        );
    }

    public function testAttemptNumberBounds(): void
    {
        self::assertSame(64, Fixtures::record(64, true, CircuitVerdict::Success)->dispatchedAttemptNumber);
        $this->expectException(InvalidConfigurationException::class);
        Fixtures::record(65, true, CircuitVerdict::Success);
    }

    /** @param array<string, int> $overrides */
    private function summary(array $overrides = [], bool $complete = true, ?Duration $elapsed = new Duration(1)): ExecutionSummary
    {
        $v = $overrides + [
            'dispatched' => 2, 'admitted' => 0, 'retries' => 1, 'ok' => 1, 'bad' => 1, 'neutral' => 0,
            'values' => 1, 'exceptions' => 1, 'fallbacks' => 0, 'omitted' => 0,
        ];

        return new ExecutionSummary(
            new OperationId('op'),
            new ProfileVersion('v1'),
            D::Failed,
            $v['dispatched'],
            $v['admitted'],
            $v['retries'],
            $v['ok'],
            $v['bad'],
            $v['neutral'],
            $v['values'],
            $v['exceptions'],
            $v['fallbacks'],
            $complete,
            $v['omitted'],
            $elapsed,
        );
    }

    public function testSummaryInvariants(): void
    {
        self::assertSame(2, $this->summary()->dispatched);

        foreach ([['ok' => 2], ['values' => 2], ['retries' => -1], ['omitted' => -1], ['dispatched' => 65, 'ok' => 65, 'values' => 65, 'bad' => 0, 'exceptions' => 0]] as $bad) {
            try {
                $this->summary($bad);
                self::fail('Summary should be rejected: ' . json_encode($bad));
            } catch (InvalidConfigurationException $e) {
                self::assertInstanceOf(InvalidConfigurationException::class, $e);
            }
        }
    }

    public function testMissingElapsedRequiresIncompleteAccounting(): void
    {
        self::assertNull($this->summary(complete: false, elapsed: null)->elapsed);
        $this->expectException(InvalidConfigurationException::class);
        $this->summary(complete: true, elapsed: null);
    }
}
