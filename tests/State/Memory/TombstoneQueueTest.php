<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\State\Memory\TombstoneQueue;
use PHPUnit\Framework\TestCase;

final class TombstoneQueueTest extends TestCase
{
    public function testEvictionFollowsTheOrderOfInsertionAcrossPartitions(): void
    {
        $queue = new TombstoneQueue();
        $queue->push('p', 'a', 1);
        $queue->push('q', 'b', 3);
        $queue->push('p', 'c', 5);
        $order = [];

        $evicted = $queue->evict(10, static function (string $partition, string $owner, int $deadline) use (&$order): bool {
            $order[] = $partition . '/' . $owner . '@' . $deadline;

            return true;
        });

        self::assertSame(3, $evicted);
        self::assertSame(['p/a@1', 'q/b@3', 'p/c@5'], $order);
        self::assertSame(0, $queue->size());
    }

    public function testEvictionStopsAtTheRequestedCountAndKeepsTheRest(): void
    {
        $queue = new TombstoneQueue();
        foreach ([1, 2, 3] as $deadline) {
            $queue->push('p', 'o' . $deadline, $deadline);
        }

        self::assertSame(2, $queue->evict(2, static fn(): bool => true));
        self::assertSame(1, $queue->size());
        self::assertSame(0, (new TombstoneQueue())->evict(5, static fn(): bool => true));
    }

    public function testAnEntryWhoseTombstoneIsGoneIsSkippedAndDoesNotCountAsEvicted(): void
    {
        $queue = new TombstoneQueue();
        $queue->push('p', 'gone', 1);
        $queue->push('p', 'live', 2);
        $queue->push('q', 'gone', 3);
        $queue->push('q', 'live', 4);
        $removed = [];

        $evicted = $queue->evict(2, static function (string $partition, string $owner) use (&$removed): bool {
            if ($owner === 'gone') {
                return false;
            }
            $removed[] = $partition . '/' . $owner;

            return true;
        });

        self::assertSame(2, $evicted, 'the count is of removed tombstones');
        self::assertSame(['p/live', 'q/live'], $removed);
        self::assertSame(0, $queue->size());
    }

    public function testStaleEntriesAreDroppedWhenTheQueueOutgrowsTheLiveRecords(): void
    {
        $queue = new TombstoneQueue();
        for ($i = 0; $i < 300; ++$i) {
            $queue->push('p', 'o' . $i, $i);
        }
        $live = ['o290' => true, 'o295' => true];

        $queue->compact(static fn(string $partition, string $owner, int $deadline): bool => isset($live[$owner]), 10);

        self::assertSame(2, $queue->size());
        $order = [];
        $queue->evict(5, static function (string $partition, string $owner) use (&$order): bool {
            $order[] = $owner;

            return true;
        });
        self::assertSame(['o290', 'o295'], $order, 'compaction keeps the order');
    }

    public function testACompactionIsSkippedWhileTheQueueIsWithinItsBound(): void
    {
        $queue = new TombstoneQueue();
        for ($i = 0; $i < 20; ++$i) {
            $queue->push('p', 'o' . $i, $i);
        }
        $asked = 0;

        $queue->compact(static function () use (&$asked): bool {
            ++$asked;

            return false;
        }, 10);

        self::assertSame(0, $asked);
        self::assertSame(20, $queue->size());
    }

    public function testTheBoundIsExactlyTwiceTheLiveRecordsPlusSixtyFour(): void
    {
        $queue = new TombstoneQueue();
        for ($i = 0; $i < 84; ++$i) {
            $queue->push('p', 'o' . $i, $i);
        }
        $asked = 0;
        $isLive = static function () use (&$asked): bool {
            ++$asked;

            return false;
        };

        $queue->compact($isLive, 10);
        self::assertSame(0, $asked, 'at the bound nothing is dropped');
        self::assertSame(84, $queue->size());

        $queue->push('p', 'o84', 84);
        $queue->compact($isLive, 10);
        self::assertSame(85, $asked, 'one entry over the bound examines every entry');
        self::assertSame(0, $queue->size());
    }

    public function testACompactionKeepsEveryLiveEntryAndNothingElse(): void
    {
        $queue = new TombstoneQueue();
        for ($i = 0; $i < 100; ++$i) {
            $queue->push('p' . ($i % 3), 'o' . $i, $i);
        }

        $queue->compact(static fn(string $partition, string $owner, int $deadline): bool => $deadline % 10 === 0, 0);

        self::assertSame(10, $queue->size());
        $deadlines = [];
        $queue->evict(100, static function (string $partition, string $owner, int $deadline) use (&$deadlines): bool {
            $deadlines[] = $deadline;

            return true;
        });
        self::assertSame([0, 10, 20, 30, 40, 50, 60, 70, 80, 90], $deadlines);
    }
}
