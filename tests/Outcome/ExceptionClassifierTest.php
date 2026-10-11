<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Outcome;

use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\ReqtideException;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Execution\OperationMetadata;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Outcome\Acceptability;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Outcome\FallbackEligibility;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Outcome\OutcomeClassification;
use Enaxon\Reqtide\Outcome\RetryEligibility;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Marker interface that extends Throwable, used to show that interfaces can be listed. */
interface TransientMarker extends \Throwable {}

final class TransientTimeout extends \RuntimeException implements TransientMarker {}

final class TimeoutChild extends \RuntimeException {}

final class SpecificTimeout extends \LogicException {}

final class RejectedArgument extends \InvalidArgumentException {}

final class ExceptionClassifierTest extends TestCase
{
    private static function metadata(): OperationMetadata
    {
        return new OperationMetadata(OperationSafety::unsafe(), BoundedMetadata::empty());
    }

    private static function ofException(ExceptionClassifier $classifier, \Throwable $exception): OutcomeClassification
    {
        return $classifier->classify(Outcome::fromException($exception, BoundedMetadata::empty()), self::metadata());
    }

    private static function ofValue(ExceptionClassifier $classifier, mixed $value): OutcomeClassification
    {
        return $classifier->classify(Outcome::fromValue($value, BoundedMetadata::empty()), self::metadata());
    }

    public function testADefaultClassifierAcceptsEveryValueAndCountsEveryExceptionAsAnUnretryableFailure(): void
    {
        $classifier = ExceptionClassifier::create();

        $value = self::ofValue($classifier, ['any' => 'value']);
        self::assertSame(RetryEligibility::Ineligible, $value->retry);
        self::assertSame(CircuitVerdict::Success, $value->circuit);
        self::assertSame(FallbackEligibility::Ineligible, $value->fallback);
        self::assertSame(Acceptability::Acceptable, $value->acceptability);
        self::assertSame('value_accepted', $value->reasonCode);
        self::assertNull($value->retryAfterLowerBound);

        $exception = self::ofException($classifier, new \RuntimeException('boom'));
        self::assertSame(RetryEligibility::Ineligible, $exception->retry, 'unknown exceptions are never retried by default');
        self::assertSame(CircuitVerdict::Failure, $exception->circuit, 'unknown exceptions protect the circuit by default');
        self::assertSame(FallbackEligibility::Ineligible, $exception->fallback, 'unknown exceptions are never masked by default');
        self::assertSame(Acceptability::Unacceptable, $exception->acceptability);
        self::assertSame('exception_failure', $exception->reasonCode);
    }

    public function testNullFalseAndEmptyValuesAreStillAcceptedValues(): void
    {
        $classifier = ExceptionClassifier::create();

        foreach ([null, false, 0, '', []] as $value) {
            $classification = self::ofValue($classifier, $value);
            self::assertSame(Acceptability::Acceptable, $classification->acceptability);
            self::assertSame(CircuitVerdict::Success, $classification->circuit);
        }
    }

    public function testRetryOnMakesListedExceptionsAndTheirSubclassesRetryable(): void
    {
        $classifier = ExceptionClassifier::create()->retryOn(\RuntimeException::class);

        foreach ([new \RuntimeException('a'), new TimeoutChild('b'), new \UnexpectedValueException('c')] as $exception) {
            $classification = self::ofException($classifier, $exception);
            self::assertSame(RetryEligibility::Eligible, $classification->retry, $exception::class);
            self::assertSame(CircuitVerdict::Failure, $classification->circuit, $exception::class);
            self::assertSame('exception_retryable', $classification->reasonCode, $exception::class);
        }
        $other = self::ofException($classifier, new \LogicException('not listed'));
        self::assertSame(RetryEligibility::Ineligible, $other->retry);
        self::assertSame('exception_failure', $other->reasonCode);
    }

    public function testInterfacesThatExtendThrowableCanBeListed(): void
    {
        $classifier = ExceptionClassifier::create()->retryOn(TransientMarker::class);

        self::assertSame(RetryEligibility::Eligible, self::ofException($classifier, new TransientTimeout('x'))->retry);
        self::assertSame(RetryEligibility::Ineligible, self::ofException($classifier, new \RuntimeException('x'))->retry);
    }

    public function testALeadingBackslashIsAccepted(): void
    {
        $classifier = ExceptionClassifier::create()->retryOn('\\' . TimeoutChild::class);

        self::assertSame(RetryEligibility::Eligible, self::ofException($classifier, new TimeoutChild('x'))->retry);
    }

