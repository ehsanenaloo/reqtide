<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Error;

use Enaxon\Reqtide\Admission\AdmissionDecision;
use Enaxon\Reqtide\Admission\AdmissionStatus;
use Enaxon\Reqtide\Error\ProblemCarrier;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\AdmissionDeferredException;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Exception\CapabilityMismatchException;
use Enaxon\Reqtide\Exception\ClassificationFailedException;
use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\DeadlineExceededException;
use Enaxon\Reqtide\Exception\FallbackFailedException;
use Enaxon\Reqtide\Exception\ImpossibleAdmissionException;
use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\OperationCancelledException;
use Enaxon\Reqtide\Exception\PreparationFailedException;
use Enaxon\Reqtide\Exception\ReqtideException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every concrete ReqtideException is a ProblemCarrier whose Problem is redaction-safe. */
final class ExceptionProblemCarrierTest extends TestCase
{
    private const CANARIES = [
        'CANARY-7f3a',
        'https://user:pass@internal.example.com',
        'SECRET-TOKEN',
        'Authorization: Bearer abc.def.ghi',
        '/var/www/private/config.php',
    ];

    private static function canary(): string
    {
        return implode(' ', self::CANARIES);
    }

    private static function hostile(): \Throwable
    {
        return new \RuntimeException(self::canary(), 7, new \LogicException(self::canary()));
    }

    /** @return array<class-string<ReqtideException>, array{\Closure(): ProblemCarrier, ReasonCode}> */
    private static function factories(): array
    {
        $message = self::canary();

        return [
            AdmissionDeferredException::class => [static fn(): ProblemCarrier => new AdmissionDeferredException(new AdmissionDecision(AdmissionStatus::Deferred, 'cooldown_active', null, Duration::seconds(1)), self::hostile()), ReasonCode::CooldownActive],
            AdmissionRejectedException::class => [static fn(): ProblemCarrier => new AdmissionRejectedException(new AdmissionDecision(AdmissionStatus::Rejected, 'circuit_open', null, null), self::hostile()), ReasonCode::CircuitOpen],
            ImpossibleAdmissionException::class => [static fn(): ProblemCarrier => new ImpossibleAdmissionException(new AdmissionDecision(AdmissionStatus::Impossible, 'rate.cost_exceeds_capacity', null, null), self::hostile()), ReasonCode::RateCostExceedsCapacity],
            CapabilityMismatchException::class => [static fn(): ProblemCarrier => new CapabilityMismatchException($message, 0, self::hostile()), ReasonCode::CapabilityMismatch],
            ClassificationFailedException::class => [static fn(): ProblemCarrier => new ClassificationFailedException(Outcome::fromValue($message, BoundedMetadata::empty()), self::hostile()), ReasonCode::ClassifierFailed],
            ClockFailureException::class => [static fn(): ProblemCarrier => new ClockFailureException($message, 0, self::hostile()), ReasonCode::ClockFailure],
            CorruptStateException::class => [static fn(): ProblemCarrier => new CorruptStateException($message, 0, self::hostile()), ReasonCode::StateCorrupt],
            DeadlineExceededException::class => [static fn(): ProblemCarrier => new DeadlineExceededException($message, 0, self::hostile()), ReasonCode::DeadlineExceeded],
            FallbackFailedException::class => [static fn(): ProblemCarrier => new FallbackFailedException(Outcome::fromException(self::hostile(), BoundedMetadata::empty()), self::hostile()), ReasonCode::FallbackFailed],
            IndeterminateStateException::class => [static fn(): ProblemCarrier => new IndeterminateStateException($message, 0, self::hostile()), ReasonCode::StateIndeterminate],
            InvalidConfigurationException::class => [static fn(): ProblemCarrier => new InvalidConfigurationException($message, 0, self::hostile()), ReasonCode::InvalidConfiguration],
            OperationCancelledException::class => [static fn(): ProblemCarrier => new OperationCancelledException($message, 0, self::hostile()), ReasonCode::OperationCancelled],
            PreparationFailedException::class => [static fn(): ProblemCarrier => new PreparationFailedException(self::hostile()), ReasonCode::PreparationFailed],
            StateCapacityExceededException::class => [static fn(): ProblemCarrier => new StateCapacityExceededException($message, 0, self::hostile()), ReasonCode::StateCapacityExceeded],
            StateUnavailableException::class => [static fn(): ProblemCarrier => new StateUnavailableException($message, 0, self::hostile()), ReasonCode::StateUnavailable],
        ];
    }

    /** @return list<class-string> every class and interface declared in src/Exception */
    private static function declaredExceptionTypes(): array
    {
        $types = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Exception/*.php') ?: [] as $file) {
            $types[] = 'Enaxon\\Reqtide\\Exception\\' . basename($file, '.php');
        }
        sort($types);

        /** @var list<class-string> $types */
        return $types;
    }

