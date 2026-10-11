<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Internal\NumericLimits;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\State\Memory\MemoryRetryBudgetBackend;
use Enaxon\Reqtide\State\RetryBudgetReservation;
use Enaxon\Reqtide\State\RetryReservationStatus;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Tests\Support\FakeClock;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\TestCase;

final class MemoryRetryBudgetBackendTest extends TestCase
{
    private const SECOND = 1_000_000_000;

    private ?FakeClock $fakeClock = null;

    private ?MemoryTestSupport $identities = null;

    private function clock(): FakeClock
    {
        return $this->fakeClock ??= new FakeClock(1_000 * self::SECOND);
    }

    private function ids(): MemoryTestSupport
    {
        return $this->identities ??= new MemoryTestSupport();
    }

    private const TOKEN = 1_000_000;





    private function backend(int $partitions = 10_000, int $records = 20_000): MemoryRetryBudgetBackend
    {
        return new MemoryRetryBudgetBackend($this->clock(), $this->ids(), $partitions, $records);
    }

    private function policy(int $capacity = 10, int $leaseSeconds = 10, string $subject = 'subject'): RetryBudgetPolicy
    {
        return new RetryBudgetPolicy(
            MemoryTestSupport::identity($subject),
            new PositiveInt($capacity),
            new PositiveInt(10),
            Duration::seconds(60),
            Duration::seconds($leaseSeconds),
        );
    }

    private function reserve(MemoryRetryBudgetBackend $backend, RetryBudgetPolicy $policy): RetryBudgetReservation
    {
        $decision = $backend->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
        self::assertSame(RetryReservationStatus::Reserved, $decision->status);
        self::assertNotNull($decision->reservation);

        return $decision->reservation;
    }

    /** @return array{int, int} balance and pending in whole-token units (scaled) */
    private function state(MemoryRetryBudgetBackend $backend, RetryBudgetPolicy $policy): array
    {
        $snapshot = $backend->snapshot($policy);
        self::assertNotNull($snapshot);

        return [$snapshot['balance'], $snapshot['pending']];
    }

    public function testCapabilitiesAreProcessLocal(): void
    {
        self::assertSame(StateScope::ProcessLocal, $this->backend()->capabilities()->scope);
    }

    public function testLeaseOverflowIsRejectedBeforeMutation(): void
    {
        $backend = $this->backend();
        $policy = new RetryBudgetPolicy(MemoryTestSupport::identity(), new PositiveInt(2), new PositiveInt(1), Duration::seconds(60), Duration::nanoseconds(NumericLimits::MAX_DEADLINE_HORIZON_NANOSECONDS));
        $this->clock()->now = NumericLimits::MAX_DEADLINE_HORIZON_NANOSECONDS;

        try {
            $backend->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
            self::fail('Expiry overflow must be a typed failure.');
        } catch (InvalidConfigurationException) {
        }
        self::assertNull($backend->snapshot($policy));
    }

    public function testMaintenanceExpiresReservationsRefundsAndKeepsPendingValidOnes(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(10, 10);
        $this->reserve($backend, $policy);
        $this->clock()->now += 5 * self::SECOND;
        self::assertSame(0, $backend->maintain());
        self::assertSame([9 * self::TOKEN, self::TOKEN], $this->state($backend, $policy), 'valid reservation untouched');

        $this->clock()->now += 5 * self::SECOND; // expired at equality
        $backend->maintain();
        self::assertSame([10 * self::TOKEN, 0], $this->state($backend, $policy));
        self::assertNotNull($backend->snapshot($policy), 'tombstone retention keeps the partition for 120 s');

        $this->clock()->now += 121 * self::SECOND;
        self::assertSame(1, $backend->maintain());
        $backend->maintain();
        self::assertNull($backend->snapshot($policy));
    }

    public function testMaintenanceNeverDeletesPartitionHoldingSpentBudget(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(10, 10);
        $reservation = $this->reserve($backend, $policy);
        $backend->commit($policy, $reservation, $this->ids()->command());

        $this->clock()->now += 3 * self::SECOND; // still 0.5 token short of full, tombstone may elapse later
        $backend->maintain();
        $this->clock()->now += 2 * self::SECOND;
        $backend->maintain();
        self::assertNotNull($backend->snapshot($policy));
    }

    public function testMaintenanceRejectsNonPositiveBatch(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->backend()->maintain(0);
    }

