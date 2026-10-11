<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Testing;

use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Time\Clock;
use Enaxon\Reqtide\Value\Duration;

/**
 * Deterministic Clock whose monotonic and wall readings change only when a test says so.
 *
 * The wall reading is independent: advance() moves both domains, while advanceMonotonicOnly() and
 * setWall() model wall jumps (NTP step, DST, manual change) that the monotonic domain must not follow.
 *
 * Starting point. A new clock reads `DEFAULT_WALL_START` (2026-01-01T00:00:00+00:00, UTC) on the wall and 1 second
 * on the monotonic domain unless told otherwise. The default is a fixed, realistic calendar date, never the
 * real current time, because a test must give the same result tomorrow; it is also never 1970, so
 * date arithmetic that assumes "recent" data behaves normally. Anything that is anchored to wall time, such as a
 * `ReplaySafetyContract`, must be created from THIS clock (`ReplaySafetyContract::forReadOnlyCallable(..., clock:
 * $clock)`) or from a time that the clock was started at (`startingAt()`): a contract made from the real
 * clock lies in the virtual clock's future or past and is not valid.
 *
 * @api-for-tests
 */
final class VirtualClock implements Clock
{
    /** Wall time of a clock that was not given one (UTC). Fixed on purpose; see the class comment. */
    public const DEFAULT_WALL_START = '2026-01-01T00:00:00+00:00';

    private int $monotonic;
    private \DateTimeImmutable $wall;
    private int $failMonotonicReads = 0;
    private int $failWallReads = 0;
    private int $monotonicReadCount = 0;
    private int $wallReadCount = 0;

    public function __construct(int $initialMonotonicNanoseconds = 1_000_000_000, ?\DateTimeImmutable $initialWall = null)
    {
        if ($initialMonotonicNanoseconds < 0) {
            throw new \InvalidArgumentException('The initial monotonic reading must not be negative.');
        }
        $this->monotonic = $initialMonotonicNanoseconds;
        $this->wall = $initialWall ?? new \DateTimeImmutable(self::DEFAULT_WALL_START);
    }

    /**
     * A new clock with the same monotonic reading as this one and the given wall start. It is a configuration
     * step for the beginning of a test: `(new VirtualClock())->startingAt($someInstant)`. Failure injection and
     * read counters of this clock are not carried over.
     */
    public function startingAt(\DateTimeImmutable $wall): self
    {
        return new self($this->monotonic, $wall);
    }

    /**
     * @phpstan-impure
     * @throws ClockFailureException while a failure injected with failNextMonotonicReads() is pending
     */
    public function monotonicNow(): int
    {
        ++$this->monotonicReadCount;
        if ($this->failMonotonicReads > 0) {
            --$this->failMonotonicReads;

            throw new ClockFailureException('Injected monotonic clock failure.');
        }

        return $this->monotonic;
    }

    /**
     * @phpstan-impure
     * @throws ClockFailureException while a failure injected with failNextWallReads() is pending
     */
    public function wallNow(): \DateTimeImmutable
    {
        ++$this->wallReadCount;
        if ($this->failWallReads > 0) {
            --$this->failWallReads;

            throw new ClockFailureException('Injected wall clock failure.');
        }

        return $this->wall;
    }

    /** Moves monotonic and wall time forward by the same amount (wall at microsecond resolution). */
    public function advance(Duration $duration): void
    {
        $this->advanceMonotonicOnly($duration);
        $this->wall = $this->wall->modify(sprintf('+%d microseconds', intdiv($duration->nanoseconds, 1_000)));
    }

    /** Moves only the monotonic domain; the wall reading stays put. */
    public function advanceMonotonicOnly(Duration $duration): void
    {
        if ($this->monotonic > PHP_INT_MAX - $duration->nanoseconds) {
            throw new \OverflowException('Virtual monotonic time would overflow.');
        }
        $this->monotonic += $duration->nanoseconds;
    }

    /** Sets an absolute monotonic reading; it may never go backwards. */
    public function setMonotonic(int $nanoseconds): void
    {
        if ($nanoseconds < $this->monotonic) {
            throw new \InvalidArgumentException('The monotonic clock must never decrease.');
        }
        $this->monotonic = $nanoseconds;
    }

    /** Wall jump in either direction, independent of the monotonic domain. */
    public function setWall(\DateTimeImmutable $wall): void
    {
        $this->wall = $wall;
    }

    /** The next $count monotonic reads throw ClockFailureException. */
    public function failNextMonotonicReads(int $count = 1): void
    {
        $this->failMonotonicReads = self::nonNegative($count);
    }

    /** The next $count wall reads throw ClockFailureException. */
    public function failNextWallReads(int $count = 1): void
    {
        $this->failWallReads = self::nonNegative($count);
    }

    /** Read counts include failed reads. */
    public function monotonicReadCount(): int
    {
        return $this->monotonicReadCount;
    }

    public function wallReadCount(): int
    {
        return $this->wallReadCount;
    }

    /** Current monotonic value without counting as a read or consuming an injected failure. */
    public function peekMonotonic(): int
    {
        return $this->monotonic;
    }

    private static function nonNegative(int $count): int
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('The failure count must not be negative.');
        }

        return $count;
    }
}
