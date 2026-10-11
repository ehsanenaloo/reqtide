<?php

declare(strict_types=1);

namespace Comparison;

/**
 * Process-wide virtual wall clock. Library code that calls unqualified time()/microtime()/usleep()
 * inside its own namespace is redirected here by src/shadows.php.
 */
final class VirtualTime
{
    /** 1_700_000_040 is divisible by 60 and by 10: scenario time 0 sits on a 60s tumbling-window boundary. */
    public const EPOCH = 1_700_000_040.0;

    private static float $now = self::EPOCH;

    public static function reset(): void
    {
        self::$now = self::EPOCH;
    }

    public static function now(): float
    {
        return self::$now;
    }

    /** Seconds since scenario start (EPOCH). */
    public static function elapsed(): float
    {
        return self::$now - self::EPOCH;
    }

    public static function advance(float $seconds): void
    {
        if ($seconds > 0) {
            self::$now += $seconds;
        }
    }

    public static function set(float $elapsedSinceEpoch): void
    {
        self::$now = self::EPOCH + $elapsedSinceEpoch;
    }

    /** Move the clock to an absolute scenario time (seconds since EPOCH); may go backwards. */
    public static function jumpTo(float $elapsedSinceEpoch): void
    {
        self::set($elapsedSinceEpoch);
    }
}
