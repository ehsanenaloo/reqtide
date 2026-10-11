<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Contract;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\State\ConcurrencyAdmissionStatus;
use Enaxon\Reqtide\State\ConcurrencyBackend;
use Enaxon\Reqtide\State\ConcurrencyPermit;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\BackendClockKind;
use Enaxon\Reqtide\Value\BackendExpiry;
use Enaxon\Reqtide\Value\BackendInstant;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PermitOwnerId;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour every ConcurrencyBackend must show: bounded acquisition, owned idempotent release, expiry at equality,
 * incarnation checks, ceilings and clock regression. Boundary expectations use the harness step.
 *
 * Intentionally backend-specific and therefore not here: the local expiry value, maintenance budgets and counts,
 * constructor argument checks and overflow at the top of the nanosecond range (process-local); script and registry
 * behaviour (shared).
 */
abstract class ConcurrencyBackendContract extends TestCase
{
    protected const SECOND = 1_000_000_000;

    private const START = 100 * self::SECOND;

    private ?ConcurrencyHarness $harness = null;

    private ?ContractIdentities $identities = null;

    private ?ConcurrencyBackend $defaultBackend = null;

    abstract protected function createHarness(): ConcurrencyHarness;

    final protected function harness(): ConcurrencyHarness
    {
        if ($this->harness === null) {
            $this->harness = $this->createHarness();
            $this->harness->time()->moveTo(self::START);
        }

        return $this->harness;
    }

    final protected function time(): ControllableTime
    {
        return $this->harness()->time();
    }

    final protected function ids(): ContractIdentities
    {
        return $this->identities ??= new ContractIdentities();
    }

    final protected function backend(): ConcurrencyBackend
    {
        return $this->defaultBackend ??= $this->harness()->concurrency();
    }

    private function limited(int $partitions, int $records): ConcurrencyBackend
    {
        return $this->harness()->concurrency($partitions, $records);
    }

    private function policy(int $capacity = 1, int $leaseSeconds = 10, string $subject = 'subject'): ConcurrencyPolicy
    {
        return new ConcurrencyPolicy(ContractIdentities::identity($subject), new PositiveInt($capacity), Duration::seconds($leaseSeconds));
    }

    private function acquire(ConcurrencyBackend $backend, ConcurrencyPolicy $policy): ConcurrencyPermit
    {
        $admission = $backend->acquire($policy, $this->ids()->owner(), $this->ids()->command());
        self::assertSame(ConcurrencyAdmissionStatus::Accepted, $admission->status);
        self::assertNotNull($admission->permit);

        return $admission->permit;
    }

    /** An expiry of the same clock kind that lies far before the real one. */
    private function earlierExpiry(BackendExpiry $real): BackendExpiry
    {
        return match ($real->clockKind) {
            BackendClockKind::LocalMonotonic => BackendExpiry::local(1),
            BackendClockKind::SharedAuthority => BackendExpiry::shared(new BackendInstant(0, 1)),
        };
    }

    public function testCapabilitiesMatchTheHarnessDeclaration(): void
    {
        $capabilities = $this->backend()->capabilities();
        self::assertSame($this->harness()->expectedScope(), $capabilities->scope);
        self::assertSame($this->harness()->expectsFiniteIoTimeout(), $capabilities->ioTimeout !== null);
        self::assertLessThanOrEqual($this->time()->step(), $capabilities->clockResolution->nanoseconds);
    }

    public function testBulkA01AcquireIsBoundedAndReleaseFreesCapacity(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(2);
        $first = $this->acquire($backend, $policy);
        $second = $this->acquire($backend, $policy);

        self::assertFalse($first->owner->equals($second->owner));
        self::assertTrue($first->incarnation->equals($second->incarnation));

        $rejected = $backend->acquire($policy, $this->ids()->owner(), $this->ids()->command());
        self::assertSame(ConcurrencyAdmissionStatus::CapacityExhausted, $rejected->status);
        self::assertNull($rejected->permit);

        self::assertSame(FinalizationStatus::Applied, $backend->release($policy, $first, $this->ids()->command())->status);
        $this->acquire($backend, $policy);
    }

    public function testBulkA01DuplicateReleaseIsIdempotentAndNeverFreesTwice(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(1);
        $permit = $this->acquire($backend, $policy);

        self::assertSame(FinalizationStatus::Applied, $backend->release($policy, $permit, $this->ids()->command())->status);
        $replacement = $this->acquire($backend, $policy);
        self::assertSame(FinalizationStatus::AlreadyFinalized, $backend->release($policy, $permit, $this->ids()->command())->status);

        // The duplicate did not decrement the replacement permit.
        $blocked = $backend->acquire($policy, $this->ids()->owner(), $this->ids()->command());
        self::assertSame(ConcurrencyAdmissionStatus::CapacityExhausted, $blocked->status);
        self::assertSame(FinalizationStatus::Applied, $backend->release($policy, $replacement, $this->ids()->command())->status);
    }

