<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Testing;

use Enaxon\Reqtide\Completion\SettlementOverride;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\PreparationFailedException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Outcome\OutcomeKind;
use Enaxon\Reqtide\Runtime\AttemptControls;
use Enaxon\Reqtide\Runtime\AttemptTimeoutCapability;
use Enaxon\Reqtide\Runtime\CancellationCapability;
use Enaxon\Reqtide\Runtime\DetachedCompletionCapability;
use Enaxon\Reqtide\Runtime\InvocationForm;
use Enaxon\Reqtide\Runtime\NonblockingCapability;
use Enaxon\Reqtide\Runtime\PhysicalStopCapability;
use Enaxon\Reqtide\Runtime\PreparedAttempt;
use Enaxon\Reqtide\Runtime\QueueOwnership;
use Enaxon\Reqtide\Runtime\RuntimeCapabilities;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Runtime\SettlementCapability;
use Enaxon\Reqtide\Testing\ControlledRuntimeAdapter;
use Enaxon\Reqtide\Testing\DeterministicIdentityGenerator;
use Enaxon\Reqtide\Testing\DispatchScript;
use Enaxon\Reqtide\Testing\RecordingAttemptOperation;
use Enaxon\Reqtide\Testing\VirtualClock;
use Enaxon\Reqtide\Time\CancellationToken;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;
use PHPUnit\Framework\TestCase;

final class ControlledRuntimeTest extends TestCase
{
    private ?VirtualClock $clockInstance = null;
    private ?CancellationToken $tokenInstance = null;
    private ?Deadline $deadlineInstance = null;
    private ?DeterministicIdentityGenerator $idsInstance = null;

    private function clock(): VirtualClock
    {
        return $this->clockInstance ??= new VirtualClock(0);
    }

    private function token(): CancellationToken
    {
        return $this->tokenInstance ??= new CancellationToken();
    }

    private function deadline(): Deadline
    {
        return $this->deadlineInstance ??= Deadline::after(Duration::seconds(1), $this->clock());
    }

    private function ids(): DeterministicIdentityGenerator
    {
        return $this->idsInstance ??= new DeterministicIdentityGenerator();
    }

    private function context(int $number = 1): AttemptContext
    {
        return new AttemptContext(new OperationId('op'), $this->ids()->attempt(), $number, $this->deadline(), $this->token(), Duration::seconds(1));
    }

    private function controls(): AttemptControls
    {
        return new AttemptControls(Duration::seconds(1), $this->deadline(), $this->token(), $this->clock());
    }

    /** @return PreparedAttempt<mixed> */
    private function prepared(): PreparedAttempt
    {
        /** @var RecordingAttemptOperation<mixed> $op */
        $op = new RecordingAttemptOperation(static fn(AttemptContext $c): mixed => null);

        return $op->prepare($this->context());
    }

    public function testDefaultCapabilitiesAreSynchronous(): void
    {
        $caps = (new ControlledRuntimeAdapter($this->clock()))->capabilities();
        self::assertSame(InvocationForm::Synchronous, $caps->invocation);
        self::assertSame(SchedulingCapability::BoundedBlocking, $caps->scheduling);
        self::assertSame(SettlementCapability::InlineReturnOrThrow, $caps->settlement);
    }

    public function testCustomSynchronousCapabilitiesAreHonouredAndPromiseRejected(): void
    {
        $custom = new RuntimeCapabilities(
            InvocationForm::Synchronous,
            AttemptTimeoutCapability::TransportEnforced,
            SchedulingCapability::None,
            QueueOwnership::None,
            CancellationCapability::CheckpointOnly,
            SettlementCapability::InlineReturnOrThrow,
            DetachedCompletionCapability::Unsupported,
            NonblockingCapability::NotClaimed,
            PhysicalStopCapability::NotGuaranteed,
        );
        $adapter = new ControlledRuntimeAdapter($this->clock(), null, $custom);
        self::assertSame($custom, $adapter->capabilities());

        $promise = new RuntimeCapabilities(
            InvocationForm::ObservedPromise,
            AttemptTimeoutCapability::TransportEnforced,
            SchedulingCapability::AdapterDrivenQueue,
            QueueOwnership::AdapterOwned,
            CancellationCapability::TransportRequest,
            SettlementCapability::TerminalPromiseSettlement,
            DetachedCompletionCapability::Unsupported,
            NonblockingCapability::NotClaimed,
            PhysicalStopCapability::NotGuaranteed,
        );
        $this->expectException(InvalidConfigurationException::class);
        new ControlledRuntimeAdapter($this->clock(), null, $promise);
    }

