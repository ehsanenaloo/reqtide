<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Admission\AdmissionDecision;
use Enaxon\Reqtide\Admission\AdmissionStatus;
use Enaxon\Reqtide\Error\ProblemCarrier;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\AdmissionDeferredException;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Exception\ClassificationFailedException;
use Enaxon\Reqtide\Exception\DeadlineExceededException;
use Enaxon\Reqtide\Exception\FallbackFailedException;
use Enaxon\Reqtide\Exception\ImpossibleAdmissionException;
use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\OperationCancelledException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\Tests\Pipeline\Support\ClosureFallback;
use Enaxon\Reqtide\Tests\Pipeline\Support\DispatchSpy;
use Enaxon\Reqtide\Tests\Pipeline\Support\Harness;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\ProfileVersion;
use PHPUnit\Framework\TestCase;

/**
 * A policy exception thrown by CompiledPipeline::execute() names the operation: operation id, profile
 * version, correlation id, and the attempt when the problem is about one. Detailed mode and business exceptions
 * are unchanged.
 */
final class ThrownProblemContextTest extends TestCase
{
    private static function assertNamesOperation(\Throwable $thrown, ReasonCode $reason): ProblemCarrier
    {
        self::assertInstanceOf(ProblemCarrier::class, $thrown);
        $problem = $thrown->problem();
        self::assertSame($reason, $problem->code);
        self::assertNotNull($problem->operationId);
        self::assertSame('op-1', $problem->operationId->value);
        self::assertNotNull($problem->profileVersion);
        self::assertSame('v1', $problem->profileVersion->value);
        self::assertSame('op-1', $problem->correlationId);
        $array = $problem->toArray();
        self::assertSame('op-1', $array['operationId']);
        self::assertSame('v1', $array['profileVersion']);

        return $thrown;
    }

    private static function thrownBy(\Closure $call): \Throwable
    {
        try {
            $call();
        } catch (\Throwable $thrown) {
            return $thrown;
        }
        self::fail('The call was expected to throw.');
    }

    public function testACircuitRefusalCarriesTheOperationContext(): void
    {
        $h = new Harness(true);
        $h->circuit->status = CircuitAdmissionStatus::Open;
        $spy = DispatchSpy::returning('never', $h->log);

        $thrown = self::thrownBy(fn(): mixed => $h->pipeline()->execute($spy->callable(), $h->context(Duration::seconds(5))));

        self::assertInstanceOf(AdmissionRejectedException::class, $thrown);
        $carrier = self::assertNamesOperation($thrown, ReasonCode::CircuitOpen);
        self::assertNull($carrier->problem()->attemptId, 'nothing was dispatched, so no attempt is blamed');
    }

    public function testADeferredAndAnImpossibleRateDecisionCarryTheContext(): void
    {
        $deferred = new Harness(false, false, true);
        $deferred->rate->script = [new AdmissionDecision(AdmissionStatus::Deferred, 'rate.exhausted', null, Duration::seconds(2))];
        $spy = DispatchSpy::returning('never', $deferred->log);
        $thrown = self::thrownBy(fn(): mixed => $deferred->pipeline()->execute($spy->callable(), $deferred->context(Duration::seconds(5))));
        self::assertInstanceOf(AdmissionDeferredException::class, $thrown);
        self::assertNamesOperation($thrown, ReasonCode::RateExhausted);

        $impossible = new Harness(false, false, true);
        $impossible->rate->script = [new AdmissionDecision(AdmissionStatus::Impossible, 'rate.cost_exceeds_capacity', null, null)];
        $thrown = self::thrownBy(fn(): mixed => $impossible->pipeline()->execute($spy->callable(), $impossible->context(Duration::seconds(5))));
        self::assertInstanceOf(ImpossibleAdmissionException::class, $thrown);
        self::assertNamesOperation($thrown, ReasonCode::RateCostExceedsCapacity);
    }

    public function testDeadlineAndCancellationCarryTheContext(): void
    {
        $late = new Harness(true, true, true);
        $context = $late->context(Duration::seconds(5));
        $late->rate->onCall = static function (string $name) use ($late, $context): void {
            if ($name === 'rate.consume') {
                $late->clock->now = $context->deadline->monotonicNanoseconds;
            }
        };
        $spy = DispatchSpy::returning('v', $late->log);
        $thrown = self::thrownBy(fn(): mixed => $late->pipeline()->execute($spy->callable(), $context));
        self::assertInstanceOf(DeadlineExceededException::class, $thrown);
        self::assertNamesOperation($thrown, ReasonCode::DeadlineExceeded);

        $cancelled = new Harness(true, true, true);
        $cancelled->rate->onCall = static function (string $name) use ($cancelled): void {
            if ($name === 'rate.consume') {
                $cancelled->cancel->cancel();
            }
        };
        $thrown = self::thrownBy(fn(): mixed => $cancelled->pipeline()->execute($spy->callable(), $cancelled->context(Duration::seconds(5))));
        self::assertInstanceOf(OperationCancelledException::class, $thrown);
        self::assertNamesOperation($thrown, ReasonCode::OperationCancelled);
    }

