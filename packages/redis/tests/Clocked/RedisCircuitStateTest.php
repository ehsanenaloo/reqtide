<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Clocked;

use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\Redis\RedisCircuitBackend;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRegistryAdmin;
use Enaxon\Reqtide\Redis\RedisRegistryMaintenance;
use Enaxon\Reqtide\Redis\Tests\Support\RedisBackendHarness;
use Enaxon\Reqtide\State\CircuitAdmission;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\CircuitPermit;
use Enaxon\Reqtide\State\CircuitPermitKind;
use Enaxon\Reqtide\State\CircuitTransition;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\State\Memory\MemoryCircuitBackend;
use Enaxon\Reqtide\Tests\Contract\ContractCircuitRig;
use Enaxon\Reqtide\Tests\Contract\ContractIdentities;
use Enaxon\Reqtide\Time\SystemClock;
use Enaxon\Reqtide\Value\BackendExpiry;
use Enaxon\Reqtide\Value\BackendInstant;
use Enaxon\Reqtide\Value\Generation;
use Enaxon\Reqtide\Value\IncarnationId;
use Enaxon\Reqtide\Value\PermitOwnerId;
use PHPUnit\Framework\TestCase;

/**
 * What only a shared store can show for the circuit breaker: backends
 * over one registry see one circuit, every transition is reported as a fact with its reason, the registry counters follow
 * permits and transitions, terminal records are evicted across state kinds while circuit state never is, a full time
 * bucket is refused before any change, and exact ownership holds for every field of a permit.
 */
final class RedisCircuitStateTest extends TestCase
{
    private const SECOND = 1_000_000_000;

    private ?RedisBackendHarness $harness = null;

    private ?ContractIdentities $identities = null;

    protected function setUp(): void
    {
        $this->harness()->time()->moveTo(100 * self::SECOND);
    }

    protected function tearDown(): void
    {
        $this->harness?->dispose();
    }

    private function harness(): RedisBackendHarness
    {
        return $this->harness ??= RedisBackendHarness::create();
    }

    private function ids(): ContractIdentities
    {
        return $this->identities ??= new ContractIdentities();
    }

    private function backend(RedisRegistry $registry): RedisCircuitBackend
    {
        return new RedisCircuitBackend($this->harness()->options(), $registry);
    }