    public function testReturnedValueIsObservedOnTimeWithElapsedTime(): void
    {
        $adapter = new ControlledRuntimeAdapter($this->clock());
        $adapter->enqueue(DispatchScript::returning('ok')->advancing(Duration::milliseconds(250)));

        $observation = $adapter->invoke($this->prepared(), $this->context(), $this->controls())
            ->awaitObservedSettlement($this->controls());

        self::assertSame(OutcomeKind::Value, $observation->outcome->kind);
        self::assertSame('ok', $observation->outcome->value());
        self::assertSame(SettlementOverride::None, $observation->logicalOverride);
        self::assertSame(250_000_000, $observation->observedMonotonicNanoseconds);
        self::assertNotNull($observation->elapsed);
        self::assertSame(250_000_000, $observation->elapsed->nanoseconds);
        self::assertTrue($observation->accountingComplete);
        self::assertSame([], $observation->diagnostics);
    }

    public function testNullValueRemainsAValue(): void
    {
        $adapter = new ControlledRuntimeAdapter($this->clock(), DispatchScript::returning(null));
        $observation = $adapter->invoke($this->prepared(), $this->context(), $this->controls())
            ->awaitObservedSettlement($this->controls());

        self::assertSame(OutcomeKind::Value, $observation->outcome->kind);
        self::assertNull($observation->outcome->value());
    }

    public function testThrownExceptionIsPreservedByIdentity(): void
    {
        $failure = new \DomainException('boom');
        $adapter = new ControlledRuntimeAdapter($this->clock());
        $adapter->enqueue(DispatchScript::throwing($failure));

        $observation = $adapter->invoke($this->prepared(), $this->context(), $this->controls())
            ->awaitObservedSettlement($this->controls());

        self::assertSame(OutcomeKind::Exception, $observation->outcome->kind);
        self::assertSame($failure, $observation->outcome->exception());
        self::assertSame(SettlementOverride::None, $observation->logicalOverride);
    }

    public function testSettlementAtExactDeadlineIsLate(): void
    {
        $adapter = new ControlledRuntimeAdapter($this->clock());
        $adapter->enqueue(DispatchScript::returning('late')->advancing(Duration::seconds(1)));

        $observation = $adapter->invoke($this->prepared(), $this->context(), $this->controls())
            ->awaitObservedSettlement($this->controls());

        self::assertSame(SettlementOverride::DeadlineExceeded, $observation->logicalOverride);
        self::assertSame('late', $observation->outcome->value(), 'the actual outcome is retained');
    }

    public function testSettlementOneNanosecondBeforeDeadlineIsOnTime(): void
    {
        $adapter = new ControlledRuntimeAdapter($this->clock());
        $adapter->enqueue(DispatchScript::returning('ok')->advancing(Duration::nanoseconds(999_999_999)));

        $observation = $adapter->invoke($this->prepared(), $this->context(), $this->controls())
            ->awaitObservedSettlement($this->controls());

        self::assertSame(SettlementOverride::None, $observation->logicalOverride);
    }

