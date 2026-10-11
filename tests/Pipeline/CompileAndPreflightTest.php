<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Exception\CapabilityMismatchException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\ReplayPreparation;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Pipeline\CompiledPipeline;
use Enaxon\Reqtide\Pipeline\PipelineDefinition;
use Enaxon\Reqtide\Pipeline\RuntimeEnvironment;
use Enaxon\Reqtide\Policy\AttemptOptions;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Runtime\AttemptTimeoutCapability;
use Enaxon\Reqtide\Runtime\CancellationCapability;
use Enaxon\Reqtide\Runtime\DetachedCompletionCapability;
use Enaxon\Reqtide\Runtime\InvocationForm;
use Enaxon\Reqtide\Runtime\NonblockingCapability;
use Enaxon\Reqtide\Runtime\PhysicalStopCapability;
use Enaxon\Reqtide\Runtime\QueueOwnership;
use Enaxon\Reqtide\Runtime\RuntimeCapabilities;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Runtime\SettlementCapability;
use Enaxon\Reqtide\Runtime\SynchronousRuntimeAdapter;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Tests\Pipeline\Support\DispatchSpy;
use Enaxon\Reqtide\Tests\Pipeline\Support\Harness;
use Enaxon\Reqtide\Tests\Pipeline\Support\RecordingStrategy;
use Enaxon\Reqtide\Tests\Pipeline\Support\SpyOperation;
use Enaxon\Reqtide\Tests\Pipeline\Support\StubCapabilityRuntime;
use Enaxon\Reqtide\Tests\Pipeline\Support\StubCapabilityScheduler;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Time\NoDelayScheduler;
use Enaxon\Reqtide\Value\ApplicationId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\EnvironmentId;
use Enaxon\Reqtide\Value\PartitionIdentity;
use Enaxon\Reqtide\Value\PolicyId;
use Enaxon\Reqtide\Value\PolicyIdentity;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\ProfileVersion;
use Enaxon\Reqtide\Value\SubjectId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Compile-time capability rejection, definition validation and operation preflight. */
final class CompileAndPreflightTest extends TestCase
{
    private static function withRuntime(Harness $h, \Enaxon\Reqtide\Runtime\RuntimeAdapter $runtime, ?\Enaxon\Reqtide\Time\DelayScheduler $scheduler = null): RuntimeEnvironment
    {
        $base = $h->environment();

        return new RuntimeEnvironment(
            $base->clock,
            $scheduler ?? $base->scheduler,
            $base->random,
            $base->classifier,
            $runtime,
            $base->events,
            $base->rateLimit,
            $base->circuit,
            $base->concurrency,
            $base->retryBudget,
            $base->cooldown,
        );
    }

    private static function promiseRuntime(): StubCapabilityRuntime
    {
        return new StubCapabilityRuntime(new RuntimeCapabilities(
            InvocationForm::ObservedPromise,
            AttemptTimeoutCapability::TransportEnforced,
            SchedulingCapability::AdapterDrivenQueue,
            QueueOwnership::AdapterOwned,
            CancellationCapability::TransportRequest,
            SettlementCapability::TerminalPromiseSettlement,
            DetachedCompletionCapability::Unsupported,
            NonblockingCapability::NotClaimed,
            PhysicalStopCapability::NotGuaranteed,
        ));
    }

    public function testApiA02TransportEnforcedTimeoutRequiresTheRuntimeToEnforceIt(): void
    {
        $h = new Harness();

        $this->expectException(CapabilityMismatchException::class);
        $h->definition(null, false, AttemptTimeoutRequirement::TransportEnforced)->compile($h->environment());
    }

    public function testApiA02TransportEnforcedTimeoutCompilesWhenTheRuntimeEnforcesIt(): void
    {
        $h = new Harness();

        $pipeline = $h->definition(null, false, AttemptTimeoutRequirement::TransportEnforced)->compile(self::withRuntime($h, self::promiseRuntime(), new StubCapabilityScheduler(SchedulingCapability::AdapterDrivenQueue)));

        self::assertInstanceOf(CompiledPipeline::class, $pipeline);
    }

    public function testApiA02RetryDelaysRequireASchedulerThatCanWait(): void
    {
        $h = new Harness(false, false, false, true);

        $this->expectException(CapabilityMismatchException::class);
        $h->definition(Harness::retry(3))->compile(self::withRuntime($h, new SynchronousRuntimeAdapter(), new NoDelayScheduler($h->clock)));
    }

