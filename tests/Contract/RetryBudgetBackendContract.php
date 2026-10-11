<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Contract;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\State\RetryBudgetBackend;
use Enaxon\Reqtide\State\RetryBudgetFinalizationStatus;
use Enaxon\Reqtide\State\RetryBudgetReservation;
use Enaxon\Reqtide\State\RetryReservationStatus;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\IncarnationId;
use Enaxon\Reqtide\Value\PermitOwnerId;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour every RetryBudgetBackend must show: reserve, commit and release arithmetic with the accrue-before-change
 * ordering, lease expiry at equality, first-commit-only dispatch authority, stale-token handling, exhaustion waits,
 * ceilings, clock regression and advisory inspection. Exact scaled balances are read through the harness observation
 * port; boundary expectations use the harness step.
 *
 * Intentionally backend-specific and therefore not here: reflection fingerprints of process memory, maintenance
 * budgets and counts, constructor argument checks and overflow at the top of the nanosecond range (process-local);
 * script and registry behaviour (shared).
 */
abstract class RetryBudgetBackendContract extends TestCase
{
    protected const SECOND = 1_000_000_000;

    private const START = 1_000 * self::SECOND;

    private const TOKEN = 1_000_000;

    private ?RetryBudgetHarness $harness = null;

    private ?ContractIdentities $identities = null;

    private ?RetryBudgetBackend $defaultBackend = null;

    abstract protected function createHarness(): RetryBudgetHarness;

    final protected function harness(): RetryBudgetHarness
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

    final protected function backend(): RetryBudgetBackend
    {
        return $this->defaultBackend ??= $this->harness()->retryBudget();
    }

    private function limited(int $partitions, int $records): RetryBudgetBackend
    {
        return $this->harness()->retryBudget($partitions, $records);
    }

    private function policy(int $capacity = 10, int $leaseSeconds = 10, string $subject = 'subject'): RetryBudgetPolicy
    {
        return new RetryBudgetPolicy(
            ContractIdentities::identity($subject),
            new PositiveInt($capacity),
            new PositiveInt(10),
            Duration::seconds(60),
            Duration::seconds($leaseSeconds),
        );
    }

    private function reserve(RetryBudgetBackend $backend, RetryBudgetPolicy $policy): RetryBudgetReservation
    {
        $decision = $backend->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
        self::assertSame(RetryReservationStatus::Reserved, $decision->status);
        self::assertNotNull($decision->reservation);

        return $decision->reservation;
    }

    /** @return array{int, int} balance and pending in scaled units */
    private function state(RetryBudgetBackend $backend, RetryBudgetPolicy $policy): array
    {
        $snapshot = $this->harness()->observeBudget($backend, $policy);
        self::assertNotNull($snapshot);

        return [$snapshot['balance'], $snapshot['pending']];
    }

    /**
     * Inspect, and prove at the same instant that the stored view did not change (the observation is the state as of
     * the current effective time, so it is compared before and after the same read, never across a clock move).
     */
    private function inspectWithoutDisturbance(RetryBudgetBackend $backend, RetryBudgetPolicy $policy): \Enaxon\Reqtide\State\RetryBudgetInspection
    {
        $before = $this->harness()->observeBudget($backend, $policy);
        $inspection = $backend->inspect($policy);
        self::assertSame($before, $this->harness()->observeBudget($backend, $policy), 'inspect() must not write');

        return $inspection;
    }

    public function testCapabilitiesMatchTheHarnessDeclaration(): void
    {
        $capabilities = $this->backend()->capabilities();
        self::assertSame($this->harness()->expectedScope(), $capabilities->scope);
        self::assertSame($this->harness()->expectsFiniteIoTimeout(), $capabilities->ioTimeout !== null);
        self::assertLessThanOrEqual($this->time()->step(), $capabilities->clockResolution->nanoseconds);
    }

    // ---- advisory inspection: public results and the observation port (the process-local fingerprint tests stay with the memory backend)

    public function testInspectOfAbsentStateReadsAsAFreshFullBucketWithoutAllocating(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(7);

        $inspection = $backend->inspect($policy);

        self::assertSame(7, $inspection->availableTokens->value);
        self::assertTrue($inspection->wouldReserve);
        self::assertNull($inspection->retryAfter);
        self::assertNull($this->harness()->observeBudget($backend, $policy), 'no partition was created');
    }

