<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Value;

use Enaxon\Reqtide\Admission\AdmissionDecision;
use Enaxon\Reqtide\Admission\AdmissionStatus;
use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Completion\SettlementOverride;
use Enaxon\Reqtide\Error\ProblemCause;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Error\Severity;
use Enaxon\Reqtide\Error\Stage;
use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Exception\FallbackFailedException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\PreparationFailedException;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Internal\Arithmetic\Limbs;
use Enaxon\Reqtide\Internal\Codes;
use Enaxon\Reqtide\Internal\Horizon;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Outcome\RetryEligibility;
use Enaxon\Reqtide\Pipeline\Internal\AttemptResult;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\Policy\RateAlgorithmOptions;
use Enaxon\Reqtide\Policy\TokenBucketOptions;
use Enaxon\Reqtide\Runtime\SettlementObservation;
use Enaxon\Reqtide\State\Memory\MemoryCooldownBackend;
use Enaxon\Reqtide\State\Memory\RateRecord;
use Enaxon\Reqtide\Telemetry\CircuitTransitioned;
use Enaxon\Reqtide\Tests\State\Memory\MemoryTestSupport;
use Enaxon\Reqtide\Tests\Support\FakeClock;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\ProfileVersion;
use Enaxon\Reqtide\Value\Ratio;
use PHPUnit\Framework\TestCase;

/** Exact limits, codes and messages of small immutable types, and the primitive limb operations. */
final class SmallTypesHardeningTest extends TestCase
{
    // ---- Codes / Horizon ------------------------------------------------------------------------------

    public function testBoundedCodesAcceptTheirAlphabetAtTheLimitAndNameTheOffender(): void
    {
        self::assertSame(str_repeat('a', 64), Codes::code(str_repeat('a', 64), 'name'));
        foreach (['', str_repeat('a', 65), 'has space', "new\nline"] as $bad) {
            try {
                Codes::code($bad, 'myField');
                self::fail('Code "' . $bad . '" must be rejected.');
            } catch (InvalidConfigurationException $e) {
                self::assertSame('myField must match [A-Za-z0-9._-]{1,64}.', $e->getMessage());
            }
        }
    }

    public function testPrintableTextIsBoundedInBytesAndNamesTheLimit(): void
    {
        self::assertSame('abc', Codes::printable('abc', 3, 'label'));
        foreach (['', 'abcd', "tab\there"] as $bad) {
            try {
                Codes::printable($bad, 3, 'label');
                self::fail('Text "' . $bad . '" must be rejected.');
            } catch (InvalidConfigurationException $e) {
                self::assertSame('label must be non-empty printable ASCII of at most 3 bytes.', $e->getMessage());
            }
        }
    }

