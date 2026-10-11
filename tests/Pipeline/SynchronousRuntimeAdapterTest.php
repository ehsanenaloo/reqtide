<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Completion\SettlementOverride;
use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Outcome\OutcomeKind;
use Enaxon\Reqtide\Runtime\AttemptControls;
use Enaxon\Reqtide\Runtime\AttemptTimeoutCapability;
use Enaxon\Reqtide\Runtime\CallableAttemptOperation;
use Enaxon\Reqtide\Runtime\CancellationCapability;
use Enaxon\Reqtide\Runtime\InvocationForm;
use Enaxon\Reqtide\Runtime\QueueOwnership;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Runtime\SettlementCapability;
use Enaxon\Reqtide\Runtime\SynchronousRuntimeAdapter;
use Enaxon\Reqtide\Tests\Pipeline\Support\PipelineClock;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Time\CancellationToken;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;
use PHPUnit\Framework\TestCase;

final class SynchronousRuntimeAdapterTest extends TestCase
{
    private ?PipelineClock $clockInstance = null;

    private ?CancellationToken $cancelInstance = null;

    private function clock(): PipelineClock
    {
        return $this->clockInstance ??= new PipelineClock(1_000);
    }

    private function cancel(): CancellationToken
    {
        return $this->cancelInstance ??= new CancellationToken();
    }

    private function context(Deadline $deadline): AttemptContext
    {
        return new AttemptContext(new OperationId('op-1'), AttemptId::fromLowerHex(Fixtures::hex(1)), 1, $deadline, $this->cancel(), Duration::seconds(1));
    }

    private function controls(Deadline $deadline): AttemptControls
    {
        return new AttemptControls(Duration::seconds(1), $deadline, $this->cancel(), $this->clock());
    }

    public function testDeclaresSynchronousInlineCapabilitiesWithSchedulingPerConstructor(): void
    {
        $none = (new SynchronousRuntimeAdapter())->capabilities();
        self::assertSame(InvocationForm::Synchronous, $none->invocation);
        self::assertSame(SettlementCapability::InlineReturnOrThrow, $none->settlement);
        self::assertSame(AttemptTimeoutCapability::Unsupported, $none->attemptTimeout);
        self::assertSame(CancellationCapability::CheckpointOnly, $none->cancellation);
        self::assertSame(QueueOwnership::None, $none->queueOwnership);
        self::assertSame(SchedulingCapability::None, $none->scheduling);

        self::assertSame(
            SchedulingCapability::BoundedBlocking,
            (new SynchronousRuntimeAdapter(SchedulingCapability::BoundedBlocking))->capabilities()->scheduling,
        );
    }

    public function testRejectsAQueueDrivenSchedulingClaim(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new SynchronousRuntimeAdapter(SchedulingCapability::AdapterDrivenQueue);
    }

    public function testCapturesTheOriginalReturnValueAndTheObservationTimeOnTheControlsClock(): void
    {
        $deadline = Deadline::after(Duration::seconds(1), $this->clock());
        $adapter = new SynchronousRuntimeAdapter();
        $operation = (new CallableAttemptOperation(function (): array {
            $this->clock()->advance(7);

            return ['x' => null];
        }))->prepare($this->context($deadline));
        $context = $this->context($deadline);

        $observation = $adapter->invoke($operation, $context, $this->controls($deadline))->awaitObservedSettlement($this->controls($deadline));

        self::assertSame(OutcomeKind::Value, $observation->outcome->kind);
        self::assertSame(['x' => null], $observation->outcome->value());
        self::assertSame(SettlementOverride::None, $observation->logicalOverride);
        self::assertSame(1_007, $observation->observedMonotonicNanoseconds);
        self::assertSame(7, $observation->elapsed?->nanoseconds);
        self::assertTrue($observation->accountingComplete);
        self::assertSame([], $observation->diagnostics);
    }

    public function testCapturesTheOriginalThrowAsAnExceptionOutcome(): void
    {
        $deadline = Deadline::after(Duration::seconds(1), $this->clock());
        $failure = new \DomainException('original');
        $operation = (new CallableAttemptOperation(static fn(): never => throw $failure))->prepare($this->context($deadline));

        $observation = (new SynchronousRuntimeAdapter())->invoke($operation, $this->context($deadline), $this->controls($deadline))->awaitObservedSettlement($this->controls($deadline));

        self::assertSame(OutcomeKind::Exception, $observation->outcome->kind);
        self::assertSame($failure, $observation->outcome->exception());
        self::assertSame(SettlementOverride::None, $observation->logicalOverride);
    }

    public function testInvokesTheCallableExactlyOncePerDispatchEvenWhenAwaitedRepeatedly(): void
    {
        $deadline = Deadline::after(Duration::seconds(1), $this->clock());
        $calls = 0;
        $operation = (new CallableAttemptOperation(static function () use (&$calls): int {
            return ++$calls;
        }))->prepare($this->context($deadline));
        $controls = $this->controls($deadline);

        $execution = (new SynchronousRuntimeAdapter())->invoke($operation, $this->context($deadline), $controls);
        $first = $execution->awaitObservedSettlement($controls);
        $second = $execution->awaitObservedSettlement($controls);

        self::assertSame(1, $calls);
        self::assertSame($first, $second);
    }