    public function testInspectAgreesWithReserveAndNeverDisturbsTheStateItReads(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(2, 120);
        $first = $this->reserve($backend, $policy);
        $this->reserve($backend, $policy);

        $none = $this->inspectWithoutDisturbance($backend, $policy);
        self::assertFalse($none->wouldReserve);
        self::assertSame(0, $none->availableTokens->value);
        self::assertNull($none->retryAfter, 'held reservations take all headroom: no wait can help');

        $backend->commit($policy, $first, $this->ids()->command());
        $wait = $this->inspectWithoutDisturbance($backend, $policy);
        self::assertFalse($wait->wouldReserve);
        self::assertSame(6 * self::SECOND, $wait->retryAfter?->nanoseconds);

        $this->time()->advance(6 * self::SECOND - $this->time()->step());
        $almost = $this->inspectWithoutDisturbance($backend, $policy);
        self::assertFalse($almost->wouldReserve);
        self::assertSame($this->time()->step(), $almost->retryAfter?->nanoseconds, 'never early and never late, like reserve');

        $this->time()->advance($this->time()->step());
        $ready = $this->inspectWithoutDisturbance($backend, $policy);
        self::assertTrue($ready->wouldReserve);
        self::assertSame(1, $ready->availableTokens->value);
        self::assertNull($ready->retryAfter);

        // Every read above left the stored view untouched at its own instant.
        self::assertSame(RetryReservationStatus::Reserved, $backend->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command())->status);
    }

    public function testInspectAppliesLeaseExpiryToTheViewOnly(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(1, 10);
        $this->reserve($backend, $policy);

        $this->time()->advance(9 * self::SECOND);
        self::assertFalse($backend->inspect($policy)->wouldReserve);

        $this->time()->advance(self::SECOND); // lease expired at equality
        $expired = $this->inspectWithoutDisturbance($backend, $policy);
        self::assertTrue($expired->wouldReserve, 'the expired reservation is refunded in the view');
        self::assertSame(1, $expired->availableTokens->value);
    }

    public function testInspectAndReserveAgreeAtEveryStepOfAMixedHistory(): void
    {
        $inspectedBackend = $this->harness()->retryBudget();
        $reservedBackend = $this->harness()->retryBudget();
        $policy = $this->policy(3, 4);
        /** @var list<array{RetryBudgetReservation, RetryBudgetReservation}> $held */
        $held = [];

        $steps = [0, 3, 1, 7, 2, 11, 5, 13, 1, 17, 4, 19, 29, 3, 23, 2, 31, 37, 1, 41];
        foreach ($steps as $index => $seconds) {
            $this->time()->advance($seconds * self::SECOND);

            $view = $inspectedBackend->inspect($policy);
            $decision = $reservedBackend->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());

            self::assertSame($decision->status === RetryReservationStatus::Reserved, $view->wouldReserve, 'step ' . $index);
            self::assertSame($decision->retryAfter?->nanoseconds, $view->retryAfter?->nanoseconds, 'step ' . $index);
            if ($decision->reservation !== null) {
                // Mirror the same mutation on the inspected twin so both histories stay identical.
                $twin = $this->reserve($inspectedBackend, $policy);
                $held[] = [$twin, $decision->reservation];
            }
            if ($index % 3 === 0 && $held !== []) {
                [$a, $b] = array_shift($held);
                self::assertSame(
                    $reservedBackend->commit($policy, $b, $this->ids()->command())->status,
                    $inspectedBackend->commit($policy, $a, $this->ids()->command())->status,
                );
            } elseif ($index % 3 === 1 && $held !== []) {
                [$a, $b] = array_shift($held);
                self::assertSame(
                    $reservedBackend->release($policy, $b, $this->ids()->command())->status,
                    $inspectedBackend->release($policy, $a, $this->ids()->command())->status,
                );
            }
        }
    }

    public function testInspectUnderAClockRegressionUsesTheProtectedEffectiveTime(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(2, 10);
        $first = $this->reserve($backend, $policy);
        $backend->commit($policy, $first, $this->ids()->command());
        $this->reserve($backend, $policy);

        $this->time()->moveTo(0); // regression: the watermark (t = 1000 s) wins
        $view = $this->inspectWithoutDisturbance($backend, $policy);
        self::assertFalse($view->wouldReserve, 'a regressed reading neither refills nor expires');
    }

    public function testRetryA03ReserveElapsedRefillReleaseMintsNoToken(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(10, 120);
        $reservation = $this->reserve($backend, $policy);
        self::assertSame([9 * self::TOKEN, self::TOKEN], $this->state($backend, $policy));

        $this->time()->advance(60 * self::SECOND); // ample refill while one token is pending
        self::assertSame([9 * self::TOKEN, self::TOKEN], $this->state($backend, $policy), 'capped at C-P, no minted token');

        $result = $backend->release($policy, $reservation, $this->ids()->command());
        self::assertSame(RetryBudgetFinalizationStatus::Released, $result->status);
        self::assertFalse($result->authorizesDispatch());
        self::assertSame([10 * self::TOKEN, 0], $this->state($backend, $policy));
    }

    public function testRetryA03ReserveThenCommitOnlyLaterRefillRestoresTheToken(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(10, 10);
        $reservation = $this->reserve($backend, $policy);

        $result = $backend->commit($policy, $reservation, $this->ids()->command());
        self::assertSame(RetryBudgetFinalizationStatus::Committed, $result->status);
        self::assertTrue($result->authorizesDispatch());
        self::assertSame([9 * self::TOKEN, 0], $this->state($backend, $policy));

        // 10 tokens per 60 s: exactly one token after 6 s, 999_999 scaled units one tick earlier.
        $this->time()->advance(6 * self::SECOND - $this->time()->step());
        self::assertSame([10 * self::TOKEN - 1, 0], $this->state($backend, $policy));
        $this->time()->advance($this->time()->step());
        self::assertSame([10 * self::TOKEN, 0], $this->state($backend, $policy));
    }

    public function testRetryA03With120sLeaseCommitAtT60ThenReserveAtT60LeavesEight(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(10, 120);
        $reservation = $this->reserve($backend, $policy);
        self::assertSame([9 * self::TOKEN, self::TOKEN], $this->state($backend, $policy));

        $this->time()->advance(60 * self::SECOND);
        self::assertSame(RetryBudgetFinalizationStatus::Committed, $backend->commit($policy, $reservation, $this->ids()->command())->status);
        self::assertSame([9 * self::TOKEN, 0], $this->state($backend, $policy));

        $this->reserve($backend, $policy);
        self::assertSame([8 * self::TOKEN, self::TOKEN], $this->state($backend, $policy), 'no retroactive refill');
    }

    public function testRetryA03TenSecondLeaseCommitAtT60IsExpiredAndRefundsOnlyPendingCost(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(10, 10);
        $reservation = $this->reserve($backend, $policy);

        $this->time()->advance(60 * self::SECOND);
        $result = $backend->commit($policy, $reservation, $this->ids()->command());
        self::assertSame(RetryBudgetFinalizationStatus::Expired, $result->status);
        self::assertFalse($result->authorizesDispatch());
        self::assertSame([10 * self::TOKEN, 0], $this->state($backend, $policy));

        // Recognised repeat stays Expired and never authorizes a send.
        self::assertSame(RetryBudgetFinalizationStatus::Expired, $backend->commit($policy, $reservation, $this->ids()->command())->status);
        self::assertSame(RetryBudgetFinalizationStatus::Expired, $backend->release($policy, $reservation, $this->ids()->command())->status);
        self::assertSame([10 * self::TOKEN, 0], $this->state($backend, $policy), 'no second refund');
    }

    public function testRetryA03LeaseExpiredAtEqualityIsExpired(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(10, 10);
        $reservation = $this->reserve($backend, $policy);

        $this->time()->advance(10 * self::SECOND - $this->time()->step());
        $copy = $this->reserve($backend, $policy);
        self::assertSame(RetryBudgetFinalizationStatus::Released, $backend->release($policy, $copy, $this->ids()->command())->status);

        $this->time()->advance($this->time()->step());
        self::assertSame(RetryBudgetFinalizationStatus::Expired, $backend->commit($policy, $reservation, $this->ids()->command())->status);
    }

    public function testRetryA03DuplicateCommitAndReleaseNeverAuthorizeASecondDispatch(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(10, 120);

        $committed = $this->reserve($backend, $policy);
        self::assertTrue($backend->commit($policy, $committed, $this->ids()->command())->authorizesDispatch());
        $duplicate = $backend->commit($policy, $committed, $this->ids()->command());
        self::assertSame(RetryBudgetFinalizationStatus::AlreadyCommitted, $duplicate->status);
        self::assertFalse($duplicate->authorizesDispatch());
        $releaseAfterCommit = $backend->release($policy, $committed, $this->ids()->command());
        self::assertSame(RetryBudgetFinalizationStatus::AlreadyCommitted, $releaseAfterCommit->status, 'stored disposition wins; no refund');
        self::assertSame([9 * self::TOKEN, 0], $this->state($backend, $policy));

        $released = $this->reserve($backend, $policy);
        self::assertSame(RetryBudgetFinalizationStatus::Released, $backend->release($policy, $released, $this->ids()->command())->status);
        $dupRelease = $backend->release($policy, $released, $this->ids()->command());
        self::assertSame(RetryBudgetFinalizationStatus::AlreadyReleased, $dupRelease->status);
        $commitAfterRelease = $backend->commit($policy, $released, $this->ids()->command());
        self::assertSame(RetryBudgetFinalizationStatus::AlreadyReleased, $commitAfterRelease->status);
        self::assertFalse($commitAfterRelease->authorizesDispatch());
        self::assertSame([9 * self::TOKEN, 0], $this->state($backend, $policy), 'no double refund');
    }

    public function testRetryA03WrongOwnerAttemptOrIncarnationIsStale(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(10, 120);
        $real = $this->reserve($backend, $policy);

        $wrongOwner = new RetryBudgetReservation($real->policy, $real->incarnation, $real->generation, $real->attempt, PermitOwnerId::fromLowerHex(Fixtures::hex(0xBEEF)), $real->lease, $real->expiresAt);
        $wrongAttempt = new RetryBudgetReservation($real->policy, $real->incarnation, $real->generation, $this->ids()->attempt(), $real->owner, $real->lease, $real->expiresAt);
        $wrongIncarnation = new RetryBudgetReservation($real->policy, IncarnationId::fromLowerHex(Fixtures::hex(0xF00D)), $real->generation, $real->attempt, $real->owner, $real->lease, $real->expiresAt);

        foreach ([$wrongOwner, $wrongAttempt, $wrongIncarnation] as $forged) {
            self::assertSame(RetryBudgetFinalizationStatus::StaleOwnership, $backend->commit($policy, $forged, $this->ids()->command())->status);
            self::assertSame(RetryBudgetFinalizationStatus::StaleOwnership, $backend->release($policy, $forged, $this->ids()->command())->status);
        }
        self::assertSame([9 * self::TOKEN, self::TOKEN], $this->state($backend, $policy), 'forged tokens changed nothing');
        self::assertTrue($backend->commit($policy, $real, $this->ids()->command())->authorizesDispatch());
    }

    public function testStateA05OldReservationAfterRecreationIsStale(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(10, 10);
        $old = $this->reserve($backend, $policy);
        $backend->release($policy, $old, $this->ids()->command());

        $this->time()->advance(121 * self::SECOND);
        $this->harness()->reclaimExpired(); // full bucket, no records, idle 120 s: deleted
        self::assertNull($this->harness()->observeBudget($backend, $policy));

        $new = $this->reserve($backend, $policy);
        self::assertSame($old->generation->value, $new->generation->value);
        self::assertFalse($old->incarnation->equals($new->incarnation));
        self::assertSame(RetryBudgetFinalizationStatus::StaleOwnership, $backend->commit($policy, $old, $this->ids()->command())->status);
        self::assertSame(RetryBudgetFinalizationStatus::StaleOwnership, $backend->release($policy, $old, $this->ids()->command())->status);
        self::assertSame([9 * self::TOKEN, self::TOKEN], $this->state($backend, $policy));
    }

    public function testExhaustionCarriesExactWaitOrNoneWhenOnlyPendingHeadroomIsLeft(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(2, 120);
        $first = $this->reserve($backend, $policy);
        $this->reserve($backend, $policy);

        $none = $backend->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
        self::assertSame(RetryReservationStatus::Exhausted, $none->status);
        self::assertNull($none->retryAfter, 'no refill can happen while held reservations take all headroom');

        $backend->commit($policy, $first, $this->ids()->command()); // (0, 1): headroom 1, deficit 1 token
        $wait = $backend->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
        self::assertSame(RetryReservationStatus::Exhausted, $wait->status);
        self::assertSame(6 * self::SECOND, $wait->retryAfter?->nanoseconds);

        $this->time()->advance(6 * self::SECOND - $this->time()->step());
        $almost = $backend->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
        self::assertSame(RetryReservationStatus::Exhausted, $almost->status);
        self::assertSame($this->time()->step(), $almost->retryAfter?->nanoseconds, 'wait is never early and never late');

        $this->time()->advance($this->time()->step());
        self::assertSame(RetryReservationStatus::Reserved, $backend->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command())->status);
    }

    public function testClockRegressionNeverRefillsOrExpires(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(2, 10);
        $first = $this->reserve($backend, $policy);
        $backend->commit($policy, $first, $this->ids()->command()); // (1, 0) at t=1000 s

        $this->time()->moveTo(0);
        $second = $this->reserve($backend, $policy); // (0, 1)
        self::assertSame([0, self::TOKEN], $this->state($backend, $policy));
        $exhausted = $backend->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
        self::assertSame(RetryReservationStatus::Exhausted, $exhausted->status);

        $this->time()->moveTo(1_005 * self::SECOND); // 5 s after the watermark, not 1005 s
        self::assertSame(RetryBudgetFinalizationStatus::Committed, $backend->commit($policy, $second, $this->ids()->command())->status);
        // 5 s * 10 tokens / 60 s = 0.8333.. token, floored in scaled units.
        self::assertSame([833_333, 0], $this->state($backend, $policy));
    }

    public function testStateA05PartitionCeilingRejectsNewAllocationButFinalizationSucceeds(): void
    {
        $backend = $this->limited(1, 100);
        $a = $this->policy(10, 120, 'a');
        $b = $this->policy(10, 120, 'b');
        $reservation = $this->reserve($backend, $a);

        try {
            $backend->reserve($b, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
            self::fail('A new partition beyond the ceiling must be rejected.');
        } catch (StateCapacityExceededException) {
        }

        self::assertNull($this->harness()->observeBudget($backend, $b));
        self::assertSame([9 * self::TOKEN, self::TOKEN], $this->state($backend, $a));
        self::assertSame(RetryBudgetFinalizationStatus::Committed, $backend->commit($a, $reservation, $this->ids()->command())->status);
    }

    public function testStateA05RecordCeilingNeverBlocksExistingFinalizationAndTombstonesAreReclaimed(): void
    {
        $backend = $this->limited(10, 1);
        $policy = $this->policy(10, 120);
        $held = $this->reserve($backend, $policy);

        try {
            $backend->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
            self::fail('A new record beyond the ceiling must be rejected.');
        } catch (StateCapacityExceededException) {
        }
        self::assertSame([9 * self::TOKEN, self::TOKEN], $this->state($backend, $policy), 'rejection changed no budget');

        self::assertSame(RetryBudgetFinalizationStatus::Committed, $backend->commit($policy, $held, $this->ids()->command())->status);
        self::assertSame(RetryBudgetFinalizationStatus::AlreadyCommitted, $backend->commit($policy, $held, $this->ids()->command())->status);

        // Still within tombstone retention: pressure evicts the nonprotective tombstone instead of rejecting.
        $this->time()->advance(60 * self::SECOND);
        $next = $this->reserve($backend, $policy);
        self::assertSame(RetryBudgetFinalizationStatus::StaleOwnership, $backend->commit($policy, $held, $this->ids()->command())->status, 'evicted tombstone => stale, never a second commit');

        // The pending reservation is protective: the only slot is now taken by it, so a further allocation is rejected.
        try {
            $backend->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
            self::fail('A pending reservation must never be evicted.');
        } catch (StateCapacityExceededException) {
        }
        self::assertSame(RetryBudgetFinalizationStatus::Released, $backend->release($policy, $next, $this->ids()->command())->status);

        $this->time()->advance(121 * self::SECOND);
        $this->reserve($backend, $policy);
    }

    public function testSettingsAreImmutablePerPolicyIdentity(): void
    {
        $backend = $this->backend();
        $this->reserve($backend, $this->policy(10));

        $this->expectException(InvalidConfigurationException::class);
        $backend->reserve($this->policy(11), $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
    }

    public function testInspectRejectsAPolicyBoundToDifferentSettings(): void
    {
        $backend = $this->backend();
        $this->reserve($backend, $this->policy(10, 10));

        $this->expectException(InvalidConfigurationException::class);
        $backend->inspect($this->policy(11, 10));
    }
}