    public function testBulkA01ExpiredAtEqualityIsStaleAndLateReleaseCannotDecrementNewerPermit(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(1, 10);
        $old = $this->acquire($backend, $policy);

        $this->time()->moveTo((100 + 10) * self::SECOND - $this->time()->step());
        $blocked = $backend->acquire($policy, $this->ids()->owner(), $this->ids()->command());
        self::assertSame(ConcurrencyAdmissionStatus::CapacityExhausted, $blocked->status, 'valid one tick before expiry');

        $this->time()->moveTo((100 + 10) * self::SECOND); // exactly at expiry: stale
        $newer = $this->acquire($backend, $policy);

        self::assertSame(FinalizationStatus::StaleOwnership, $backend->release($policy, $old, $this->ids()->command())->status);
        $stillBlocked = $backend->acquire($policy, $this->ids()->owner(), $this->ids()->command());
        self::assertSame(ConcurrencyAdmissionStatus::CapacityExhausted, $stillBlocked->status, 'newer permit untouched');
        self::assertSame(FinalizationStatus::Applied, $backend->release($policy, $newer, $this->ids()->command())->status);
    }

    public function testReleaseOfExpiredPermitAtEqualityIsStaleEvenWithoutNewerPermit(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(1, 10);
        $permit = $this->acquire($backend, $policy);

        $this->time()->moveTo((100 + 10) * self::SECOND);
        self::assertSame(FinalizationStatus::StaleOwnership, $backend->release($policy, $permit, $this->ids()->command())->status);
    }

    public function testWrongOwnerCannotRelease(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(1);
        $permit = $this->acquire($backend, $policy);
        $forged = new ConcurrencyPermit(
            $permit->policy,
            $permit->incarnation,
            $permit->generation,
            PermitOwnerId::fromLowerHex(Fixtures::hex(0xDEAD)),
            $permit->lease,
            $permit->expiresAt,
        );

        self::assertSame(FinalizationStatus::StaleOwnership, $backend->release($policy, $forged, $this->ids()->command())->status);
        $blocked = $backend->acquire($policy, $this->ids()->owner(), $this->ids()->command());
        self::assertSame(ConcurrencyAdmissionStatus::CapacityExhausted, $blocked->status);
    }

    public function testForgedExpiryIsStale(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(1);
        $permit = $this->acquire($backend, $policy);
        $forged = new ConcurrencyPermit($permit->policy, $permit->incarnation, $permit->generation, $permit->owner, $permit->lease, $this->earlierExpiry($permit->expiresAt));

        self::assertSame(FinalizationStatus::StaleOwnership, $backend->release($policy, $forged, $this->ids()->command())->status);
    }

    public function testStateA05RecreatedStateGetsNewIncarnationAndRejectsOldToken(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(1);
        $old = $this->acquire($backend, $policy);
        $backend->release($policy, $old, $this->ids()->command());

        $this->time()->advance(121 * self::SECOND);
        $this->harness()->reclaimExpired(); // tombstone elapsed, partition empty: deleted
        $new = $this->acquire($backend, $policy);

        self::assertSame(1, $new->generation->value);
        self::assertSame($old->generation->value, $new->generation->value, 'same generation, different incarnation');
        self::assertFalse($old->incarnation->equals($new->incarnation));

        // Old record/release after dedup expiry and partition recreation leaves the new protection intact.
        self::assertSame(FinalizationStatus::StaleOwnership, $backend->release($policy, $old, $this->ids()->command())->status);
        $blocked = $backend->acquire($policy, $this->ids()->owner(), $this->ids()->command());
        self::assertSame(ConcurrencyAdmissionStatus::CapacityExhausted, $blocked->status);
        self::assertSame(FinalizationStatus::Applied, $backend->release($policy, $new, $this->ids()->command())->status);
    }

    public function testStateA05DuplicateAfterTombstoneExpiryIsStaleNotDoubleRelease(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(1);
        $first = $this->acquire($backend, $policy);
        $backend->release($policy, $first, $this->ids()->command());
        $this->time()->advance(121 * self::SECOND);
        $second = $this->acquire($backend, $policy);

        self::assertSame(FinalizationStatus::StaleOwnership, $backend->release($policy, $first, $this->ids()->command())->status);
        self::assertSame(FinalizationStatus::Applied, $backend->release($policy, $second, $this->ids()->command())->status);
    }