    public function testEveryConcreteExceptionClassIsCoveredByAFactoryAndIsAProblemCarrier(): void
    {
        $concrete = [];
        foreach (self::declaredExceptionTypes() as $type) {
            $reflection = new \ReflectionClass($type);
            if ($reflection->isInterface()) {
                continue;
            }
            self::assertTrue($reflection->implementsInterface(ProblemCarrier::class), $type . ' must implement ProblemCarrier');
            self::assertTrue($reflection->implementsInterface(ReqtideException::class), $type);
            if (!$reflection->isAbstract()) {
                $concrete[] = $type;
            }
        }
        $covered = array_keys(self::factories());
        sort($covered);

        self::assertSame($concrete, $covered, 'a new exception class needs a factory in this test');
    }

    /** @return iterable<string, array{class-string<ReqtideException>}> */
    public static function exceptionClasses(): iterable
    {
        foreach (array_keys(self::factories()) as $class) {
            yield substr($class, (int) strrpos($class, '\\') + 1) => [$class];
        }
    }

    /** @param class-string<ReqtideException> $class */
    #[DataProvider('exceptionClasses')]
    public function testProblemIsRedactionSafeAndMatchesTheClassCode(string $class): void
    {
        [$factory, $expected] = self::factories()[$class];
        $exception = $factory();

        self::assertSame($expected, $exception->reason());
        $problem = $exception->problem();
        self::assertSame($expected, $problem->code);
        $json = json_encode($problem, JSON_THROW_ON_ERROR);
        foreach (self::CANARIES as $canary) {
            self::assertStringNotContainsString($canary, $json);
            self::assertStringNotContainsString($canary, (string) json_encode($problem->toArray()));
            self::assertStringNotContainsString($canary, $problem->detail());
            self::assertStringNotContainsString($canary, $problem->title());
        }
        self::assertStringNotContainsString('.php', $json);
        self::assertSame([], array_intersect(['file', 'line', 'trace', 'message', 'previous'], array_keys($problem->toArray())));
    }

    public function testCausesAreTheCausalChainAsClassNamesAndCodes(): void
    {
        $exception = new StateUnavailableException('x', 0, new IndeterminateStateException('y', 0, new \RuntimeException('z')));

        self::assertSame(
            [['class' => 'IndeterminateStateException', 'code' => 'indeterminate'], ['class' => 'RuntimeException', 'code' => null]],
            $exception->problem()->toArray()['causes'],
        );
    }

    public function testFallbackFailureRetainsBothCauses(): void
    {
        $exception = new FallbackFailedException(
            Outcome::fromException(new \DomainException('original'), BoundedMetadata::empty()),
            new \RuntimeException('fallback'),
        );

        self::assertSame(
            [['class' => 'RuntimeException', 'code' => null], ['class' => 'DomainException', 'code' => null]],
            $exception->problem()->toArray()['causes'],
        );
        $valueOriginal = new FallbackFailedException(Outcome::fromValue('v', BoundedMetadata::empty()), new \RuntimeException('f'));
        self::assertCount(1, $valueOriginal->problem()->causes);
    }

    public function testAdmissionExceptionsUseTheRegistryCodeOfTheDecisionOrAGenericOne(): void
    {
        $known = new AdmissionRejectedException(new AdmissionDecision(AdmissionStatus::Rejected, 'rate.exhausted', null, null));
        $foreign = new AdmissionRejectedException(new AdmissionDecision(AdmissionStatus::Rejected, 'vendor_quota_hit', null, null));
        $deferred = new AdmissionDeferredException(new AdmissionDecision(AdmissionStatus::Deferred, 'vendor_wait', null, Duration::seconds(2)));
        $impossible = new ImpossibleAdmissionException(new AdmissionDecision(AdmissionStatus::Impossible, 'vendor_never', null, null));

        self::assertSame(ReasonCode::RateExhausted, $known->reason());
        self::assertSame(ReasonCode::AdmissionRejected, $foreign->reason());
        self::assertSame(ReasonCode::AdmissionDeferred, $deferred->reason());
        self::assertSame(ReasonCode::AdmissionImpossible, $impossible->reason());
        self::assertStringNotContainsString('vendor', json_encode($foreign->problem(), JSON_THROW_ON_ERROR));
    }

    public function testReqtideExceptionStaysAMarkerAndIsNotRequiredToCarryAProblem(): void
    {
        $own = array_filter(
            (new \ReflectionClass(ReqtideException::class))->getMethods(),
            static fn(\ReflectionMethod $m): bool => $m->class === ReqtideException::class,
        );
        self::assertSame([], array_values($own), 'adding methods to the marker would break implementers');
        self::assertTrue((new \ReflectionClass(ProblemCarrier::class))->isSubclassOf(ReqtideException::class));
    }
}
