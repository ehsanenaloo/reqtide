<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State\Memory;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;

/**
 * Registry ceilings and retention shared by the process-local backends.
 *
 * @internal
 */
final class MemoryLimits
{
    public const DEFAULT_MAX_PARTITIONS = 10_000;

    public const DEFAULT_MAX_RECORDS = 20_000;

    public const MAX_MAINTENANCE_BATCH = 128;

    /** Command/finalization tombstone retention: 120 seconds. */
    public const TOMBSTONE_RETENTION_NANOSECONDS = 120_000_000_000;

    private function __construct() {}

    public static function positive(int $value, string $name): int
    {
        if ($value < 1) {
            throw new InvalidConfigurationException($name . ' must be at least one.');
        }

        return $value;
    }

    /** A maintenance call examines and removes at most 128 records. */
    public static function batch(int $maxRecords): int
    {
        return min(self::MAX_MAINTENANCE_BATCH, self::positive($maxRecords, 'maxRecords'));
    }

    /** Retention deadline for a nonprotective tombstone; saturates so a terminal transition can never fail. */
    public static function saturatingAdd(int $nanoseconds, int $duration): int
    {
        return $nanoseconds > PHP_INT_MAX - $duration ? PHP_INT_MAX : $nanoseconds + $duration;
    }

    /**
     * Removes the expired prefix of a map whose values are nondecreasing deadlines (constant lease or retention
     * appended in nondecreasing effective time). An entry is expired at equality.
     *
     * @param array<string, int> $deadlines
     * @return int number of removed entries, at most $limit
     */
    public static function pruneExpired(array &$deadlines, int $now, int $limit): int
    {
        $removed = 0;
        while ($removed < $limit) {
            $first = array_key_first($deadlines);
            if ($first === null || $deadlines[$first] > $now) {
                break;
            }
            unset($deadlines[$first]);
            ++$removed;
        }

        return $removed;
    }

    /** Tombstones evicted per capacity-pressure event: amortizes the head scan, never more than 64 or 1/16 of the ceiling. */
    public static function evictionBatch(int $maxRecords): int
    {
        return max(1, min(64, intdiv($maxRecords, 16)));
    }

    /** Checked `now + duration`; overflow is a typed configuration failure, never a wrap. */
    public static function checkedAdd(int $nanoseconds, int $duration): int
    {
        if ($nanoseconds > PHP_INT_MAX - $duration) {
            throw new InvalidConfigurationException('Local monotonic arithmetic overflows int64.');
        }

        return $nanoseconds + $duration;
    }
}