    public function testStateA05PartitionCeilingRejectsNewAllocationButExistingCleanupSucceeds(): void
    {
        $backend = $this->limited(2, 100);
        $a = $this->policy(1, 10, 'a');
        $b = $this->policy(1, 10, 'b');
        $c = $this->policy(1, 10, 'c');
        $permitA = $this->acquire($backend, $a);
        $permitB = $this->acquire($backend, $b);

        try {
            $backend->acquire($c, $this->ids()->owner(), $this->ids()->command());
            self::fail('A new partition beyond the ceiling must be rejected.');
        } catch (StateCapacityExceededException) {
        }

        // Active protection was not evicted: both partitions still enforce capacity and still release.
        self::assertSame(ConcurrencyAdmissionStatus::CapacityExhausted, $backend->acquire($a, $this->ids()->owner(), $this->ids()->command())->status);
        self::assertSame(FinalizationStatus::Applied, $backend->release($a, $permitA, $this->ids()->command())->status);
        self::assertSame(FinalizationStatus::Applied, $backend->release($b, $permitB, $this->ids()->command())->status);
    }

    public function testStateA05RecordCeilingRejectsNewOwnerButReleaseNeedsNoAllocation(): void
    {
        $backend = $this->limited(10, 2);
        $policy = $this->policy(5);
        $first = $this->acquire($backend, $policy);
        $second = $this->acquire($backend, $policy);

        try {
            $backend->acquire($policy, $this->ids()->owner(), $this->ids()->command());
            self::fail('A new owner record beyond the ceiling must be rejected.');
        } catch (StateCapacityExceededException) {
        }

        // Terminal release converts the owner slot in place; it cannot hit the ceiling.
        self::assertSame(FinalizationStatus::Applied, $backend->release($policy, $first, $this->ids()->command())->status);
        self::assertSame(FinalizationStatus::Applied, $backend->release($policy, $second, $this->ids()->command())->status);
        self::assertSame(FinalizationStatus::AlreadyFinalized, $backend->release($policy, $first, $this->ids()->command())->status);

        // Tombstones are nonprotective: pressure evicts the OLDEST one (first's) instead of rejecting the allocation.
        $third = $this->acquire($backend, $policy);
        self::assertSame(FinalizationStatus::StaleOwnership, $backend->release($policy, $first, $this->ids()->command())->status);
        self::assertSame(FinalizationStatus::AlreadyFinalized, $backend->release($policy, $second, $this->ids()->command())->status);
        self::assertSame(FinalizationStatus::Applied, $backend->release($policy, $third, $this->ids()->command())->status);

        // After retention allocation also succeeds through bounded maintenance.
        $this->time()->advance(121 * self::SECOND);
        $this->acquire($backend, $policy);
    }

    public function testRejectedAllocationDoesNotMutateExistingState(): void
    {
        $backend = $this->limited(10, 1);
        $policy = $this->policy(5);
        $held = $this->acquire($backend, $policy);

        try {
            $backend->acquire($this->policy(5, 10, 'other'), $this->ids()->owner(), $this->ids()->command());
            self::fail('Expected capacity rejection.');
        } catch (StateCapacityExceededException) {
        }

        self::assertSame(FinalizationStatus::Applied, $backend->release($policy, $held, $this->ids()->command())->status);
    }

    public function testExpiredLeasesAreReclaimedByMaintenanceToAdmitNewAllocation(): void
    {
        $backend = $this->limited(10, 1);
        $policy = $this->policy(5, 10);
        $this->acquire($backend, $policy);

        $this->time()->advance(10 * self::SECOND); // lease expired (equality)
        $this->acquire($backend, $policy);
    }

    public function testClockRegressionNeverShortensALease(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(1, 10);
        $permit = $this->acquire($backend, $policy);

        $this->time()->moveTo(0); // regression
        $blocked = $backend->acquire($policy, $this->ids()->owner(), $this->ids()->command());
        self::assertSame(ConcurrencyAdmissionStatus::CapacityExhausted, $blocked->status);
        self::assertSame(FinalizationStatus::Applied, $backend->release($policy, $permit, $this->ids()->command())->status);
    }

    public function testClockRegressionNeverExpiresALeaseEarlyOnRecoveryEither(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(1, 10);
        $permit = $this->acquire($backend, $policy);

        $this->time()->moveTo(0);
        $this->harness()->reclaimExpired();
        $this->time()->moveTo(105 * self::SECOND);
        self::assertSame(FinalizationStatus::Applied, $backend->release($policy, $permit, $this->ids()->command())->status);
    }

    public function testDuplicateOwnerIdentityIsRejected(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(3);
        $owner = $this->ids()->owner();
        $backend->acquire($policy, $owner, $this->ids()->command());

        $this->expectException(InvalidConfigurationException::class);
        $backend->acquire($policy, $owner, $this->ids()->command());
    }

    public function testSettingsAreImmutablePerPolicyIdentity(): void
    {
        $backend = $this->backend();
        $this->acquire($backend, $this->policy(1));

        $this->expectException(InvalidConfigurationException::class);
        $backend->acquire($this->policy(2), $this->ids()->owner(), $this->ids()->command());
    }
}