    public function testApiA02ZeroDelayRetriesNeedNoScheduler(): void
    {
        $h = new Harness(false, false, false, true);

        $pipeline = $h->definition(Harness::retry(3, 0, 0))->compile(self::withRuntime($h, new SynchronousRuntimeAdapter(), new NoDelayScheduler($h->clock)));

        self::assertInstanceOf(CompiledPipeline::class, $pipeline);
    }

    public function testApiA02QueueDrivenSchedulerWithoutAnAdapterOwnedQueueIsRejected(): void
    {
        $h = new Harness(false, false, false, true);

        $this->expectException(CapabilityMismatchException::class);
        $h->definition(Harness::retry(3))->compile(self::withRuntime($h, new SynchronousRuntimeAdapter(SchedulingCapability::BoundedBlocking), new StubCapabilityScheduler(SchedulingCapability::AdapterDrivenQueue)));
    }

    public function testApiA02BlockingDelayCannotBeAdvertisedForAPromiseRuntime(): void
    {
        $h = new Harness(false, false, false, true);

        $this->expectException(CapabilityMismatchException::class);
        // The promise runtime owns a queue; a bounded blocking scheduler would silently block while claiming async execution.
        $h->definition(Harness::retry(3))->compile(self::withRuntime($h, self::promiseRuntime()));
    }

    public function testApiA02PromiseRuntimeWithItsOwnQueueDrivenSchedulerCompiles(): void
    {
        $h = new Harness(false, false, false, true);

        $pipeline = $h->definition(Harness::retry(3))->compile(self::withRuntime($h, self::promiseRuntime(), new StubCapabilityScheduler(SchedulingCapability::AdapterDrivenQueue)));

        self::assertContains('queue_ownership=adapter_owned', $pipeline->explain()->capabilityNotes);
    }

    public function testApiA02RuntimeCapabilityCombinationsThatCannotBeExpressedAreRejectedAtConstruction(): void
    {
        // A promise runtime without a queue owner, a synchronous runtime claiming transport cancellation, and
        // detached/nonblocking/physical-stop claims do not exist as enum cases at all.
        $this->expectException(InvalidConfigurationException::class);
        new RuntimeCapabilities(
            InvocationForm::ObservedPromise,
            AttemptTimeoutCapability::Unsupported,
            SchedulingCapability::None,
            QueueOwnership::None,
            CancellationCapability::CheckpointOnly,
            SettlementCapability::TerminalPromiseSettlement,
            DetachedCompletionCapability::Unsupported,
            NonblockingCapability::NotClaimed,
            PhysicalStopCapability::NotGuaranteed,
        );
    }

    /** @return iterable<string, array{int}> */
    public static function policyKinds(): iterable
    {
        yield 'rate limit' => [0];
        yield 'circuit' => [1];
        yield 'concurrency' => [2];
        yield 'retry budget' => [3];
        yield 'cooldown' => [4];
    }

    #[DataProvider('policyKinds')]
    public function testApiA02AConfiguredPolicyWithoutItsBackendIsRejected(int $missing): void
    {
        $h = new Harness(true, true, true, true, true);
        $base = $h->environment();
        $backends = [$base->rateLimit, $base->circuit, $base->concurrency, $base->retryBudget, $base->cooldown];
        $backends[$missing] = null;
        $environment = new RuntimeEnvironment(
            $base->clock,
            $base->scheduler,
            $base->random,
            $base->classifier,
            $base->runtime,
            $base->events,
            $backends[0] instanceof \Enaxon\Reqtide\State\RateLimitBackend ? $backends[0] : null,
            $backends[1] instanceof \Enaxon\Reqtide\State\CircuitBackend ? $backends[1] : null,
            $backends[2] instanceof \Enaxon\Reqtide\State\ConcurrencyBackend ? $backends[2] : null,
            $backends[3] instanceof \Enaxon\Reqtide\State\RetryBudgetBackend ? $backends[3] : null,
            $backends[4] instanceof \Enaxon\Reqtide\State\CooldownBackend ? $backends[4] : null,
        );

        $this->expectException(CapabilityMismatchException::class);
        $h->definition()->compile($environment);
    }

