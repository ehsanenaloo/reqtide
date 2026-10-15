<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State\Memory;

/**
 * The finalization tombstones of one backend in the order they were created. A tombstone lives for a fixed retention
 * from a non-decreasing time, so this order is also the order of their deadlines, and the oldest tombstone of the whole
 * registry is always at the head: eviction under capacity pressure takes work proportional to what it evicts, not to
 * the number of partitions.
 *
 * An entry is a pointer, not the record. A tombstone that expired or was replaced stays here as a stale entry until
 * eviction reaches it or {@see compact()} drops it; the owner of the record decides whether an entry is still live.
 *
 * @internal
 */
final class TombstoneQueue
{
    /** @var \SplQueue<array{string, string, int}> partition key, owner key, deadline */
    private \SplQueue $entries;

    public function __construct()
    {
        $this->entries = new \SplQueue();
    }

    public function size(): int
    {
        return count($this->entries);
    }

    public function push(string $partition, string $owner, int $deadline): void
    {
        $this->entries->enqueue([$partition, $owner, $deadline]);
    }

    /**
     * Takes entries from the head until `$count` tombstones were removed or the queue is empty.
     *
     * @param \Closure(string, string, int): bool $remove removes the tombstone if it still exists with that deadline and
     *                                                   returns true; returns false for a stale entry
     * @return int number of removed tombstones
     */
    public function evict(int $count, \Closure $remove): int
    {
        $evicted = 0;
        while ($evicted < $count && !$this->entries->isEmpty()) {
            [$partition, $owner, $deadline] = $this->entries->dequeue();
            if ($remove($partition, $owner, $deadline)) {
                ++$evicted;
            }
        }

        return $evicted;
    }

    /**
     * Drops stale entries once the queue is longer than twice the number of live records plus a small margin, so its
     * size stays proportional to the registry ceiling. The order of the live entries is kept. The work is linear in the
     * queue and happens at most once per `$liveBound` pushes.
     *
     * @param \Closure(string, string, int): bool $isLive
     */
    public function compact(\Closure $isLive, int $liveBound): void
    {
        if (count($this->entries) <= 2 * $liveBound + 64) {
            return;
        }
        $kept = new \SplQueue();
        foreach ($this->entries as $entry) {
            if ($isLive($entry[0], $entry[1], $entry[2])) {
                $kept->enqueue($entry);
            }
        }
        $this->entries = $kept;
    }
}