    public function testInfrastructureFailuresCarryTheContext(): void
    {
        $unavailable = new Harness(true);
        $unavailable->circuit->acquireFailure = new StateUnavailableException('down');
        $spy = DispatchSpy::returning('never', $unavailable->log);
        $thrown = self::thrownBy(fn(): mixed => $unavailable->pipeline()->execute($spy->callable(), $unavailable->context(Duration::seconds(5))));
        self::assertInstanceOf(StateUnavailableException::class, $thrown);
        self::assertNamesOperation($thrown, ReasonCode::StateUnavailable);

        $foreign = new Harness(true);
        $foreign->circuit->acquireFailure = new \RuntimeException('boom');
        $thrown = self::thrownBy(fn(): mixed => $foreign->pipeline()->execute($spy->callable(), $foreign->context(Duration::seconds(5))));
        self::assertInstanceOf(IndeterminateStateException::class, $thrown);
        self::assertNamesOperation($thrown, ReasonCode::StateIndeterminate);
    }

    public function testAClassifierFailureCarriesTheContextAndBlamesTheLastAttempt(): void
    {
        $h = new Harness();
        $h->classifier->throw = new \RuntimeException('classifier bug');
        $spy = DispatchSpy::returning('v', $h->log);

        $thrown = self::thrownBy(fn(): mixed => $h->pipeline()->execute($spy->callable(), $h->context(Duration::seconds(5))));

        self::assertInstanceOf(ClassificationFailedException::class, $thrown);
        $carrier = self::assertNamesOperation($thrown, ReasonCode::ClassifierFailed);
        self::assertNotNull($carrier->problem()->attemptId, 'the classifier failed about a dispatched attempt');
    }

    public function testAFailedFallbackCarriesTheContext(): void
    {
        $h = new Harness();
        $spy = DispatchSpy::throwing(new \RuntimeException('business'), $h->log);
        $fallback = new ClosureFallback(static fn(): never => throw new \LogicException('fallback broke'));

        $thrown = self::thrownBy(fn(): mixed => $h->pipeline()->execute($spy->callable(), $h->context(Duration::seconds(5), $fallback)));

        self::assertInstanceOf(FallbackFailedException::class, $thrown);
        self::assertNamesOperation($thrown, ReasonCode::FallbackFailed);
    }

    public function testAMissingReplayContractIsAConfigurationErrorThatNamesTheOperation(): void
    {
        $h = new Harness(false, false, false, true);
        $spy = DispatchSpy::returning('never', $h->log);

        $thrown = self::thrownBy(fn(): mixed => $h->pipeline(Harness::retry(3))->execute($spy->callable(), $h->context(Duration::seconds(5))));

        self::assertInstanceOf(InvalidConfigurationException::class, $thrown);
        self::assertNamesOperation($thrown, ReasonCode::InvalidConfiguration);
        self::assertSame(0, $spy->dispatches);
    }

    public function testTheDetailedPathIsUnchangedAndAlreadyCarriedTheContext(): void
    {
        $h = new Harness(true);
        $h->circuit->status = CircuitAdmissionStatus::Open;
        $spy = DispatchSpy::returning('never', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));

        $cause = $completion->policyCause;
        self::assertInstanceOf(ProblemCarrier::class, $cause);
        self::assertNull($cause->problem()->operationId, 'the retained cause is not mutated by detailed mode');
        $problem = $completion->problem();
        self::assertNotNull($problem);
        self::assertNotNull($problem->operationId);
        self::assertSame('op-1', $problem->operationId->value);
    }

    public function testABusinessExceptionIsRethrownUntouched(): void
    {
        $h = new Harness();
        $business = new \RuntimeException('business');
        $spy = DispatchSpy::throwing($business, $h->log);

        $thrown = self::thrownBy(fn(): mixed => $h->pipeline()->execute($spy->callable(), $h->context(Duration::seconds(5))));

        self::assertSame($business, $thrown);
        self::assertNotInstanceOf(ProblemCarrier::class, $thrown);
    }

    public function testAnEarlierPolicyExceptionRaisedByAnInnerPipelineKeepsItsOwnOperation(): void
    {
        $inner = new Harness(true);
        $inner->circuit->status = CircuitAdmissionStatus::Open;
        $outer = new Harness();
        $innerSpy = DispatchSpy::returning('never', $inner->log);
        $outerSpy = DispatchSpy::of($outer->log, function () use ($inner, $innerSpy): mixed {
            return $inner->pipeline()->execute($innerSpy->callable(), $inner->context(Duration::seconds(5)));
        });

        $thrown = self::thrownBy(fn(): mixed => $outer->pipeline()->execute($outerSpy->callable(), $outer->context(Duration::seconds(5))));

        self::assertInstanceOf(AdmissionRejectedException::class, $thrown, 'the outer pipeline treats it as a business exception');
        self::assertSame('op-1', $thrown->problem()->operationId?->value);
    }

    public function testTheFirstAttachedContextWinsAndAnUnattachedExceptionReportsNone(): void
    {
        $exception = new AdmissionRejectedException(Fixtures::decision(AdmissionStatus::Rejected));
        self::assertNull($exception->problem()->operationId);
        self::assertNull($exception->problem()->profileVersion);
        self::assertNull($exception->problem()->correlationId);

        $exception->attachProblemContext(new OperationId('first'), new ProfileVersion('v1'));
        $exception->attachProblemContext(new OperationId('second'), new ProfileVersion('v2'));

        $problem = $exception->problem();
        self::assertSame('first', $problem->operationId?->value);
        self::assertSame('v1', $problem->profileVersion?->value);
        self::assertSame('first', $problem->correlationId);
        self::assertSame(ReasonCode::AdmissionRejected, $problem->code, 'the code itself is unchanged by the context');
    }
}