    public function testCancellationDuringCallYieldsCancelledOverrideButKeepsOutcome(): void
    {
        $adapter = new ControlledRuntimeAdapter($this->clock());
        $adapter->enqueue(DispatchScript::returning('done')->requestingCancellation($this->token()));

        $observation = $adapter->invoke($this->prepared(), $this->context(), $this->controls())
            ->awaitObservedSettlement($this->controls());

        self::assertTrue($this->token()->isRequested());
        self::assertSame(SettlementOverride::Cancelled, $observation->logicalOverride);
        self::assertSame('done', $observation->outcome->value());
    }

    public function testDeadlineIsEvaluatedBeforeCancellation(): void
    {
        $adapter = new ControlledRuntimeAdapter($this->clock());
        $adapter->enqueue(DispatchScript::returning('x')->advancing(Duration::seconds(2))->requestingCancellation($this->token()));

        $observation = $adapter->invoke($this->prepared(), $this->context(), $this->controls())
            ->awaitObservedSettlement($this->controls());

        self::assertSame(SettlementOverride::DeadlineExceeded, $observation->logicalOverride);
    }

    public function testClockFailureAtObservationMakesTimingUnavailable(): void
    {
        $adapter = new ControlledRuntimeAdapter($this->clock(), DispatchScript::returning('v'));
        $execution = $adapter->invoke($this->prepared(), $this->context(), $this->controls());
        $this->clock()->failNextMonotonicReads();

        $observation = $execution->awaitObservedSettlement($this->controls());

        self::assertNull($observation->observedMonotonicNanoseconds);
        self::assertNull($observation->elapsed);
        self::assertFalse($observation->accountingComplete);
        self::assertCount(1, $observation->diagnostics);
        self::assertSame(SettlementOverride::None, $observation->logicalOverride);
        self::assertSame('v', $observation->outcome->value());
    }

    public function testClockFailureAtObservationPreservesRequestedCancellation(): void
    {
        $adapter = new ControlledRuntimeAdapter($this->clock(), DispatchScript::returning('v')->requestingCancellation($this->token()));
        $execution = $adapter->invoke($this->prepared(), $this->context(), $this->controls());
        $this->clock()->failNextMonotonicReads();

        $observation = $execution->awaitObservedSettlement($this->controls());

        self::assertSame(SettlementOverride::Cancelled, $observation->logicalOverride);
        self::assertFalse($observation->accountingComplete);
    }

    public function testClockFailureAtDispatchStartLeavesElapsedUnavailable(): void
    {
        $adapter = new ControlledRuntimeAdapter($this->clock(), DispatchScript::returning('v'));
        $prepared = $this->prepared();
        $context = $this->context();
        $controls = $this->controls();
        $this->clock()->failNextMonotonicReads();
        $execution = $adapter->invoke($prepared, $context, $controls);

        $observation = $execution->awaitObservedSettlement($this->controls());

        self::assertNotNull($observation->observedMonotonicNanoseconds);
        self::assertNull($observation->elapsed);
        self::assertFalse($observation->accountingComplete);
        self::assertNotSame([], $observation->diagnostics);
    }

    public function testDispatchCountAndContextsAreRecorded(): void
    {
        $adapter = new ControlledRuntimeAdapter($this->clock());
        $adapter->enqueue(DispatchScript::throwing(new \RuntimeException('1')), DispatchScript::returning('2'));
        self::assertSame(0, $adapter->dispatchCount());

        $first = $this->context(1);
        $second = $this->context(2);
        $adapter->invoke($this->prepared(), $first, $this->controls());
        $adapter->invoke($this->prepared(), $second, $this->controls());

        self::assertSame(2, $adapter->dispatchCount());
        self::assertSame([$first, $second], $adapter->attemptContexts());
        self::assertSame($first, $adapter->dispatches()[0]->context);
        self::assertSame($this->clock(), $adapter->dispatches()[1]->controls->clock);
        self::assertSame(0, $adapter->pendingScripts());
    }