    public function testTheDeadlineHorizonIsInclusiveAndTheMessageNamesTheLimit(): void
    {
        $limit = \Enaxon\Reqtide\Internal\NumericLimits::MAX_DEADLINE_HORIZON_NANOSECONDS;
        Horizon::assert(new Duration($limit), 'lease');

        try {
            Horizon::assert(new Duration($limit + 1), 'lease');
            self::fail('One nanosecond beyond the horizon must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame('lease must not exceed ' . $limit . ' nanoseconds so deadlines cannot overflow.', $e->getMessage());
        }
    }

    // ---- AdmissionDecision ---------------------------------------------------------------------------

    public function testAnAdmissionDecisionThatViolatesItsStatusMatrixNamesTheStatus(): void
    {
        foreach ([
            [AdmissionStatus::Accepted, Duration::seconds(1)],
            [AdmissionStatus::Deferred, null],
            [AdmissionStatus::Deferred, Duration::zero()],
            [AdmissionStatus::Rejected, Duration::zero()],
        ] as [$status, $wait]) {
            try {
                new AdmissionDecision($status, 'reason', null, $wait);
                self::fail($status->value . ' with ' . ($wait === null ? 'no' : $wait->nanoseconds) . ' wait must be rejected.');
            } catch (InvalidConfigurationException $e) {
                self::assertSame('AdmissionDecision fields violate the ' . $status->value . ' matrix.', $e->getMessage());
            }
        }
        self::assertNull((new AdmissionDecision(AdmissionStatus::Rejected, 'reason', null, null))->retryAfter);
        self::assertSame(1, (new AdmissionDecision(AdmissionStatus::Rejected, 'reason', null, Duration::nanoseconds(1)))->retryAfter?->nanoseconds);
    }

    // ---- BoundedMetadata ---------------------------------------------------------------------------------

    public function testMetadataKeysMayBeExactlySixtyFourBytesAndNumericKeysAreStrings(): void
    {
        self::assertCount(1, (new BoundedMetadata([str_repeat('k', 64) => 'v']))->values);
        $numeric = new BoundedMetadata(['1' => 'v']); // @phpstan-ignore argument.type
        self::assertCount(1, $numeric->values, 'a numeric-string key is an int key in PHP but is a valid name');

        foreach ([[str_repeat('k', 65) => 'v'], ['bad' . "\x01" => 'v']] as $bad) {
            try {
                new BoundedMetadata($bad);
                self::fail('The key must be rejected.');
            } catch (InvalidConfigurationException $e) {
                self::assertSame('Metadata keys must be 1..64 printable ASCII bytes.', $e->getMessage());
            }
        }
    }

    public function testMetadataValuesMustBeBoolIntOrString(): void
    {
        self::assertCount(3, (new BoundedMetadata(['a' => true, 'b' => 7, 'c' => 'x']))->values);
        foreach ([1.5, null, ['x']] as $bad) {
            try {
                new BoundedMetadata(['k' => $bad]); // @phpstan-ignore argument.type
                self::fail('The value must be rejected.');
            } catch (InvalidConfigurationException $e) {
                self::assertSame('Metadata values must be bool, int or string.', $e->getMessage());
            }
        }
    }

    // ---- SettlementObservation -----------------------------------------------------------------------------

    /**
     * @param list<Diagnostic> $diagnostics
     * @return SettlementObservation<string>
     */
    private static function observation(?int $observed, ?Duration $elapsed, bool $complete, array $diagnostics): SettlementObservation
    {
        return new SettlementObservation(Outcome::fromValue('v', BoundedMetadata::empty()), SettlementOverride::None, $observed, $elapsed, $complete, $diagnostics);
    }

    public function testObservationDiagnosticsAreBoundedAtThirtyTwo(): void
    {
        $diagnostic = Diagnostic::of(Stage::Settlement, ReasonCode::ClockUnavailable);
        self::assertCount(32, self::observation(1, Duration::zero(), true, array_fill(0, 32, $diagnostic))->diagnostics);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Diagnostics must be a list of at most 32 entries.');
        self::observation(1, Duration::zero(), true, array_fill(0, 33, $diagnostic));
    }

    public function testAnObservedTickMayBeZeroButNotNegative(): void
    {
        self::assertSame(0, self::observation(0, Duration::zero(), true, [])->observedMonotonicNanoseconds);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Observed monotonic time must not be negative.');
        self::observation(-1, Duration::zero(), true, []);
    }

    public function testMissingTimingNeedsADiagnosticAndIncompleteAccountingWhicheverPartIsMissing(): void
    {
        $diagnostic = Diagnostic::of(Stage::Settlement, ReasonCode::ClockUnavailable);
        self::assertNull(self::observation(null, null, false, [$diagnostic])->elapsed);

        foreach ([
            'no tick, complete accounting' => static fn() => self::observation(null, Duration::zero(), true, [$diagnostic]),
            'no elapsed, complete accounting' => static fn() => self::observation(5, null, true, [$diagnostic]),
            'no tick, no diagnostic' => static fn() => self::observation(null, Duration::zero(), false, []),
            'no elapsed, no diagnostic' => static fn() => self::observation(5, null, false, []),
        ] as $name => $build) {
            try {
                $build();
                self::fail($name . ' must be rejected.');
            } catch (InvalidConfigurationException $e) {
                self::assertSame('Unavailable timing requires a diagnostic and incomplete accounting.', $e->getMessage(), $name);
            }
        }
    }

    // ---- Ratio ------------------------------------------------------------------------------------------------

    public function testARatioNeedsAPositiveNumeratorAndDenominator(): void
    {
        self::assertSame(1, Ratio::of(1, 1)->numerator->value);
        foreach ([[0, 1], [1, 0], [0, 0], [-1, 1], [1, -1]] as [$n, $d]) {
            try {
                Ratio::of($n, $d);
                self::fail("$n/$d must be rejected.");
            } catch (InvalidConfigurationException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        $reduced = Ratio::of(4, 6);
        self::assertSame([2, 3], [$reduced->numerator->value, $reduced->denominator->value]);
    }

    // ---- exceptions and telemetry -------------------------------------------------------------------------

    public function testExceptionsKeepTheirCauseAndAZeroCode(): void
    {
        $inner = new \RuntimeException('inner');
        $preparation = new PreparationFailedException($inner);
        self::assertSame(0, $preparation->getCode());
        self::assertSame('Attempt preparation failed', $preparation->getMessage());
        self::assertSame($inner, $preparation->getPrevious());

        $fallback = new FallbackFailedException(Outcome::fromValue('v', BoundedMetadata::empty()), $inner);
        self::assertSame(0, $fallback->getCode());
        self::assertSame('Fallback failed', $fallback->getMessage());
        self::assertSame($inner, $fallback->getPrevious());
    }

    public function testCircuitTransitionEventsValidateEveryCodeAndFallBackToInfoSeverityForCustomReasons(): void
    {
        $operation = new OperationId('op');
        $version = new ProfileVersion('v1');

        foreach ([['bad state', 'open', 'ok'], ['closed', 'bad state', 'ok'], ['closed', 'open', 'bad reason']] as [$from, $to, $reason]) {
            try {
                new CircuitTransitioned($operation, $version, $from, $to, $reason);
                self::fail("$from/$to/$reason must be rejected.");
            } catch (InvalidConfigurationException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        $custom = new CircuitTransitioned($operation, $version, 'closed', 'open', 'backend.custom');
        self::assertNull($custom->reason());
        self::assertSame(Severity::Info, $custom->severity());
    }

    // ---- attempt results ----------------------------------------------------------------------------------------

    public function testEachAttemptResultFactorySetsExactlyItsOwnFlags(): void
    {
        $failure = new ClockFailureException('x');

        $declined = AttemptResult::declined();
        self::assertTrue($declined->declined);
        self::assertNull($declined->termination);
        self::assertNull($declined->settled);

        $terminated = AttemptResult::terminated($failure);
        self::assertFalse($terminated->declined);
        self::assertSame($failure, $terminated->termination);
        self::assertNull($terminated->dispatchedRecord);

        $record = Fixtures::record(1, false, \Enaxon\Reqtide\Outcome\CircuitVerdict::Neutral);
        /** @var \Enaxon\Reqtide\Completion\AttemptRecord<mixed> $record */
        $afterDispatch = AttemptResult::failedAfterDispatch($record, $failure);
        self::assertFalse($afterDispatch->declined);
        self::assertSame($failure, $afterDispatch->termination);
        self::assertSame($record, $afterDispatch->dispatchedRecord);
    }

    // ---- ProblemCause naming ---------------------------------------------------------------------------------------

    public function testAnAnonymousThrowableIsNamedByItsParentClassAndNeverByAPath(): void
    {
        $anonymous = new class ('x') extends \RuntimeException {};
        self::assertSame('RuntimeException', ProblemCause::of($anonymous)->class);

        $plain = new class ('x') extends \Exception {};
        self::assertSame('Exception', ProblemCause::of($plain)->class);
    }

    public function testAnOverlongClassNameIsTruncatedToTheCauseLimit(): void
    {
        $name = 'Very' . str_repeat('Long', 40) . 'Exception';
        eval('namespace Enaxon\Reqtide\Tests\Generated; final class ' . $name . ' extends \Exception {}');
        $class = 'Enaxon\Reqtide\Tests\Generated\\' . $name;
        /** @var \Throwable $exception */
        $exception = new $class('x');

        self::assertSame(substr($name, 0, ProblemCause::MAX_CLASS_LENGTH), ProblemCause::of($exception)->class);
        self::assertSame(ProblemCause::MAX_CLASS_LENGTH, strlen(ProblemCause::of($exception)->class));
    }

    // ---- classifier merging ---------------------------------------------------------------------------------------

    public function testRepeatedClassifierCallsAccumulateEveryNameTheyAreGiven(): void
    {
        $classifier = ExceptionClassifier::create()->retryOn(\RuntimeException::class)->retryOn('\\LogicException', '\\JsonException');
        $metadata = new \Enaxon\Reqtide\Execution\OperationMetadata(\Enaxon\Reqtide\Execution\OperationSafety::unsafe(), BoundedMetadata::empty());
        $eligible = static fn(\Throwable $e): bool => $classifier->classify(Outcome::fromException($e, BoundedMetadata::empty()), $metadata)->retry === RetryEligibility::Eligible;

        self::assertTrue($eligible(new \RuntimeException('x')), 'the first call is kept');
        self::assertTrue($eligible(new \LogicException('x')), 'the second call is added');
        self::assertTrue($eligible(new \JsonException('x')), 'every name of the second call is added, not just the first');
        self::assertFalse($eligible(new \TypeError('x')));
    }

    // ---- primitive limb operations ----------------------------------------------------------------------------------

    public function testLimbMultiplicationByZeroIsEmptyOnEitherSideAndSkipsZeroLimbs(): void
    {
        self::assertSame([], Limbs::multiply([], [5]));
        self::assertSame([], Limbs::multiply([5], []));
        self::assertSame([], Limbs::multiply([], []));
        // 8192 * 8192 = 2^26: a zero low limb must not disturb the carry.
        self::assertSame([0, 0, 1], Limbs::multiply([0, 1], [0, 1]));
        self::assertSame([0, 5], Limbs::multiply([0, 1], [5]));
    }

    public function testLimbDivisionOfASmallerNumeratorReturnsItAsTheRemainderAndListsAreReindexed(): void
    {
        self::assertSame([[], [3]], Limbs::divide([3], [5]));
        self::assertSame([[1], []], Limbs::divide([5], [5]));
        [$quotient, $remainder] = Limbs::divide([0, 0, 1], [2]);
        self::assertTrue(array_is_list($quotient) && array_is_list($remainder));
        self::assertSame([0, 4096], $quotient);
        self::assertSame([], $remainder);
    }

    // ---- RateRecord / cooldown backend ------------------------------------------------------------------------------

    public function testARateRecordStartsWithZeroedCounters(): void
    {
        $options = new TokenBucketOptions(new PositiveInt(1), new PositiveInt(1), Duration::seconds(1));
        $record = new RateRecord($options, 'fp', 77);

        self::assertSame([77, 0, 0, 0], [$record->lastTick, $record->primary, $record->secondary, $record->windowStart]);
        self::assertInstanceOf(RateAlgorithmOptions::class, $record->options);
    }

    public function testTheCooldownBackendDescribesItselfBindsItsGraceAndRespectsBothCeilings(): void
    {
        $clock = new FakeClock(1_000_000_000);
        $ids = MemoryTestSupport::identity('cd');
        $backend = new MemoryCooldownBackend($clock, 10, 10);
        self::assertSame(1, $backend->capabilities()->clockResolution->nanoseconds);

        $policy = new CooldownPolicy($ids, Duration::seconds(5));
        $backend->extend($policy, Duration::seconds(2), CommandId::fromLowerHex(Fixtures::hex(1)));
        $other = new CooldownPolicy($ids, Duration::seconds(6));
        foreach (['inspect' => fn() => $backend->inspect($other), 'extend' => fn() => $backend->extend($other, Duration::seconds(1), CommandId::fromLowerHex(Fixtures::hex(2)))] as $name => $call) {
            try {
                $call();
                self::fail($name . ' must reject a different grace for a bound identity.');
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('grace', strtolower($e->getMessage()));
            }
        }

        // Each ceiling limits allocation on its own.
        foreach ([[1, 100], [100, 1]] as [$records, $partitions]) {
            $small = new MemoryCooldownBackend($clock, $partitions, $records);
            $small->extend(new CooldownPolicy(MemoryTestSupport::identity('one'), Duration::seconds(5)), Duration::seconds(2), CommandId::fromLowerHex(Fixtures::hex(3)));
            try {
                $small->extend(new CooldownPolicy(MemoryTestSupport::identity('two'), Duration::seconds(5)), Duration::seconds(2), CommandId::fromLowerHex(Fixtures::hex(4)));
                self::fail("ceilings $records/$partitions must stop the second allocation.");
            } catch (\Enaxon\Reqtide\Exception\StateCapacityExceededException $e) {
                self::assertStringContainsString('registry is at capacity', $e->getMessage());
            }
        }
    }
}
