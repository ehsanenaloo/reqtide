<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Value;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Completion\ExecutionSummary;
use Enaxon\Reqtide\Completion\SettlementOverride;
use Enaxon\Reqtide\Error\Problem;
use Enaxon\Reqtide\Error\ProblemCause;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\ReplayPreparation;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Runtime\AttemptControls;
use Enaxon\Reqtide\Runtime\SynchronousRuntimeAdapter;
use Enaxon\Reqtide\Tests\Pipeline\Support\SpyOperation;
use Enaxon\Reqtide\Tests\Support\FakeClock;
use Enaxon\Reqtide\Time\Cancellation;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Time\NeverCancelled;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\ProfileVersion;
use PHPUnit\Framework\TestCase;

/** Exact limits, messages and degraded-path behaviour of small value types and adapters. */
final class LimitsAndMessagesHardeningTest extends TestCase
{
    // ---- Duration ------------------------------------------------------------------------------------

    public function testMicrosecondsAreAThousandNanoseconds(): void
    {
        self::assertSame(1_000, Duration::microseconds(1)->nanoseconds);
        self::assertSame(7_000, Duration::microseconds(7)->nanoseconds);
    }

    public function testSubtractingAnEqualDurationIsZeroAndALongerOneIsRejected(): void
    {
        self::assertSame(0, Duration::seconds(2)->subtract(Duration::seconds(2))->nanoseconds);
        self::assertSame(1, Duration::nanoseconds(3)->subtract(Duration::nanoseconds(2))->nanoseconds);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Duration subtraction would be negative.');
        Duration::nanoseconds(2)->subtract(Duration::nanoseconds(3));
    }

    public function testMultiplyAcceptsTheLargestProductAndRejectsTheNextOne(): void
    {
        $factor = 7;
        $largest = intdiv(PHP_INT_MAX, $factor);
        self::assertSame($largest * $factor, (new Duration($largest))->multiply($factor)->nanoseconds);
        self::assertSame(0, (new Duration(PHP_INT_MAX))->multiply(0)->nanoseconds);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Duration multiplication overflows.');
        (new Duration($largest + 1))->multiply($factor);
    }

    public function testMinAndMaxPickTheStrictlySmallerAndLargerDuration(): void
    {
        $small = Duration::seconds(1);
        $large = Duration::seconds(2);

        self::assertSame($small, $large->min($small));
        self::assertSame($small, $small->min($large));
        self::assertSame($large, $small->max($large));
        self::assertSame($large, $large->max($small));
        $twin = Duration::seconds(1);
        self::assertSame($small, $small->min($twin), 'a tie keeps the receiver');
        self::assertSame($small, $small->max($twin), 'a tie keeps the receiver');
    }

    // ---- Problem -------------------------------------------------------------------------------------

