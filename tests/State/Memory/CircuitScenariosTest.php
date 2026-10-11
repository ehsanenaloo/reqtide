<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\CircuitPermitKind;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\State\Memory\CircuitPartition;
use Enaxon\Reqtide\State\Memory\ExactProduct;
use Enaxon\Reqtide\State\Memory\MemoryCircuitBackend;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Value\Generation;
use PHPUnit\Framework\TestCase;

/** Thresholds and windows, probes, stale and duplicate finalizations, retention. */
final class CircuitScenariosTest extends TestCase
{
    private const S = CircuitRig::SECOND;

    private static function failure(): CircuitVerdict
    {
        return CircuitVerdict::Failure;
    }

    private static function success(): CircuitVerdict
    {
        return CircuitVerdict::Success;
    }

    public function testCapabilitiesDescribeProcessLocalState(): void
    {
        $capabilities = (new CircuitRig())->backend->capabilities();

        self::assertSame(StateScope::ProcessLocal, $capabilities->scope);
        self::assertNull($capabilities->ioTimeout);
        self::assertSame(1, $capabilities->clockResolution->nanoseconds);
    }

    // Thresholds and windows -----------------------------------------------------------------------------------

    public function testTimeWindowBucketCeilingFailsBeforeAnyHealthMutation(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::timePolicy(minimum: 1, ratioNumerator: 1, ratioDenominator: 1);
        $rig->at(5 * self::S);
        $rig->complete($policy, self::success());
        $permit = $rig->permit($policy);

        $part = $this->onlyPartition($rig->backend);
        $buckets = new \ReflectionProperty(CircuitPartition::class, 'buckets');
        $buckets->setValue($part, [5 => [8_796_093_022_207, 0]]);

        try {
            $rig->record($policy, $permit, self::failure());
            self::fail('Expected StateCapacityExceededException.');
        } catch (StateCapacityExceededException) {
            // Ownership cleanup happened even though the sample was refused.
        }
        self::assertSame(FinalizationStatus::AlreadyFinalized, $rig->record($policy, $permit, self::failure()));
        self::assertSame(CircuitAdmissionStatus::Accepted, $rig->status($policy), 'no failure was counted');
        self::assertSame([5 => [8_796_093_022_207, 0]], $buckets->getValue($part));
    }

    public function testExactProductComparisonHasNoOverflow(): void
    {
        $max = 9_007_199_254_740_991;
        self::assertSame(0, ExactProduct::compare($max, $max, $max, $max));
        self::assertSame(1, ExactProduct::compare($max, $max, $max, $max - 1));
        self::assertSame(-1, ExactProduct::compare($max - 1, $max, $max, $max));
        self::assertSame(1, ExactProduct::compare(PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX - 1));
        self::assertSame(0, ExactProduct::compare(10, 2, 20, 1));
        self::assertSame(0, ExactProduct::compare(0, $max, 0, 1));
        self::assertSame(1, ExactProduct::compare(1, 1, 0, $max));
    }

    // Probes -----------------------------------------------------------------------------------

    private function trip(CircuitRig $rig, \Enaxon\Reqtide\Policy\CircuitPolicy $policy): void
    {
        $rig->complete($policy, self::failure());
        $rig->complete($policy, self::failure());
        self::assertSame(CircuitAdmissionStatus::Open, $rig->status($policy));
    }

    public function testManualResetClosesAndAdvancesGeneration(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::countPolicy(minimum: 2);
        $stale = $rig->permit($policy);
        $this->trip($rig, $policy);
        $rig->advance(30 * self::S);
        $probe = $rig->permit($policy);
        $before = $probe->generation->value;

        $after = $rig->backend->reset($policy->identity);
        self::assertNotNull($after);
        self::assertSame($before + 1, $after->value);
        self::assertSame(FinalizationStatus::StaleOwnership, $rig->record($policy, $probe, self::success()));
        self::assertSame(FinalizationStatus::StaleOwnership, $rig->record($policy, $stale, self::failure()));
        $closed = $rig->permit($policy);
        self::assertSame(CircuitPermitKind::ClosedSample, $closed->kind);
        self::assertSame($after->value, $closed->generation->value);
        self::assertNull($rig->backend->reset(CircuitRig::identity('unknown')));
    }

    // Stale and duplicate finalizations -----------------------------------------------------------------------------------

    public function testRecreatedPartitionHasANewIncarnationAndRejectsOldPermits(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::timePolicy(minimum: 2);
        $old = $rig->permit($policy);
        $rig->record($policy, $old, CircuitVerdict::Neutral);
        $rig->advance(121 * self::S);
        self::assertGreaterThan(0, $rig->backend->maintain());

        $fresh = $rig->permit($policy);
        self::assertSame(1, $fresh->generation->value);
        self::assertSame(1, $old->generation->value);
        self::assertFalse($fresh->incarnation->equals($old->incarnation));
        self::assertSame(FinalizationStatus::StaleOwnership, $rig->record($policy, $old, self::failure()));
        self::assertSame(FinalizationStatus::StaleOwnership, $rig->abandon($policy, $old));
        self::assertSame(FinalizationStatus::Applied, $rig->record($policy, $fresh, self::success()));
    }

