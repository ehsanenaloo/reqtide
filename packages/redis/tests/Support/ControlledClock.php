<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Support;

use Enaxon\Reqtide\Tests\Contract\ControllableTime;

/**
 * The clock of a Redis server that runs under libfaketime (tools/docker/redis-faketime): the server reads its wall time
 * from a file on every call, so writing the file moves the clock forwards or backwards and the scripts under test run
 * unchanged.
 * libfaketime is not exact to the microsecond for every value: it parses the fraction of a second as a floating-point
 * number and truncates, so for some values the server reports one or two microseconds less than the file says (observed
 * on Redis 7.4.11 and 8.2.10 for ".999999" at several seconds). A contract suite that states "one tick before the
 * boundary" cannot run on a clock like that. The file therefore carries half a microsecond more than the target (the
 * parser accepts sub-microsecond digits), and every move is verified by reading the server's TIME; when it still differs
 * from the target the file is rewritten with a small correction (up to four microseconds). A target that cannot be
 * reached is an error: the suite never runs on an inexact clock.
 *
 * The contract axis (nanoseconds) is mapped onto 2030-01-01 00:00:00 UTC, an epoch day boundary, so aligned-window
 * expectations hold. The clock is frozen: it moves only when a test moves it. The file is replaced by rename, never
 * written in place, so the server never reads a half-written value.
 */
final class ControlledClock implements ControllableTime
{
    /** 2030-01-01 00:00:00 UTC. */
    public const BASE_EPOCH_SECONDS = 1_893_456_000;

    private const MAX_CORRECTION_MICROSECONDS = 4;

    private int $nanoseconds = 0;

    /**
     * @param \Closure(): array{int, int} $observe the server's TIME as (seconds, microseconds)
     */
    public function __construct(private readonly string $file, private readonly \Closure $observe)
    {
        $this->write();
    }

    public function now(): int
    {
        return $this->nanoseconds;
    }

    public function moveTo(int $nanoseconds): void
    {
        $this->nanoseconds = $nanoseconds;
        $this->write();
    }

    public function advance(int $nanoseconds): void
    {
        $this->moveTo($this->nanoseconds + $nanoseconds);
    }

    public function step(): int
    {
        return 1_000;
    }

    private function write(): void
    {
        $target = intdiv($this->nanoseconds, 1_000);
        $expected = [self::BASE_EPOCH_SECONDS + intdiv($target, 1_000_000), $target % 1_000_000];

        for ($correction = 0; $correction <= self::MAX_CORRECTION_MICROSECONDS; ++$correction) {
            $this->put($target + $correction);
            if (($this->observe)() === $expected) {
                return;
            }
        }

        throw new \RuntimeException('The controllable clock could not be set exactly to ' . $expected[0] . '.' . sprintf('%06d', $expected[1]) . '.');
    }

    private function put(int $micros): void
    {
        $seconds = intdiv($micros, 1_000_000);
        // Half a microsecond is added: the server truncates, so the written value lands on the intended microsecond.
        $text = gmdate('Y-m-d H:i:s', self::BASE_EPOCH_SECONDS + $seconds) . sprintf('.%06d5', $micros % 1_000_000);
        $temporary = $this->file . '.' . getmypid() . '.tmp';
        if (file_put_contents($temporary, $text) === false || !rename($temporary, $this->file)) {
            throw new \RuntimeException('The controllable clock file could not be written.');
        }
    }
}