    /** Everything inspect() must leave alone: partitions, record count, rotation queue and the time watermark. */
    private function stateFingerprint(MemoryRetryBudgetBackend $backend): string
    {
        $reflection = new \ReflectionObject($backend);
        $read = static fn(string $name): mixed => $reflection->getProperty($name)->getValue($backend);
        $time = $read('time');
        self::assertIsObject($time);
        $watermark = (new \ReflectionObject($time))->getProperty('watermark')->getValue($time);
        $rotation = $read('rotation');
        self::assertInstanceOf(\SplQueue::class, $rotation);

        return serialize([$read('partitions'), $read('records'), iterator_to_array($rotation, false), $watermark]);
    }

    public function testInspectOfAbsentStateReadsAsAFreshFullBucketWithoutAllocating(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(7);
        $before = $this->stateFingerprint($backend);

        $inspection = $backend->inspect($policy);

        self::assertSame(7, $inspection->availableTokens->value);
        self::assertTrue($inspection->wouldReserve);
        self::assertNull($inspection->retryAfter);
        self::assertSame($before, $this->stateFingerprint($backend));
        self::assertNull($backend->snapshot($policy), 'no partition was created');
    }

    public function testInspectAgreesWithReserveAndNeverDisturbsTheStateItReads(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(2, 120);
        $first = $this->reserve($backend, $policy);
        $this->reserve($backend, $policy);

        $none = $backend->inspect($policy);
        self::assertFalse($none->wouldReserve);
        self::assertSame(0, $none->availableTokens->value);
        self::assertNull($none->retryAfter, 'held reservations take all headroom: no wait can help');

        $backend->commit($policy, $first, $this->ids()->command());
        $before = $this->stateFingerprint($backend);
        $wait = $backend->inspect($policy);
        self::assertFalse($wait->wouldReserve);
        self::assertSame(6 * self::SECOND, $wait->retryAfter?->nanoseconds);

        $this->clock()->now += 6 * self::SECOND - 1;
        $almost = $backend->inspect($policy);
        self::assertFalse($almost->wouldReserve);
        self::assertSame(1, $almost->retryAfter?->nanoseconds, 'never early and never late, like reserve');

        $this->clock()->now += 1;
        $ready = $backend->inspect($policy);
        self::assertTrue($ready->wouldReserve);
        self::assertSame(1, $ready->availableTokens->value);
        self::assertNull($ready->retryAfter);

        // The clock moved, yet nothing was accrued, expired or stored by any of the reads.
        self::assertSame($before, $this->stateFingerprint($backend));
        self::assertSame(RetryReservationStatus::Reserved, $backend->reserve($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command())->status);
    }

    public function testInspectAppliesLeaseExpiryToLocalVariablesOnly(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(1, 10);
        $this->reserve($backend, $policy);

        $this->clock()->now += 9 * self::SECOND;
        self::assertFalse($backend->inspect($policy)->wouldReserve);

        $this->clock()->now += self::SECOND; // lease expired at equality
        $before = $this->stateFingerprint($backend);
        $expired = $backend->inspect($policy);
        self::assertTrue($expired->wouldReserve, 'the expired reservation is refunded in the view');
        self::assertSame(1, $expired->availableTokens->value);
        self::assertSame($before, $this->stateFingerprint($backend), 'but no tombstone or balance change is written');
    }

    public function testInspectAndReserveAgreeAtEveryStepOfAMixedHistory(): void
    {
        $clock = new FakeClock(1_000 * self::SECOND);
        $inspectedBackend = new MemoryRetryBudgetBackend($clock, $this->ids());
        $reservedClock = new FakeClock(1_000 * self::SECOND);
        $reservedBackend = new MemoryRetryBudgetBackend($reservedClock, $this->ids());
        $policy = $this->policy(3, 4);
        /** @var list<array{RetryBudgetReservation, RetryBudgetReservation}> $held */
        $held = [];

        $steps = [0, 3, 1, 7, 2, 11, 5, 13, 1, 17, 4, 19, 29, 3, 23, 2, 31, 37, 1, 41];
        foreach ($steps as $index => $seconds) {
            $clock->now += $seconds * self::SECOND;
            $reservedClock->now += $seconds * self::SECOND;

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

        $this->clock()->now = 0; // regression: the watermark (t = 1000 s) wins
        $before = $this->stateFingerprint($backend);
        $view = $backend->inspect($policy);
        self::assertFalse($view->wouldReserve, 'a regressed reading neither refills nor expires');
        self::assertSame($before, $this->stateFingerprint($backend));
    }

}