    private function acquire(RedisCircuitBackend $backend, CircuitPolicy $policy): CircuitAdmission
    {
        return $backend->acquire($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
    }

    private function permit(RedisCircuitBackend $backend, CircuitPolicy $policy): CircuitPermit
    {
        $admission = $this->acquire($backend, $policy);
        self::assertSame(CircuitAdmissionStatus::Accepted, $admission->status);
        self::assertNotNull($admission->permit);

        return $admission->permit;
    }

    private function failOnce(RedisCircuitBackend $backend, CircuitPolicy $policy): ?CircuitTransition
    {
        return $backend->record($policy, $this->permit($backend, $policy), CircuitVerdict::Failure, $this->ids()->command())->transition;
    }

    /**
     * @phpstan-impure
     * @return array{int, int} partitions and records
     */
    private function counters(RedisRegistry $registry): array
    {
        $status = (new RedisRegistryAdmin($this->harness()->options(), $registry))->verify();

        return [$status->partitions, $status->records];
    }

    public function testTwoInstancesOverOneRegistryShareOneCircuit(): void
    {
        $registry = $this->harness()->registry();
        $first = $this->backend($registry);
        $second = $this->backend($registry);
        $policy = ContractCircuitRig::countPolicy(minimum: 2);

        $this->failOnce($first, $policy);
        $this->failOnce($first, $policy);
        $open = $this->acquire($second, $policy);
        self::assertSame(CircuitAdmissionStatus::Open, $open->status);
        self::assertSame(30 * self::SECOND, $open->retryAfter?->nanoseconds);

        $this->harness()->time()->advance(30 * self::SECOND);
        $probe = $this->permit($second, $policy);
        self::assertSame(CircuitPermitKind::HalfOpenProbe, $probe->kind);
        self::assertSame(FinalizationStatus::Applied, $first->record($policy, $probe, CircuitVerdict::Success, $this->ids()->command())->status);
        self::assertSame(CircuitPermitKind::ClosedSample, $this->permit($first, $policy)->kind);
    }

    public function testEveryTransitionIsOneFactWithItsReason(): void
    {
        $backend = $this->backend($this->harness()->registry());
        $policy = ContractCircuitRig::countPolicy(minimum: 2);

        self::assertNull($this->failOnce($backend, $policy));
        $opened = $this->failOnce($backend, $policy);
        self::assertEquals(new CircuitTransition('closed', 'open', ReasonCode::CircuitThresholdExceeded->value), $opened);

        $this->harness()->time()->advance(30 * self::SECOND);
        $admission = $this->acquire($backend, $policy);
        self::assertEquals(new CircuitTransition('open', 'half_open', ReasonCode::CircuitOpenElapsed->value), $admission->transition);
        self::assertNotNull($admission->permit);

        $reopened = $backend->record($policy, $admission->permit, CircuitVerdict::Failure, $this->ids()->command());
        self::assertEquals(new CircuitTransition('half_open', 'open', ReasonCode::CircuitProbeFailed->value), $reopened->transition);

        $this->harness()->time()->advance(30 * self::SECOND);
        $probe = $this->permit($backend, $policy);
        $closed = $backend->record($policy, $probe, CircuitVerdict::Success, $this->ids()->command());
        self::assertEquals(new CircuitTransition('half_open', 'closed', ReasonCode::CircuitProbesSucceeded->value), $closed->transition);
        self::assertSame(5, $probe->generation->value, 'closed 1, open 2, half open 3, open 4, half open 5: the probe was issued under generation 5');
    }

    public function testRegistryCountersFollowPermitsTransitionsAndMaintenance(): void
    {
        $registry = $this->harness()->registry();
        $backend = $this->backend($registry);
        $policy = ContractCircuitRig::timePolicy(minimum: 2);

        $first = $this->permit($backend, $policy);
        $second = $this->permit($backend, $policy);
        self::assertSame([1, 2], $this->counters($registry));

        $backend->record($policy, $first, CircuitVerdict::Neutral, $this->ids()->command());
        self::assertSame([1, 2], $this->counters($registry), 'the permit slot became a terminal record in place');
        $backend->abandon($policy, $second, $this->ids()->command());
        self::assertSame([1, 2], $this->counters($registry));

        // Idle for more than 120 s, no permit, an empty time window: the next maintenance steps remove the records and the partition.
        $this->harness()->time()->advance(300 * self::SECOND);
        $maintenance = new RedisRegistryMaintenance($this->harness()->options(), $registry);
        $maintenance->step();
        $maintenance->step();
        self::assertSame([0, 0], $this->counters($registry));
    }

    public function testATransitionDropsEveryOutstandingPermitAndTheirRecords(): void
    {
        $registry = $this->harness()->registry();
        $backend = $this->backend($registry);
        $policy = ContractCircuitRig::countPolicy(minimum: 2, leaseSeconds: 100);

        $outstanding = [$this->permit($backend, $policy), $this->permit($backend, $policy), $this->permit($backend, $policy)];
        $this->failOnce($backend, $policy);
        $this->failOnce($backend, $policy);
        // Two completed failures are terminal records (2); the three outstanding permits were dropped by the opening.
        self::assertSame([1, 2], $this->counters($registry));
        foreach ($outstanding as $permit) {
            self::assertSame(FinalizationStatus::StaleOwnership, $backend->record($policy, $permit, CircuitVerdict::Success, $this->ids()->command())->status);
        }
        self::assertSame(CircuitAdmissionStatus::Open, $this->acquire($backend, $policy)->status);
    }

    public function testCircuitStateIsNeverReclaimedWhileOpenOrHalfOpenOrHoldingCountHistory(): void
    {
        $registry = $this->harness()->registry();
        $backend = $this->backend($registry);
        $maintenance = new RedisRegistryMaintenance($this->harness()->options(), $registry);
        $open = ContractCircuitRig::countPolicy(minimum: 2, openSeconds: 1_000_000, identity: ContractIdentities::identity('open'));
        $history = ContractCircuitRig::countPolicy(minimum: 5, identity: ContractIdentities::identity('history'));

        $this->failOnce($backend, $open);
        $this->failOnce($backend, $open);
        $this->failOnce($backend, $history);

        $this->harness()->time()->advance(10_000 * self::SECOND);
        for ($i = 0; $i < 4; ++$i) {
            $maintenance->step();
        }

        self::assertSame(2, $this->counters($registry)[0], 'both partitions stay');
        self::assertSame(CircuitAdmissionStatus::Open, $this->acquire($backend, $open)->status);
    }

    public function testAnOpenPartitionWithAnEmptyTimeWindowIsNeverReclaimed(): void
    {
        $registry = $this->harness()->registry();
        $backend = $this->backend($registry);
        $maintenance = new RedisRegistryMaintenance($this->harness()->options(), $registry);
        $policy = ContractCircuitRig::timePolicy(windowSeconds: 5, minimum: 2, openSeconds: 1_000_000);

        $this->failOnce($backend, $policy);
        $this->failOnce($backend, $policy);
        $this->harness()->time()->advance(500 * self::SECOND); // the samples left the window long ago
        $maintenance->step();
        $maintenance->step();

        self::assertSame(1, $this->counters($registry)[0]);
        self::assertSame(CircuitAdmissionStatus::Open, $this->acquire($backend, $policy)->status);
    }

    public function testTheOldestTerminalRecordIsEvictedUnderRecordPressureAndTheCircuitPartitionStays(): void
    {
        $registry = $this->harness()->registry(10, 3);
        $backend = $this->backend($registry);
        $policy = ContractCircuitRig::countPolicy(minimum: 5);

        $a = $this->permit($backend, $policy);
        $b = $this->permit($backend, $policy);
        $backend->record($policy, $a, CircuitVerdict::Success, $this->ids()->command());
        $this->harness()->time()->advance(1 * self::SECOND);
        $backend->record($policy, $b, CircuitVerdict::Success, $this->ids()->command());
        $c = $this->permit($backend, $policy);
        self::assertSame([1, 3], $this->counters($registry));

        $this->permit($backend, $policy); // needs a record: the oldest terminal record (a's) is evicted
        self::assertSame([1, 3], $this->counters($registry));
        self::assertSame(FinalizationStatus::StaleOwnership, $backend->abandon($policy, $a, $this->ids()->command())->status);
        self::assertSame(FinalizationStatus::AlreadyFinalized, $backend->abandon($policy, $b, $this->ids()->command())->status);
        self::assertSame(FinalizationStatus::Applied, $backend->abandon($policy, $c, $this->ids()->command())->status);
    }

    public function testACeilingRejectionChangesNeitherStateNorCounters(): void
    {
        $registry = $this->harness()->registry(1, 10);
        $backend = $this->backend($registry);
        $first = ContractCircuitRig::countPolicy(minimum: 5, identity: ContractIdentities::identity('first'));
        $second = ContractCircuitRig::countPolicy(minimum: 5, identity: ContractIdentities::identity('second'));
        $held = $this->permit($backend, $first);

        try {
            $this->acquire($backend, $second);
            self::fail('Expected a capacity rejection.');
        } catch (StateCapacityExceededException $rejection) {
            self::assertStringContainsString('rt_capacity', $rejection->getMessage());
        }
        self::assertSame([1, 1], $this->counters($registry));
        self::assertSame(FinalizationStatus::Applied, $backend->record($first, $held, CircuitVerdict::Success, $this->ids()->command())->status);
    }

    public function testAFullTimeBucketIsRefusedBeforeAnyChange(): void
    {
        $registry = $this->harness()->registry();
        $backend = $this->backend($registry);
        $policy = ContractCircuitRig::timePolicy(minimum: 2);
        $permit = $this->permit($backend, $policy);

        // The bucket of the current second holds the largest sample count the contract allows.
        $client = $this->harness()->client();
        /** @var list<string> $partitions */
        $partitions = $client->keys($registry->keyPrefix . ':p:cb:*');
        $window = array_values(array_filter($partitions, static fn(string $key): bool => !str_ends_with($key, ':a')))[0] . ':w';
        $client->hset($window, '1893456100', '8796093022207 0');
        $before = $client->hgetall($window);

        try {
            $backend->record($policy, $permit, CircuitVerdict::Failure, $this->ids()->command());
            self::fail('Expected a capacity rejection.');
        } catch (StateCapacityExceededException) {
            self::assertSame($before, $client->hgetall($window));
        }
        self::assertSame(FinalizationStatus::Applied, $backend->abandon($policy, $permit, $this->ids()->command())->status, 'the permit is still active');
    }

    public function testEveryFieldOfAPermitIsPartOfItsOwnership(): void
    {
        $backend = $this->backend($this->harness()->registry());
        $policy = ContractCircuitRig::countPolicy(minimum: 5);
        $permit = $this->permit($backend, $policy);
        $instant = $permit->expiresAt->sharedInstant;
        self::assertNotNull($instant);

        $with = static fn(array $changes): CircuitPermit => new CircuitPermit(
            $permit->policy,
            $changes['incarnation'] ?? $permit->incarnation,
            $changes['generation'] ?? $permit->generation,
            $changes['attempt'] ?? $permit->attempt,
            $changes['owner'] ?? $permit->owner,
            $changes['kind'] ?? $permit->kind,
            $permit->lease,
            $changes['expiry'] ?? $permit->expiresAt,
        );
        $forgeries = [
            'incarnation' => $with(['incarnation' => IncarnationId::fromLowerHex(str_repeat('ab', 16))]),
            'generation' => $with(['generation' => new Generation($permit->generation->value + 1)]),
            'attempt' => $with(['attempt' => $this->ids()->attempt()]),
            'owner' => $with(['owner' => PermitOwnerId::fromLowerHex(str_repeat('cd', 16))]),
            'kind' => $with(['kind' => CircuitPermitKind::HalfOpenProbe]),
            'expiry microseconds' => $with(['expiry' => BackendExpiry::shared(new BackendInstant($instant->seconds, $instant->microseconds === 0 ? 1 : $instant->microseconds - 1))]),
            'expiry seconds' => $with(['expiry' => BackendExpiry::shared(new BackendInstant($instant->seconds + 1, $instant->microseconds))]),
        ];
        foreach ($forgeries as $name => $forged) {
            self::assertSame(FinalizationStatus::StaleOwnership, $backend->record($policy, $forged, CircuitVerdict::Failure, $this->ids()->command())->status, $name);
            self::assertSame(FinalizationStatus::StaleOwnership, $backend->abandon($policy, $forged, $this->ids()->command())->status, $name);
        }
        self::assertSame(FinalizationStatus::Applied, $backend->record($policy, $permit, CircuitVerdict::Success, $this->ids()->command())->status);
    }

    public function testTheSameAttemptReceivesTheSamePermitAgainAndAnotherAttemptIsRefused(): void
    {
        $registry = $this->harness()->registry();
        $backend = $this->backend($registry);
        $policy = ContractCircuitRig::countPolicy(minimum: 5);
        $attempt = $this->ids()->attempt();
        $owner = $this->ids()->owner();

        $first = $backend->acquire($policy, $attempt, $owner, $this->ids()->command())->permit;
        $this->harness()->time()->advance(1 * self::SECOND);
        $again = $backend->acquire($policy, $attempt, $owner, $this->ids()->command())->permit;
        self::assertNotNull($first);
        self::assertEquals($first, $again, 'the permit keeps its original expiry; nothing was extended or counted twice');
        self::assertSame([1, 1], $this->counters($registry));

        $this->expectException(InvalidConfigurationException::class);
        $backend->acquire($policy, $this->ids()->attempt(), $owner, $this->ids()->command());
    }

    public function testAPermitOfAnotherBackendKindIsStaleAndNothingIsStored(): void
    {
        $registry = $this->harness()->registry();
        $redis = $this->backend($registry);
        $policy = ContractCircuitRig::countPolicy(minimum: 5);
        $local = (new MemoryCircuitBackend(new SystemClock()))->acquire($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command())->permit;
        self::assertNotNull($local);

        self::assertSame(FinalizationStatus::StaleOwnership, $redis->record($policy, $local, CircuitVerdict::Failure, $this->ids()->command())->status);
        self::assertSame([0, 0], $this->counters($registry));
    }

    public function testAMissingRegistryIsUnavailableAndAForeignOneIsRefused(): void
    {
        $policy = ContractCircuitRig::countPolicy();
        $missing = new RedisRegistry('rt_c_missing' . bin2hex(random_bytes(4)), 'contract', 'a-host-managed-secret-of-32-bytes!!');
        try {
            $this->acquire($this->backend($missing), $policy);
            self::fail('Expected an unavailable backend.');
        } catch (StateUnavailableException) {
            self::assertSame([], $this->harness()->client()->keys($missing->keyPrefix . ':*'));
        }

        $real = $this->harness()->registry();
        $foreign = new RedisRegistry($real->keyPrefix, 'someone-else', 'a-host-managed-secret-of-32-bytes!!');
        $this->expectException(CorruptStateException::class);
        $this->acquire($this->backend($foreign), $policy);
    }

    public function testAnIdleClosedPartitionWithAnEmptyTimeWindowIsKeptUntilItHasBeenIdleLongEnough(): void
    {
        $registry = $this->harness()->registry();
        $backend = $this->backend($registry);
        $maintenance = new RedisRegistryMaintenance($this->harness()->options(), $registry);
        $policy = ContractCircuitRig::timePolicy(windowSeconds: 5, minimum: 2);

        $permit = $this->permit($backend, $policy);
        $backend->record($policy, $permit, CircuitVerdict::Failure, $this->ids()->command());
        $this->harness()->time()->advance(60 * self::SECOND); // the sample left the window, the record has not ended yet
        $maintenance->step();
        self::assertSame(1, $this->counters($registry)[0], 'idle for 60 s only');

        $this->harness()->time()->advance(61 * self::SECOND);
        $maintenance->step();
        $maintenance->step();
        self::assertSame([0, 0], $this->counters($registry));
    }
}