    public function testDefaultScriptServesUnqueuedDispatches(): void
    {
        $adapter = new ControlledRuntimeAdapter($this->clock(), DispatchScript::returning(7));
        $a = $adapter->invoke($this->prepared(), $this->context(1), $this->controls())->awaitObservedSettlement($this->controls());
        $b = $adapter->invoke($this->prepared(), $this->context(2), $this->controls())->awaitObservedSettlement($this->controls());

        self::assertSame(7, $a->outcome->value());
        self::assertSame(7, $b->outcome->value());
        self::assertSame(2, $adapter->dispatchCount());
    }

    public function testUnscriptedDispatchIsCountedThenFailsLoudly(): void
    {
        $adapter = new ControlledRuntimeAdapter($this->clock());
        try {
            $adapter->invoke($this->prepared(), $this->context(), $this->controls());
            self::fail('LogicException expected');
        } catch (\LogicException) {
            self::assertSame(1, $adapter->dispatchCount());
        }
    }

    public function testCallThroughRunsTheRealOperationAndCapturesItsThrow(): void
    {
        $failure = new \RuntimeException('real');
        $ok = new RecordingAttemptOperation(static fn(AttemptContext $c): string => 'attempt-' . $c->dispatchedAttemptNumber);
        $bad = new RecordingAttemptOperation(static function (AttemptContext $c) use ($failure): never {
            throw $failure;
        });
        $adapter = new ControlledRuntimeAdapter($this->clock(), DispatchScript::callingOperation());

        $context = $this->context(3);
        $value = $adapter->invoke($ok->prepare($context), $context, $this->controls())->awaitObservedSettlement($this->controls());
        $error = $adapter->invoke($bad->prepare($context), $context, $this->controls())->awaitObservedSettlement($this->controls());

        self::assertSame('attempt-3', $value->outcome->value());
        self::assertSame(1, $ok->invokeCount());
        self::assertSame($failure, $error->outcome->exception());
        self::assertSame(1, $bad->invokeCount());
    }

    public function testScriptedValueDoesNotInvokeTheOperation(): void
    {
        $op = new RecordingAttemptOperation(static fn(AttemptContext $c): string => 'real');
        $adapter = new ControlledRuntimeAdapter($this->clock(), DispatchScript::returning('fake'));
        $context = $this->context();
        $adapter->invoke($op->prepare($context), $context, $this->controls());

        self::assertSame(0, $op->invokeCount());
    }

    public function testRecordingAttemptOperationCountsPreparesRewindsVerifiesAndDiscards(): void
    {
        $op = new RecordingAttemptOperation(static fn(AttemptContext $c): int => 1);
        $context = $this->context();
        $prepared = $op->prepare($context);
        self::assertSame(0, $op->rewinds());
        $op->prepare($context);
        $op->prepare($context);
        self::assertSame(3, $op->prepareCount());
        self::assertSame(2, $op->rewinds());

        $prepared->verifyReplay($context);
        self::assertSame(1, $op->verifyReplayCount());
        self::assertSame(1, $prepared->invoke($context));
        self::assertSame(1, $op->invokeCount());

        $outcome = Outcome::fromValue(5, BoundedMetadata::empty());
        $prepared->discardOutcome($outcome);
        self::assertSame([$outcome], $op->discardedOutcomes());
    }

    public function testRecordingAttemptOperationInjectedFailuresAreOneShot(): void
    {
        $op = new RecordingAttemptOperation(static fn(AttemptContext $c): int => 1);
        $context = $this->context();
        $cause = new \RuntimeException('stream gone');

        $op->failNextPrepare($cause);
        try {
            $op->prepare($context);
            self::fail('PreparationFailedException expected');
        } catch (PreparationFailedException $e) {
            self::assertSame($cause, $e->preparationCause);
        }
        $prepared = $op->prepare($context);
        self::assertSame(2, $op->prepareCount());

        $op->failNextVerifyReplay($cause);
        try {
            $prepared->verifyReplay($context);
            self::fail('PreparationFailedException expected');
        } catch (PreparationFailedException $e) {
            self::assertSame($cause, $e->preparationCause);
        }
        $prepared->verifyReplay($context);
        self::assertSame(2, $op->verifyReplayCount());
    }
}