    public function testObservationAtTheDeadlineIsLateWhileOneNanosecondBeforeIsOnTime(): void
    {
        $adapter = new SynchronousRuntimeAdapter();
        foreach ([[-1, SettlementOverride::None], [0, SettlementOverride::DeadlineExceeded], [1, SettlementOverride::DeadlineExceeded]] as [$offset, $expected]) {
            $this->clock()->now = 1_000;
            $deadline = Deadline::after(Duration::nanoseconds(500), $this->clock());
            $operation = (new CallableAttemptOperation(function () use ($deadline, $offset): string {
                $this->clock()->now = $deadline->monotonicNanoseconds + $offset;

                return 'value';
            }))->prepare($this->context($deadline));

            $observation = $adapter->invoke($operation, $this->context($deadline), $this->controls($deadline))->awaitObservedSettlement($this->controls($deadline));

            self::assertSame($expected, $observation->logicalOverride, 'offset ' . $offset);
            self::assertSame('value', $observation->outcome->value(), 'the late actual value is preserved');
        }
    }

    public function testDeadlineIsSampledBeforeCancellationAtTheSameObservation(): void
    {
        $deadline = Deadline::after(Duration::nanoseconds(500), $this->clock());
        $operation = (new CallableAttemptOperation(function () use ($deadline): string {
            $this->cancel()->cancel();
            $this->clock()->now = $deadline->monotonicNanoseconds;

            return 'v';
        }))->prepare($this->context($deadline));

        $observation = (new SynchronousRuntimeAdapter())->invoke($operation, $this->context($deadline), $this->controls($deadline))->awaitObservedSettlement($this->controls($deadline));

        self::assertSame(SettlementOverride::DeadlineExceeded, $observation->logicalOverride);
    }

    public function testCancellationRequestedDuringTheCallIsLatchedAsCancelled(): void
    {
        $deadline = Deadline::after(Duration::seconds(1), $this->clock());
        $operation = (new CallableAttemptOperation(function (): string {
            $this->cancel()->cancel();

            return 'v';
        }))->prepare($this->context($deadline));

        $observation = (new SynchronousRuntimeAdapter())->invoke($operation, $this->context($deadline), $this->controls($deadline))->awaitObservedSettlement($this->controls($deadline));

        self::assertSame(SettlementOverride::Cancelled, $observation->logicalOverride);
        self::assertSame('v', $observation->outcome->value());
    }

    public function testClockFailureBeforeInvocationThrowsAndNeverInvokesTheCallable(): void
    {
        $deadline = Deadline::after(Duration::seconds(1), $this->clock());
        $invoked = false;
        $operation = (new CallableAttemptOperation(static function () use (&$invoked): void {
            $invoked = true;
        }))->prepare($this->context($deadline));
        $this->clock()->failWhen = static fn(): bool => true;

        try {
            (new SynchronousRuntimeAdapter())->invoke($operation, $this->context($deadline), $this->controls($deadline));
            self::fail('A clock failure before dispatch must prevent dispatch.');
        } catch (ClockFailureException) {
            self::assertFalse($invoked);
        }
    }

    public function testUnavailableObservationTimingIsReportedNeverFabricatedAsOnTime(): void
    {
        $deadline = Deadline::after(Duration::seconds(1), $this->clock());
        $operation = (new CallableAttemptOperation(function (): string {
            $this->clock()->failWhen = static fn(): bool => true;

            return 'v';
        }))->prepare($this->context($deadline));

        $observation = (new SynchronousRuntimeAdapter())->invoke($operation, $this->context($deadline), $this->controls($deadline))->awaitObservedSettlement($this->controls($deadline));

        self::assertSame('v', $observation->outcome->value());
        self::assertNull($observation->observedMonotonicNanoseconds);
        self::assertNull($observation->elapsed);
        self::assertFalse($observation->accountingComplete);
        self::assertSame('clock_unavailable', $observation->diagnostics[0]->reasonCode);
        self::assertSame(SettlementOverride::None, $observation->logicalOverride);
    }

    public function testUnavailableTimingKeepsAnAlreadyRequestedCancellation(): void
    {
        $deadline = Deadline::after(Duration::seconds(1), $this->clock());
        $operation = (new CallableAttemptOperation(function (): string {
            $this->cancel()->cancel();
            $this->clock()->failWhen = static fn(): bool => true;

            return 'v';
        }))->prepare($this->context($deadline));

        $observation = (new SynchronousRuntimeAdapter())->invoke($operation, $this->context($deadline), $this->controls($deadline))->awaitObservedSettlement($this->controls($deadline));

        self::assertSame(SettlementOverride::Cancelled, $observation->logicalOverride);
        self::assertNull($observation->elapsed);
    }

    public function testCallableWrapperInfersNoReplayGuaranteeAndDisposesNothing(): void
    {
        $deadline = Deadline::after(Duration::seconds(1), $this->clock());
        $context = $this->context($deadline);
        $prepared = (new CallableAttemptOperation(static fn(AttemptContext $c): string => $c->operationId->value))->prepare($context);

        $prepared->verifyReplay($context);
        self::assertSame('op-1', $prepared->invoke($context));
        $prepared->discardOutcome(\Enaxon\Reqtide\Outcome\Outcome::fromValue('x', \Enaxon\Reqtide\Execution\BoundedMetadata::empty()));
    }
}