    // Retention and bounds ------------------------------------------------------------------------

    public function testCountHistorySurvivesIdlenessAndMaintenance(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::countPolicy(window: 4, minimum: 4, ratioNumerator: 1, ratioDenominator: 2);
        $rig->complete($policy, self::failure());
        $rig->complete($policy, self::failure());
        $rig->complete($policy, self::success());

        $rig->advance(3_600 * self::S);
        for ($i = 0; $i < 5; ++$i) {
            $rig->backend->maintain();
        }
        $rig->complete($policy, self::success()); // 2 failures in 4 samples

        self::assertSame(CircuitAdmissionStatus::Open, $rig->status($policy));
    }

    public function testOpenAndHalfOpenStateNeverExpiresFromInactivity(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::countPolicy(minimum: 2);
        $this->trip($rig, $policy);
        $rig->advance(60 * self::S);
        $rig->backend->maintain();
        $probe = $rig->permit($policy); // half open
        $rig->record($policy, $probe, CircuitVerdict::Neutral);
        $rig->advance(86_400 * self::S);
        $rig->backend->maintain();

        $next = $rig->acquire($policy);
        self::assertSame(CircuitAdmissionStatus::Accepted, $next->status);
        self::assertSame(CircuitPermitKind::HalfOpenProbe, $next->permit?->kind, 'still HalfOpen, never silently closed');
    }

    public function testIdleEmptyTimeWindowPartitionIsRetiredAfterRetention(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::timePolicy(minimum: 2);
        $rig->complete($policy, self::failure());
        self::assertCount(1, $this->partitions($rig->backend));

        $rig->advance(60 * self::S);
        $rig->backend->maintain();
        self::assertCount(1, $this->partitions($rig->backend), 'tombstone and idle retention not elapsed');
        $rig->advance(61 * self::S);
        $rig->backend->maintain();
        self::assertCount(0, $this->partitions($rig->backend));
    }

    public function testPartitionCeilingRejectsNewPartitionsAndNeverEvictsProtectiveState(): void
    {
        $rig = new CircuitRig(maxPartitions: 2);
        $a = CircuitRig::countPolicy(minimum: 2, identity: CircuitRig::identity('a'));
        $b = CircuitRig::countPolicy(minimum: 2, identity: CircuitRig::identity('b'));
        $c = CircuitRig::countPolicy(minimum: 2, identity: CircuitRig::identity('c'));
        $this->trip($rig, $a);
        $rig->complete($b, self::failure());

        $rig->advance(7_200 * self::S);
        $rig->backend->maintain();
        try {
            $rig->acquire($c);
            self::fail('Expected StateCapacityExceededException.');
        } catch (StateCapacityExceededException) {
            self::assertCount(2, $this->partitions($rig->backend));
        }
        self::assertSame(CircuitAdmissionStatus::Accepted, $rig->status($a), 'Open partition survived and is HalfOpen now');
        // b held count-window history, so it was not evicted either.
        self::assertCount(2, $this->partitions($rig->backend));
    }

    public function testGenerationExhaustionFailsWithoutWrapping(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::countPolicy(minimum: 2);
        $rig->permit($policy);
        $part = $this->onlyPartition($rig->backend);
        $part->generation = new Generation(Generation::MAX);

        $this->expectException(CorruptStateException::class);
        $this->trip($rig, $policy);
    }

    public function testMaintenanceIsBoundedAndRejectsInvalidBudgets(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::countPolicy(window: 2000, minimum: 1000);
        for ($i = 0; $i < 300; ++$i) {
            $rig->permit($policy);
        }
        $rig->advance(10 * self::S);

        self::assertSame(127, $rig->backend->maintain(), 'one budget unit examines the partition');
        $total = 127;
        for ($i = 0; $i < 10; ++$i) {
            $removed = $rig->backend->maintain();
            self::assertLessThanOrEqual(128, $removed);
            $total += $removed;
        }
        self::assertSame(300, $total, 'every expired owner was removed, in bounded batches');

        foreach ([0, -1, 129] as $bad) {
            try {
                $rig->backend->maintain($bad);
                self::fail('Expected InvalidConfigurationException.');
            } catch (InvalidConfigurationException) {
            }
        }
    }

    /** @return array<string, CircuitPartition> */
    private function partitions(MemoryCircuitBackend $backend): array
    {
        $value = (new \ReflectionProperty(MemoryCircuitBackend::class, 'partitions'))->getValue($backend);
        self::assertIsArray($value);
        $result = [];
        foreach ($value as $key => $partition) {
            self::assertIsString($key);
            self::assertInstanceOf(CircuitPartition::class, $partition);
            $result[$key] = $partition;
        }

        return $result;
    }

    private function onlyPartition(MemoryCircuitBackend $backend): CircuitPartition
    {
        $all = array_values($this->partitions($backend));
        self::assertCount(1, $all);

        return $all[0];
    }
}