    public function testApiA02FiniteBackendIoIsRequiredFromEverySelectedSharedAuthorityBackendWhenConfigured(): void
    {
        $h = new Harness(true, true);
        $h->circuit->scope = StateScope::SharedAuthority;
        $h->concurrency->scope = StateScope::SharedAuthority;
        $h->circuit->ioTimeout = Duration::milliseconds(50);

        $rejected = false;
        try {
            $h->definition(null, true)->compile($h->environment());
        } catch (CapabilityMismatchException) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'The concurrency backend declares no finite I/O timeout.');

        $h->concurrency->ioTimeout = Duration::milliseconds(50);
        self::assertInstanceOf(CompiledPipeline::class, $h->definition(null, true)->compile($h->environment()));
        // Without the requirement a missing timeout makes no bounded claim and is allowed.
        $h->concurrency->ioTimeout = null;
        self::assertInstanceOf(CompiledPipeline::class, $h->definition(null, false)->compile($h->environment()));
    }

    public function testApiA02ExplainPerformsNoBackendRuntimeOrClockIo(): void
    {
        $h = new Harness(true, true, true, true, true);
        $pipeline = $h->pipeline(Harness::retry(3));
        $readsAfterCompile = $h->clock->reads;
        $logAfterCompile = $h->log->entries;

        $explanation = $pipeline->explain();

        self::assertSame($readsAfterCompile, $h->clock->reads);
        self::assertSame($logAfterCompile, $h->log->entries);
        self::assertSame([], $h->sink->events);
        self::assertSame('profile', $explanation->profileId->value);
        self::assertSame('v1', $explanation->profileVersion->value);
        self::assertContains('retry(maxAttempts=3)', $explanation->enabledPolicies);
        foreach (['rate_limit', 'circuit', 'concurrency', 'retry_budget', 'cooldown'] as $policy) {
            self::assertContains($policy, $explanation->enabledPolicies);
        }
        self::assertContains('invocation=synchronous', $explanation->capabilityNotes);
        self::assertContains('attempt_timeout=unsupported', $explanation->capabilityNotes);
        self::assertContains('physical_stop=not_guaranteed', $explanation->capabilityNotes);
    }

    public function testCompilationAppliesDeclarativeStrategiesWithTheRuntimeCapabilities(): void
    {
        $h = new Harness();
        $strategy = new RecordingStrategy();
        $definition = $h->definition();
        $withStrategy = new PipelineDefinition(
            $definition->profileId,
            $definition->profileVersion,
            $definition->retry,
            $definition->attempt,
            null,
            null,
            null,
            null,
            null,
            null,
            false,
            [$strategy],
        );

        $withStrategy->compile($h->environment());

        self::assertCount(1, $strategy->seen);
        self::assertSame(InvocationForm::Synchronous, $strategy->seen[0]->invocation);
    }

    public function testDefinitionRejectsRateCostWithoutRateLimitAndTheReverse(): void
    {
        $h = new Harness(false, false, true);
        $base = $h->definition();

        $rejected = false;
        try {
            new PipelineDefinition($base->profileId, $base->profileVersion, $base->retry, $base->attempt, null, new PositiveInt(1), null, null, null, null, false);
        } catch (InvalidConfigurationException) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'A cost without a rate limit is invalid.');
        $this->expectException(InvalidConfigurationException::class);
        new PipelineDefinition($base->profileId, $base->profileVersion, $base->retry, $base->attempt, $base->rateLimit, null, null, null, null, null, false);
    }

    public function testDefinitionRejectsMoreThanOneAttemptWithoutARetryBudget(): void
    {
        $h = new Harness();
        $base = $h->definition();

        $this->expectException(InvalidConfigurationException::class);
        new PipelineDefinition($base->profileId, $base->profileVersion, Harness::retry(2), $base->attempt, null, null, null, null, null, null, false);
    }

    public function testDefinitionRejectsAPolicyFromAnotherProfileVersion(): void
    {
        $h = new Harness();
        $base = $h->definition();
        $other = new ConcurrencyPolicy(
            new PolicyIdentity(Fixtures::partition(), new ProfileVersion('v2'), new PolicyId('concurrency')),
            new PositiveInt(1),
            Duration::seconds(1),
        );

        $this->expectException(InvalidConfigurationException::class);
        new PipelineDefinition($base->profileId, $base->profileVersion, $base->retry, $base->attempt, null, null, null, $other, null, null, false);
    }

    public function testDefinitionRejectsPoliciesOfDifferentPartitions(): void
    {
        $h = new Harness();
        $base = $h->definition();
        $partition = new PartitionIdentity(new ApplicationId('app'), new EnvironmentId('test'), null, new SubjectId('someone-else'));
        $concurrency = new ConcurrencyPolicy(Harness::identity('concurrency'), new PositiveInt(1), Duration::seconds(1));
        $budget = new RetryBudgetPolicy(
            new PolicyIdentity($partition, new ProfileVersion('v1'), new PolicyId('budget')),
            new PositiveInt(1),
            new PositiveInt(1),
            Duration::seconds(1),
            Duration::seconds(1),
        );

        $this->expectException(InvalidConfigurationException::class);
        new PipelineDefinition($base->profileId, $base->profileVersion, $base->retry, new AttemptOptions(Duration::seconds(1), AttemptTimeoutRequirement::Cooperative), null, null, null, $concurrency, $budget, null, false);
    }

    public function testGenericCallableDefaultsToUnsafeAndNeedsASingleAttemptBeforeAnyAdmission(): void
    {
        $h = new Harness(true, true, true, true);
        $spy = DispatchSpy::returning('v', $h->log);
        $pipeline = $h->pipeline(Harness::retry(3));

        foreach ([false, true] as $detailed) {
            $rejected = false;
            try {
                $detailed
                    ? $pipeline->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)))
                    : $pipeline->execute($spy->callable(), $h->context(Duration::seconds(5)));
            } catch (InvalidConfigurationException) {
                $rejected = true;
            }
            self::assertTrue($rejected, 'An Unsafe callable must not be accepted with maxAttempts > 1.');
        }

        self::assertSame(0, $spy->dispatches);
        self::assertSame([], $h->log->entries, 'validation happens before any admission, dispatch or event');
    }

    public function testGenericCallableRetryRequiresACallableAssertionContract(): void
    {
        $h = new Harness(true, true, true, true);
        $spy = DispatchSpy::returning('v', $h->log);
        $rewind = OperationSafety::replayableRead(new ReplaySafetyContract(
            'contract-1',
            'identity-1',
            'scope-1',
            'safe-repeat',
            new \DateTimeImmutable('2026-10-07T00:00:00+00:00'),
            new \DateTimeImmutable('2026-10-09T00:00:00+00:00'),
            Duration::seconds(3600),
            ReplayPreparation::VerifiedRewind,
            ['transient'],
        ));
        $pipeline = $h->pipeline(Harness::retry(3));

        try {
            $pipeline->execute($spy->callable(), $h->context(Duration::seconds(5), null, $rewind));
            self::fail('A rewind proof says nothing about a plain callable.');
        } catch (InvalidConfigurationException) {
            self::assertSame(0, $spy->dispatches);
        }

        // The typed operation seam may carry that proof.
        $operation = new SpyOperation($h->log);
        self::assertSame('value', $pipeline->executeOperation($operation, $h->context(Duration::seconds(5), null, $rewind)));
    }

    public function testExecutionContextPartitionMustEqualEverySelectedPolicyPartition(): void
    {
        $h = new Harness(true, true);
        $spy = DispatchSpy::returning('v', $h->log);
        $foreign = new \Enaxon\Reqtide\Execution\ExecutionContext(
            new \Enaxon\Reqtide\Value\OperationId('op-2'),
            new PartitionIdentity(new ApplicationId('app'), new EnvironmentId('test'), new \Enaxon\Reqtide\Value\TenantId('tenant-b'), new SubjectId('subject')),
            \Enaxon\Reqtide\Time\Deadline::after(Duration::seconds(5), $h->clock),
            $h->cancel,
            new \Enaxon\Reqtide\Execution\OperationMetadata(OperationSafety::unsafe(), \Enaxon\Reqtide\Execution\BoundedMetadata::empty()),
            null,
        );

        $this->expectException(InvalidConfigurationException::class);
        try {
            $h->pipeline()->execute($spy->callable(), $foreign);
        } finally {
            self::assertSame(0, $spy->dispatches);
            self::assertSame([], $h->log->entries, 'a foreign partition never touches a policy of another tenant');
        }
    }

    public function testDeadlineFromAnotherClockIsAConfigurationErrorBeforeAnyMutation(): void
    {
        $h = new Harness(true, true);
        $spy = DispatchSpy::returning('v', $h->log);
        $otherClock = new Support\PipelineClock();
        $foreign = new \Enaxon\Reqtide\Execution\ExecutionContext(
            new \Enaxon\Reqtide\Value\OperationId('op-2'),
            Fixtures::partition(),
            \Enaxon\Reqtide\Time\Deadline::after(Duration::seconds(5), $otherClock),
            $h->cancel,
            new \Enaxon\Reqtide\Execution\OperationMetadata(OperationSafety::unsafe(), \Enaxon\Reqtide\Execution\BoundedMetadata::empty()),
            null,
        );

        $this->expectException(InvalidConfigurationException::class);
        try {
            $h->pipeline()->execute($spy->callable(), $foreign);
        } finally {
            self::assertSame([], $h->log->entries);
        }
    }

    public function testReplayContractWithInsufficientRetentionOrHorizonDeniesRetryWithoutBlockingTheFirstAttempt(): void
    {
        $now = new \DateTimeImmutable('2026-10-08T00:00:00+00:00');
        $cases = [
            'replay_horizon_inadequate' => [$now->modify('-1 hour'), $now->modify('+10 seconds'), Duration::seconds(3600)],
            'replay_contract_not_valid' => [$now->modify('-2 hour'), $now->modify('-1 hour'), Duration::seconds(3600)],
        ];
        foreach ($cases as $reason => [$established, $validUntil, $retention]) {
            $h = new Harness(false, false, false, true);
            $contract = OperationSafety::replayableRead(new ReplaySafetyContract(
                'contract-1',
                'identity-1',
                'scope-1',
                'safe-repeat',
                $established,
                $validUntil,
                $retention,
                ReplayPreparation::CallableAssertion,
                ['transient'],
            ));
            $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

            $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, $contract));

            self::assertSame(1, $spy->dispatches, $reason);
            self::assertSame(CompletionDisposition::Failed, $completion->disposition, $reason);
            self::assertContains($reason, array_map(static fn(Diagnostic $d): string => $d->reasonCode, $completion->diagnostics));
        }

        // Retention shorter than the remaining total deadline is also inadequate.
        $h = new Harness(false, false, false, true);
        $contract = OperationSafety::replayableRead(new ReplaySafetyContract(
            'contract-1',
            'identity-1',
            'scope-1',
            'safe-repeat',
            $now->modify('-1 hour'),
            $now->modify('+1 hour'),
            Duration::seconds(10),
            ReplayPreparation::CallableAssertion,
            ['transient'],
        ));
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);
        $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, $contract));
        self::assertSame(1, $spy->dispatches);
    }

    public function testCompiledPipelineKeepsNoPerOperationStateAndIsReentrant(): void
    {
        $h = new Harness(true, true);
        $pipeline = $h->pipeline();

        $reflection = new \ReflectionClass(CompiledPipeline::class);
        foreach ($reflection->getProperties() as $property) {
            self::assertTrue($property->isReadOnly(), $property->getName() . ' must be readonly configuration');
        }
        self::assertCount(3, $reflection->getProperties());

        $inner = null;
        $outer = DispatchSpy::of($h->log, static function () use ($pipeline, $h, &$inner): string {
            $innerSpy = DispatchSpy::throwing(new \RuntimeException('inner failure'), $h->log);
            $inner = $pipeline->executeDetailed($innerSpy->callable(), new \Enaxon\Reqtide\Execution\ExecutionContext(
                new \Enaxon\Reqtide\Value\OperationId('op-inner'),
                Fixtures::partition(),
                \Enaxon\Reqtide\Time\Deadline::after(Duration::seconds(5), $h->clock),
                $h->cancel,
                new \Enaxon\Reqtide\Execution\OperationMetadata(OperationSafety::unsafe(), \Enaxon\Reqtide\Execution\BoundedMetadata::empty()),
                null,
            ));

            return 'outer';
        });

        $completion = $pipeline->executeDetailed($outer->callable(), $h->context(Duration::seconds(10)));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertNotNull($inner);
        self::assertSame(CompletionDisposition::Failed, $inner->disposition);
        self::assertSame('op-inner', $inner->summary->operationId->value);
        self::assertSame('op-1', $completion->summary->operationId->value);
        self::assertSame(1, $completion->summary->dispatched);
        self::assertSame(1, $inner->summary->dispatched);
        self::assertSame(0, $h->concurrency->active);
        self::assertSame(0, $h->circuit->outstanding);
    }
}
