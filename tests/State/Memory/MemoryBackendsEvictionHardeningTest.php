<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Admission\AdmissionStatus;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\RateLimitPolicy;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Policy\TokenBucketOptions;
use Enaxon\Reqtide\State\ConcurrencyAdmissionStatus;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\State\Memory\MemoryConcurrencyBackend;
use Enaxon\Reqtide\State\Memory\MemoryRateLimitBackend;
use Enaxon\Reqtide\State\Memory\MemoryRetryBudgetBackend;
use Enaxon\Reqtide\State\RetryBudgetFinalizationStatus;
use Enaxon\Reqtide\State\RetryReservationStatus;
use Enaxon\Reqtide\Tests\Support\FakeClock;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PolicyId;
use Enaxon\Reqtide\Value\PolicyIdentity;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\ProfileVersion;
use PHPUnit\Framework\TestCase;

/** Which finalization records survive capacity pressure, and how slots are given back, for the other three backends. */
final class MemoryBackendsEvictionHardeningTest extends TestCase
{
    private const SECOND = 1_000_000_000;

    public function testConcurrencyRoomFreedByAnElapsedTombstoneElsewhereSparesTheOtherProtectiveTombstones(): void
    {
        $clock = new FakeClock(1_000 * self::SECOND);
        $ids = new MemoryTestSupport();
        $backend = new MemoryConcurrencyBackend($clock, $ids, 10, 2);
        $policy = static fn(string $subject): ConcurrencyPolicy => new ConcurrencyPolicy(MemoryTestSupport::identity($subject), new PositiveInt(100), Duration::seconds(1_000));
        $acquire = static function (ConcurrencyPolicy $p) use ($backend, $ids) {
            $admission = $backend->acquire($p, $ids->owner(), $ids->command());
            self::assertSame(ConcurrencyAdmissionStatus::Accepted, $admission->status);
            self::assertNotNull($admission->permit);

            return $admission->permit;
        };
        $a = $policy('a');
        $b = $policy('b');
        $permitA = $acquire($a);
        $backend->release($a, $permitA, $ids->command()); // tombstone until +120 s
        $clock->now += 50 * self::SECOND;
        $permitB = $acquire($b);
        $backend->release($b, $permitB, $ids->command()); // tombstone until +170 s
        $clock->now += 75 * self::SECOND; // 125 s after a's release

        $acquire($policy('c'));

        self::assertSame(FinalizationStatus::AlreadyFinalized, $backend->release($b, $permitB, $ids->command())->status, 'b was not evicted: a had already elapsed');
    }

    public function testRetryBudgetRoomFreedByAnElapsedTombstoneElsewhereSparesTheOtherProtectiveTombstones(): void
    {
        $clock = new FakeClock(1_000 * self::SECOND);
        $ids = new MemoryTestSupport();
        $backend = new MemoryRetryBudgetBackend($clock, $ids, 10, 2);
        $policy = static fn(string $subject): RetryBudgetPolicy => new RetryBudgetPolicy(MemoryTestSupport::identity($subject), new PositiveInt(10), new PositiveInt(10), Duration::seconds(60), Duration::seconds(10));
        $reserve = static function (RetryBudgetPolicy $p) use ($backend, $ids) {
            $decision = $backend->reserve($p, $ids->attempt(), $ids->owner(), $ids->command());
            self::assertSame(RetryReservationStatus::Reserved, $decision->status);
            self::assertNotNull($decision->reservation);

            return $decision->reservation;
        };
        $a = $policy('a');
        $b = $policy('b');
        $backend->release($a, $reserve($a), $ids->command()); // tombstone until +120 s
        $clock->now += 50 * self::SECOND;
        $reservationB = $reserve($b);
        $backend->release($b, $reservationB, $ids->command()); // tombstone until +170 s
        $clock->now += 75 * self::SECOND; // 125 s after a's release

        $reserve($policy('c'));

        self::assertSame(RetryBudgetFinalizationStatus::AlreadyReleased, $backend->release($b, $reservationB, $ids->command())->status, 'b was not evicted: a had already elapsed');
    }

    public function testRetryBudgetFinalizationGivesBackTheSlotsOfTombstonesItPrunes(): void
    {
        $clock = new FakeClock(1_000 * self::SECOND);
        $ids = new MemoryTestSupport();
        $backend = new MemoryRetryBudgetBackend($clock, $ids, 10, 3);
        $policy = new RetryBudgetPolicy(MemoryTestSupport::identity('p'), new PositiveInt(10), new PositiveInt(10), Duration::seconds(60), Duration::seconds(1_000));
        $reserve = static function () use ($backend, $ids, $policy) {
            $decision = $backend->reserve($policy, $ids->attempt(), $ids->owner(), $ids->command());
            self::assertSame(RetryReservationStatus::Reserved, $decision->status);
            self::assertNotNull($decision->reservation);

            return $decision->reservation;
        };
        $backend->release($policy, $reserve(), $ids->command()); // one tombstone
        $second = $reserve(); // one pending reservation with a long lease
        $clock->now += 121 * self::SECOND; // the tombstone has elapsed

        $backend->release($policy, $second, $ids->command()); // prunes the old tombstone, creates its own

        $reserve();
        $reserve();
        $reserve(); // the third has to evict the tombstone of $second
        $this->expectException(StateCapacityExceededException::class);
        $reserve();
    }

    public function testRateLimitMaintenanceContinuesAtTheFirstRecordItHasNotYetExamined(): void
    {
        $clock = new AnchoredClock(500 * self::SECOND);
        $backend = new MemoryRateLimitBackend($clock);
        $commands = 0;
        $consume = static function (RateLimitPolicy $policy) use ($backend, &$commands): AdmissionStatus {
            return $backend->consume($policy, new PositiveInt(1), CommandId::fromLowerHex(Fixtures::hex(++$commands)))->status;
        };
        $policies = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $policies[$name] = new RateLimitPolicy(
                new PolicyIdentity(Fixtures::partition(), new ProfileVersion('v1'), new PolicyId($name)),
                new TokenBucketOptions(new PositiveInt(10), new PositiveInt(10), Duration::seconds(1)),
            );
            self::assertSame(AdmissionStatus::Accepted, $consume($policies[$name]));
        }
        $clock->now += 100 * self::SECOND;
        foreach (['a', 'b', 'e'] as $name) {
            $consume($policies[$name]); // touched again: not removable at +121 s
        }
        $clock->now += 21 * self::SECOND; // c and d have been idle for 121 s

        self::assertSame(0, $backend->maintain(2), 'a and b are examined first and kept');
        self::assertSame(2, $backend->maintain(2), 'c and d are examined next');
        self::assertSame(3, $backend->partitionCount());
    }
}