    public function testTheProblemCauseListIsBoundedAndSaysByHowMuch(): void
    {
        $cause = ProblemCause::of(new \RuntimeException('x'));
        $atLimit = array_fill(0, ProblemCause::MAX_DEPTH, $cause);
        self::assertCount(ProblemCause::MAX_DEPTH, (new Problem(ReasonCode::AttemptsExhausted, causes: $atLimit))->causes);

        try {
            new Problem(ReasonCode::AttemptsExhausted, causes: [...$atLimit, $cause]);
            self::fail('One cause too many must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame('Causes must be a list of at most ' . ProblemCause::MAX_DEPTH . ' entries.', $e->getMessage());
        }
    }

    public function testTheProblemStageDefaultsToTheCodesStageButAnExplicitStageWins(): void
    {
        $code = ReasonCode::AttemptsExhausted;
        self::assertSame($code->stage(), (new Problem($code))->stage);
        self::assertSame(\Enaxon\Reqtide\Error\Stage::Cleanup, (new Problem($code, \Enaxon\Reqtide\Error\Stage::Cleanup))->stage);
    }

    public function testNoReasonCodeEverNeedsItsProblemDetailTruncated(): void
    {
        foreach (ReasonCode::cases() as $code) {
            self::assertLessThanOrEqual(200, strlen((new Problem($code))->detail()), $code->value);
        }
    }

    // ---- ExceptionClassifier -------------------------------------------------------------------------

    public function testClassifierRejectionMessagesNameTheMethodAndTheOffendingClass(): void
    {
        $classifier = ExceptionClassifier::create();

        foreach ([
            [static fn() => $classifier->retryOn(), 'retryOn() needs at least one exception class.'],
            [static fn() => $classifier->countAsCircuitFailure(), 'countAsCircuitFailure() needs at least one exception class.'],
            [static fn() => $classifier->retryOn(''), 'retryOn() received an empty class name.'], // @phpstan-ignore argument.type
            [static fn() => $classifier->fallbackOn('stdClass'), // @phpstan-ignore argument.type
                'fallbackOn() expects names of classes or interfaces that extend \\Throwable; "stdClass" is not one (check the spelling and that the class is autoloadable).'],
        ] as [$call, $message]) {
            try {
                $call();
                self::fail($message);
            } catch (InvalidConfigurationException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testClassNamesMayStartWithABackslashAndAreMergedWithoutDuplicates(): void
    {
        $base = ExceptionClassifier::create()->retryOn(\RuntimeException::class);
        $merged = $base->retryOn('\\LogicException', \RuntimeException::class);

        $metadata = new \Enaxon\Reqtide\Execution\OperationMetadata(\Enaxon\Reqtide\Execution\OperationSafety::unsafe(), \Enaxon\Reqtide\Execution\BoundedMetadata::empty());
        $retryable = static fn(ExceptionClassifier $c, \Throwable $e): bool => $c->classify(\Enaxon\Reqtide\Outcome\Outcome::fromException($e, \Enaxon\Reqtide\Execution\BoundedMetadata::empty()), $metadata)->retry === \Enaxon\Reqtide\Outcome\RetryEligibility::Eligible;

        self::assertTrue($retryable($merged, new \LogicException('x')), 'the leading backslash is ignored');
        self::assertTrue($retryable($merged, new \RuntimeException('x')), 'earlier names are kept');
        self::assertFalse($retryable($base, new \LogicException('x')), 'the original classifier is unchanged');
        self::assertFalse($retryable($merged, new \JsonException('x')), 'unlisted exceptions stay ineligible');
    }

    // ---- ReplaySafetyContract ------------------------------------------------------------------------

    /** @param list<string> $codes */
    private static function contract(string $reference = 'ref', string $value = 'identity', string $scope = 'scope', string $handling = 'handling', array $codes = ['a']): ReplaySafetyContract
    {
        $now = new \DateTimeImmutable('2026-10-08T00:00:00+00:00');

        return new ReplaySafetyContract($reference, $value, $scope, $handling, $now, $now->modify('+1 hour'), Duration::seconds(60), ReplayPreparation::CallableAssertion, $codes);
    }

    public function testContractTextFieldsAcceptTheirMaximumAndRejectOneByteMore(): void
    {
        self::assertSame(256, strlen(self::contract(str_repeat('r', 256))->contractReference));
        self::assertSame(128, strlen(self::contract('ref', str_repeat('i', 128))->identityValue));
        self::assertSame(256, strlen(self::contract('ref', 'identity', str_repeat('s', 256))->identityScope));
        self::assertSame(256, strlen(self::contract('ref', 'identity', 'scope', str_repeat('h', 256))->duplicateHandlingContract));

        foreach ([
            'contractReference' => static fn() => self::contract(str_repeat('r', 257)),
            'identityValue' => static fn() => self::contract('ref', str_repeat('i', 129)),
            'identityScope' => static fn() => self::contract('ref', 'identity', str_repeat('s', 257)),
            'duplicateHandlingContract' => static fn() => self::contract('ref', 'identity', 'scope', str_repeat('h', 257)),
        ] as $field => $build) {
            try {
                $build();
                self::fail($field . ' of 1 byte too many must be rejected.');
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString($field, $e->getMessage());
            }
        }
    }

    public function testRetryableOutcomeCodesMustBeDistinct(): void
    {
        self::assertSame(['a', 'b'], self::contract(codes: ['a', 'b'])->retryableOutcomeCodes);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('retryableOutcomeCodes must be distinct.');
        self::contract(codes: ['a', 'b', 'a']);
    }

    public function testTheReadOnlyCallableContractIsValidForExactlyTheRequestedTimeAndNamesItsLimit(): void
    {
        $clock = new FakeClock(1);
        $contract = ReplaySafetyContract::forReadOnlyCallable('op', Duration::seconds(90), $clock);

        self::assertSame(90, $contract->validUntil->getTimestamp() - $contract->establishedAt->getTimestamp());
        self::assertTrue($contract->validUntil > $contract->establishedAt);

        $named = ReplaySafetyContract::forReadOnlyCallable(str_repeat('n', 128), Duration::nanoseconds(1_500_000_500), $clock);
        self::assertSame(128, strlen($named->identityValue));
        self::assertSame(1_500_001, (int) round(((float) $named->validUntil->format('U.u') - (float) $named->establishedAt->format('U.u')) * 1_000_000), 'rounded up to whole microseconds');

        $this->expectException(InvalidConfigurationException::class);
        ReplaySafetyContract::forReadOnlyCallable(str_repeat('n', 129), Duration::seconds(1), $clock);
    }

    // ---- ExecutionSummary ----------------------------------------------------------------------------

    private static function summary(int $dispatched = 1, int $succeeded = 1, int $values = 1, int $uncertain = 0, bool $complete = true, ?Duration $elapsed = new Duration(1), int $admitted = 0, int $retries = 0, int $fallbacks = 0, int $omitted = 0, int $exceptions = 0, int $failed = 0, int $neutral = 0): ExecutionSummary
    {
        return new ExecutionSummary(
            new OperationId('op'),
            new ProfileVersion('v1'),
            CompletionDisposition::Succeeded,
            $dispatched,
            $admitted,
            $retries,
            $succeeded,
            $failed,
            $neutral,
            $values,
            $exceptions,
            $fallbacks,
            $complete,
            $omitted,
            $elapsed,
            $uncertain,
        );
    }

    public function testEveryCountIsRejectedWhenNegativeAndTheMessageNamesIt(): void
    {
        foreach ([
            'admittedNotDispatched' => static fn() => self::summary(admitted: -1),
            'retries' => static fn() => self::summary(retries: -1),
            'fallbackExecutions' => static fn() => self::summary(fallbacks: -1),
            'omittedDiagnostics' => static fn() => self::summary(omitted: -1),
            'admissionUncertain' => static fn() => self::summary(uncertain: -1, complete: false),
            'successfulAttempts' => static fn() => self::summary(dispatched: 0, succeeded: -1, values: 0),
            'dispatched' => static fn() => self::summary(dispatched: -1, succeeded: 0, values: 0),
        ] as $name => $build) {
            try {
                $build();
                self::fail($name . ' must not be negative.');
            } catch (InvalidConfigurationException $e) {
                self::assertSame($name . ' must not be negative.', $e->getMessage());
            }
        }
    }

    public function testNoCountMayExceedTheDispatchedCountAndTheMessageNamesTheOffender(): void
    {
        foreach ([
            'successfulAttempts' => static fn() => self::summary(dispatched: 1, succeeded: 2, values: 1),
            'failedAttempts' => static fn() => self::summary(dispatched: 1, succeeded: 0, values: 1, failed: 2),
            'neutralAttempts' => static fn() => self::summary(dispatched: 1, succeeded: 0, values: 1, neutral: 2),
            'actualValues' => static fn() => self::summary(dispatched: 1, succeeded: 1, values: 2),
            'actualExceptions' => static fn() => self::summary(dispatched: 1, succeeded: 1, values: 0, exceptions: 2),
        ] as $name => $build) {
            try {
                $build();
                self::fail($name . ' must be rejected.');
            } catch (InvalidConfigurationException $e) {
                self::assertSame($name . ' must not exceed dispatched.', $e->getMessage());
            }
        }
    }

    public function testUncertainAdmissionsAreBoundedAndRequireIncompleteAccounting(): void
    {
        self::assertSame(64, self::summary(uncertain: 64, complete: false)->admissionUncertain);

        try {
            self::summary(uncertain: 65, complete: false);
            self::fail('65 uncertain admissions exceed the bound.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame('At most 64 admissions can be uncertain.', $e->getMessage());
        }
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('An uncertain admission requires incomplete accounting.');
        self::summary(uncertain: 1, complete: true);
    }

    // ---- SynchronousRuntimeAdapter ---------------------------------------------------------------------

    /** @return array{0: SpyOperation, 1: AttemptContext} */
    private static function attemptFixtures(Cancellation $cancellation, FakeClock $clock): array
    {
        $deadline = Deadline::after(Duration::seconds(60), $clock);
        $context = new AttemptContext(new OperationId('op'), AttemptId::fromLowerHex(str_repeat('a', 32)), 1, $deadline, $cancellation, Duration::seconds(30));

        return [new SpyOperation(), $context];
    }

    public function testAnUnsampleableCancellationNeverCountsAsCancelled(): void
    {
        $clock = new FakeClock(10);
        $cancellation = new class implements Cancellation {
            public function isRequested(): bool
            {
                throw new \RuntimeException('cannot sample');
            }
        };
        [$operation, $context] = self::attemptFixtures($cancellation, $clock);
        $controls = new AttemptControls($context->attemptTimeout, $context->deadline, $cancellation, $clock);

        $observation = (new SynchronousRuntimeAdapter())->invoke($operation, $context, $controls)->awaitObservedSettlement($controls);

        self::assertSame(SettlementOverride::None, $observation->logicalOverride);
        self::assertFalse($observation->accountingComplete);
        self::assertContains(ReasonCode::CancellationSampleFailed->value, array_map(static fn(Diagnostic $d): string => $d->reasonCode, $observation->diagnostics));
    }

    public function testAMonotonicReadingOfZeroIsValidAndANegativeOneIsNot(): void
    {
        $clock = new FakeClock(0);
        [$operation, $context] = self::attemptFixtures(new NeverCancelled(), $clock);
        $controls = new AttemptControls($context->attemptTimeout, $context->deadline, new NeverCancelled(), $clock);

        $observation = (new SynchronousRuntimeAdapter())->invoke($operation, $context, $controls)->awaitObservedSettlement($controls);
        self::assertSame(0, $observation->observedMonotonicNanoseconds);
        self::assertSame(0, $observation->elapsed?->nanoseconds);

        // A negative reading before the call means nothing may be invoked.
        $broken = new FakeClock(-1);
        $broken->now = -1;
        [$untouched, $context2] = self::attemptFixtures(new NeverCancelled(), new FakeClock(5));
        $controls2 = new AttemptControls($context2->attemptTimeout, $context2->deadline, new NeverCancelled(), $broken);
        try {
            (new SynchronousRuntimeAdapter())->invoke($untouched, $context2, $controls2);
            self::fail('A negative start reading must be a clock failure.');
        } catch (ClockFailureException $e) {
            self::assertSame(0, $untouched->invoked, 'nothing was invoked');
        }
    }

    public function testANegativeReadingAfterTheCallLeavesTheSettlementUnobserved(): void
    {
        $clock = new FakeClock(5);
        [$operation, $context] = self::attemptFixtures(new NeverCancelled(), $clock);
        $operation->body = static function () use ($clock): string {
            $clock->now = -1;

            return 'done';
        };
        $controls = new AttemptControls($context->attemptTimeout, $context->deadline, new NeverCancelled(), $clock);

        $observation = (new SynchronousRuntimeAdapter())->invoke($operation, $context, $controls)->awaitObservedSettlement($controls);

        self::assertNull($observation->observedMonotonicNanoseconds);
        self::assertNull($observation->elapsed);
        self::assertSame(SettlementOverride::None, $observation->logicalOverride);
        self::assertContains(ReasonCode::ClockUnavailable->value, array_map(static fn(Diagnostic $d): string => $d->reasonCode, $observation->diagnostics));
    }
}