    public function testWithoutAnExplicitFailureListEveryNonIgnoredExceptionCountsAgainstTheCircuit(): void
    {
        $classifier = ExceptionClassifier::create()->ignoreForCircuit(RejectedArgument::class);

        self::assertSame(CircuitVerdict::Failure, self::ofException($classifier, new \RuntimeException('x'))->circuit);
        $ignored = self::ofException($classifier, new RejectedArgument('bad input'));
        self::assertSame(CircuitVerdict::Neutral, $ignored->circuit);
        self::assertSame('exception_ignored', $ignored->reasonCode);
        self::assertSame(RetryEligibility::Ineligible, $ignored->retry);
    }

    public function testAnExplicitFailureListTurnsEveryOtherExceptionCircuitNeutralButKeepsRetryableOnesFailing(): void
    {
        $classifier = ExceptionClassifier::create()
            ->countAsCircuitFailure(SpecificTimeout::class)
            ->retryOn(TimeoutChild::class);

        self::assertSame(CircuitVerdict::Failure, self::ofException($classifier, new SpecificTimeout('x'))->circuit);
        self::assertSame(CircuitVerdict::Failure, self::ofException($classifier, new TimeoutChild('x'))->circuit, 'a retryable failure is a dependency failure');
        $other = self::ofException($classifier, new \DomainException('x'));
        self::assertSame(CircuitVerdict::Neutral, $other->circuit, 'neutral neither opens nor heals the circuit');
        self::assertSame('exception_ignored', $other->reasonCode);
    }

    public function testIgnoreWinsOverEveryFailureListButARetryableIgnoredExceptionKeepsItsRetryCode(): void
    {
        $classifier = ExceptionClassifier::create()
            ->countAsCircuitFailure(\RuntimeException::class)
            ->ignoreForCircuit(TimeoutChild::class)
            ->retryOn(TimeoutChild::class);

        $classification = self::ofException($classifier, new TimeoutChild('x'));

        self::assertSame(CircuitVerdict::Neutral, $classification->circuit);
        self::assertSame(RetryEligibility::Eligible, $classification->retry);
        self::assertSame('exception_retryable', $classification->reasonCode);
    }

    public function testFallbackIsEligibleOnlyForListedExceptionsAndThrowableOptsInToAll(): void
    {
        $listed = ExceptionClassifier::create()->fallbackOn(TimeoutChild::class);
        self::assertSame(FallbackEligibility::Eligible, self::ofException($listed, new TimeoutChild('x'))->fallback);
        self::assertSame(FallbackEligibility::Ineligible, self::ofException($listed, new \RuntimeException('x'))->fallback);

        $all = ExceptionClassifier::create()->fallbackOn(\Throwable::class);
        self::assertSame(FallbackEligibility::Eligible, self::ofException($all, new \Error('x'))->fallback);
    }

    public function testRetryAfterIsReadFromExceptionsOnly(): void
    {
        $calls = 0;
        $classifier = ExceptionClassifier::create()
            ->retryOn(TimeoutChild::class)
            ->retryAfterFrom(static function (\Throwable $e) use (&$calls): ?Duration {
                ++$calls;

                return $e instanceof TimeoutChild ? Duration::seconds(7) : null;
            });

        $wait = self::ofException($classifier, new TimeoutChild('x'))->retryAfterLowerBound;
        self::assertNotNull($wait);
        self::assertSame(7_000_000_000, $wait->nanoseconds);
        self::assertNull(self::ofException($classifier, new \RuntimeException('x'))->retryAfterLowerBound);
        self::assertSame(2, $calls);

        self::assertNull(self::ofValue($classifier, 'fine')->retryAfterLowerBound);
        self::assertSame(2, $calls, 'the extractor is not consulted for values');
    }

    public function testAZeroRetryAfterIsAValidLowerBound(): void
    {
        $classifier = ExceptionClassifier::create()->retryAfterFrom(static fn(\Throwable $e): Duration => Duration::zero());

        $wait = self::ofException($classifier, new \RuntimeException('x'))->retryAfterLowerBound;

        self::assertNotNull($wait);
        self::assertTrue($wait->isZero());
    }

    public function testAValuePredicateMarksValuesAsCircuitFailuresWithoutRetryOrFallback(): void
    {
        $classifier = ExceptionClassifier::create()
            ->fallbackOn(\Throwable::class)
            ->unacceptableValuesWhen(static fn(mixed $value): bool => $value === 'error-object');

        $bad = self::ofValue($classifier, 'error-object');
        self::assertSame(Acceptability::Unacceptable, $bad->acceptability);
        self::assertSame(CircuitVerdict::Failure, $bad->circuit);
        self::assertSame(RetryEligibility::Ineligible, $bad->retry);
        self::assertSame(FallbackEligibility::Ineligible, $bad->fallback);
        self::assertSame('value_unacceptable', $bad->reasonCode);

        $good = self::ofValue($classifier, 'ok');
        self::assertSame(Acceptability::Acceptable, $good->acceptability);
        self::assertSame('value_accepted', $good->reasonCode);
    }

