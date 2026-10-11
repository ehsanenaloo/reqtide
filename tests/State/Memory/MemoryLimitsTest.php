<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\State\Memory\MemoryLimits;
use PHPUnit\Framework\TestCase;

/** The shared registry arithmetic: ceilings, batches, saturating and checked time addition, expiry and eviction order. */
final class MemoryLimitsTest extends TestCase
{
    public function testPositiveAcceptsOneAndNamesTheOffendingSettingOtherwise(): void
    {
        self::assertSame(1, MemoryLimits::positive(1, 'maxRecords'));
        foreach ([0, -1] as $bad) {
            try {
                MemoryLimits::positive($bad, 'maxPartitions');
                self::fail('A ceiling of ' . $bad . ' must be rejected.');
            } catch (InvalidConfigurationException $e) {
                self::assertSame('maxPartitions must be at least one.', $e->getMessage());
            }
        }
    }

    public function testBatchIsCappedAtTheMaintenanceMaximum(): void
    {
        self::assertSame(1, MemoryLimits::batch(1));
        self::assertSame(127, MemoryLimits::batch(127));
        self::assertSame(128, MemoryLimits::batch(128));
        self::assertSame(128, MemoryLimits::batch(129));
        self::assertSame(128, MemoryLimits::batch(PHP_INT_MAX));
    }

    public function testSaturatingAddSaturatesOnlyBeyondTheLargestTick(): void
    {
        self::assertSame(15, MemoryLimits::saturatingAdd(10, 5));
        self::assertSame(PHP_INT_MAX, MemoryLimits::saturatingAdd(PHP_INT_MAX - 5, 5), 'exactly the largest tick is not saturation');
        self::assertSame(PHP_INT_MAX, MemoryLimits::saturatingAdd(PHP_INT_MAX - 4, 5));
        self::assertSame(PHP_INT_MAX - 1, MemoryLimits::saturatingAdd(PHP_INT_MAX - 6, 5));
        self::assertSame(PHP_INT_MAX, MemoryLimits::saturatingAdd(PHP_INT_MAX, 1));
        self::assertSame(PHP_INT_MAX, MemoryLimits::saturatingAdd(0, PHP_INT_MAX));
    }

    public function testCheckedAddFailsOnlyWhenTheSumWouldOverflow(): void
    {
        self::assertSame(PHP_INT_MAX, MemoryLimits::checkedAdd(PHP_INT_MAX - 5, 5));
        self::assertSame(PHP_INT_MAX, MemoryLimits::checkedAdd(0, PHP_INT_MAX));
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('overflows int64');
        MemoryLimits::checkedAdd(PHP_INT_MAX - 4, 5);
    }

    public function testPruneExpiredRemovesTheExpiredPrefixAtEqualityAndHonoursTheLimit(): void
    {
        $deadlines = ['a' => 10, 'b' => 20, 'c' => 20, 'd' => 30];
        self::assertSame(1, MemoryLimits::pruneExpired($deadlines, 10, 100), 'expired at equality');
        self::assertSame(['b' => 20, 'c' => 20, 'd' => 30], $deadlines);

        self::assertSame(1, MemoryLimits::pruneExpired($deadlines, 20, 1), 'the limit stops the sweep');
        self::assertSame(['c' => 20, 'd' => 30], $deadlines);

        self::assertSame(0, MemoryLimits::pruneExpired($deadlines, 20, 0), 'a zero limit removes nothing');
        self::assertSame(1, MemoryLimits::pruneExpired($deadlines, 29, 100), 'stops at the first live entry');
        self::assertSame(['d' => 30], $deadlines);
        self::assertSame(0, MemoryLimits::pruneExpired($deadlines, 29, 100));
        $empty = [];
        self::assertSame(0, MemoryLimits::pruneExpired($empty, PHP_INT_MAX, 100));
    }

    public function testEvictionBatchIsOneSixteenthOfTheCeilingBetweenOneAndSixtyFour(): void
    {
        self::assertSame(1, MemoryLimits::evictionBatch(1));
        self::assertSame(1, MemoryLimits::evictionBatch(31));
        self::assertSame(2, MemoryLimits::evictionBatch(32));
        self::assertSame(2, MemoryLimits::evictionBatch(47));
        self::assertSame(3, MemoryLimits::evictionBatch(48));
        self::assertSame(63, MemoryLimits::evictionBatch(63 * 16));
        self::assertSame(64, MemoryLimits::evictionBatch(64 * 16));
        self::assertSame(64, MemoryLimits::evictionBatch(65 * 16));
        self::assertSame(64, MemoryLimits::evictionBatch(20_000));
    }

    public function testEvictOldestTakesTheGloballyOldestHeadsAndRequeuesTheNextHeadOfThePartitionItCameFrom(): void
    {
        // Partition p holds tombstones expiring at 1 and 5; q holds one expiring at 3. Oldest first: p@1, q@3, p@5.
        $remaining = ['p' => [1, 5], 'q' => [3]];
        $order = [];
        $evict = static function (string $key) use (&$remaining, &$order): ?int {
            $deadline = array_shift($remaining[$key]);
            $order[] = $key . '@' . $deadline;

            return $remaining[$key][0] ?? null;
        };

        self::assertSame(3, MemoryLimits::evictOldest(['p' => 1, 'q' => 3], $evict, 10));
        self::assertSame(['p@1', 'q@3', 'p@5'], $order);
    }

    public function testEvictOldestStopsAtTheRequestedCount(): void
    {
        $remaining = ['p' => [1, 2, 3]];
        $evict = static function (string $key) use (&$remaining): ?int {
            array_shift($remaining[$key]);

            return $remaining[$key][0] ?? null;
        };

        self::assertSame(2, MemoryLimits::evictOldest(['p' => 1], $evict, 2));
        self::assertSame([3], $remaining['p']);
        self::assertSame(0, MemoryLimits::evictOldest([], $evict, 5));
    }
}