    public function testEveryReasonCodeTheClassifierEmitsIsARegistryMember(): void
    {
        $classifier = ExceptionClassifier::create()
            ->retryOn(TimeoutChild::class)
            ->ignoreForCircuit(RejectedArgument::class)
            ->unacceptableValuesWhen(static fn(mixed $value): bool => $value === 1);
        $codes = [
            self::ofValue($classifier, 0)->reasonCode,
            self::ofValue($classifier, 1)->reasonCode,
            self::ofException($classifier, new TimeoutChild('x'))->reasonCode,
            self::ofException($classifier, new \RuntimeException('x'))->reasonCode,
            self::ofException($classifier, new RejectedArgument('x'))->reasonCode,
        ];

        self::assertSame(
            ['value_accepted', 'value_unacceptable', 'exception_retryable', 'exception_failure', 'exception_ignored'],
            $codes,
        );
        foreach ($codes as $code) {
            self::assertNotNull(ReasonCode::tryFrom($code), $code);
        }
    }

    public function testReqtideOwnExceptionsAreClassifiedLikeAnyOtherException(): void
    {
        $refusal = new AdmissionRejectedException(Fixtures::decision(\Enaxon\Reqtide\Admission\AdmissionStatus::Rejected));

        $classification = self::ofException(ExceptionClassifier::create(), $refusal);

        self::assertInstanceOf(ReqtideException::class, $refusal);
        self::assertSame('exception_failure', $classification->reasonCode);
    }

    public function testBuildingIsImmutable(): void
    {
        $base = ExceptionClassifier::create();
        $derived = $base->retryOn(TimeoutChild::class)->fallbackOn(\Throwable::class);

        self::assertSame(RetryEligibility::Ineligible, self::ofException($base, new TimeoutChild('x'))->retry);
        self::assertSame(FallbackEligibility::Ineligible, self::ofException($base, new TimeoutChild('x'))->fallback);
        self::assertSame(RetryEligibility::Eligible, self::ofException($derived, new TimeoutChild('x'))->retry);
    }

    public function testListsAccumulateAcrossCallsWithoutDuplicates(): void
    {
        $classifier = ExceptionClassifier::create()
            ->retryOn(TimeoutChild::class)
            ->retryOn(SpecificTimeout::class, TimeoutChild::class);

        self::assertSame(RetryEligibility::Eligible, self::ofException($classifier, new TimeoutChild('x'))->retry);
        self::assertSame(RetryEligibility::Eligible, self::ofException($classifier, new SpecificTimeout('x'))->retry);
    }

    /** @return iterable<string, array{string, string, \Closure(): ExceptionClassifier}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'unknown class' => ['retryOn()', 'Not\\A\\Real\\Class', static fn(): ExceptionClassifier => ExceptionClassifier::create()->retryOn('Not\\A\\Real\\Class')]; // @phpstan-ignore argument.type
        yield 'not a throwable' => ['countAsCircuitFailure()', \stdClass::class, static fn(): ExceptionClassifier => ExceptionClassifier::create()->countAsCircuitFailure(\stdClass::class)]; // @phpstan-ignore argument.type
        yield 'empty name' => ['ignoreForCircuit()', 'empty class name', static fn(): ExceptionClassifier => ExceptionClassifier::create()->ignoreForCircuit('')]; // @phpstan-ignore argument.type
        yield 'no names' => ['fallbackOn()', 'at least one exception class', static fn(): ExceptionClassifier => ExceptionClassifier::create()->fallbackOn()];
        yield 'one bad among good' => ['retryOn()', 'DateTimeImmutable', static fn(): ExceptionClassifier => ExceptionClassifier::create()->retryOn(\RuntimeException::class, \DateTimeImmutable::class)]; // @phpstan-ignore argument.type
    }

    /** @param \Closure(): ExceptionClassifier $configure */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidClassNamesFailEarlyAndNameTheMethod(string $method, string $fragment, \Closure $configure): void
    {
        try {
            $configure();
            self::fail('InvalidConfigurationException expected.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString($method, $e->getMessage());
            self::assertStringContainsString($fragment, $e->getMessage());
        }
    }
}
