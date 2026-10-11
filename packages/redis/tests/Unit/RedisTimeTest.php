<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Unit;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Redis\Time\RedisTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Boundary vectors for the PHP side of the time domain.
 */
final class RedisTimeTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function quantization(): iterable
    {
        yield 'zero' => [0, 0];
        yield '1 ns becomes 1 us' => [1, 1];
        yield '999 ns becomes 1 us' => [999, 1];
        yield '1000 ns' => [1000, 1];
        yield '1001 ns rounds up' => [1001, 2];
        yield '1.5 ms' => [1_500_000, 1500];
        yield 'exact maximum' => [RedisTime::EXACT_MAX * 1000, RedisTime::EXACT_MAX];
        yield 'just below the maximum' => [RedisTime::EXACT_MAX * 1000 - 1, RedisTime::EXACT_MAX];
    }

    #[DataProvider('quantization')]
    public function testNanosecondsRoundUpToMicroseconds(int $nanoseconds, int $microseconds): void
    {
        self::assertSame($microseconds, RedisTime::nanosecondsToMicroseconds($nanoseconds));
    }

    public function test999NanosecondsReturnsAWaitOfOneThousand(): void
    {
        self::assertSame(1000, RedisTime::microsecondsToNanoseconds(RedisTime::nanosecondsToMicroseconds(999)));
    }

    public function testTheMaximumConvertsBackWithoutOverflow(): void
    {
        self::assertSame(9_007_199_254_740_991_000, RedisTime::microsecondsToNanoseconds(RedisTime::EXACT_MAX));
    }

    public function testOneAboveTheMaximumIsRejectedBeforeAnyArithmetic(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        RedisTime::nanosecondsToMicroseconds(RedisTime::EXACT_MAX * 1000 + 1);
    }

    public function testMicrosecondsAboveTheMaximumAndNegativeValuesAreRejected(): void
    {
        $rejected = 0;
        foreach ([fn() => RedisTime::microsecondsToNanoseconds(RedisTime::EXACT_MAX + 1), fn() => RedisTime::microsecondsToNanoseconds(-1), fn() => RedisTime::nanosecondsToMicroseconds(-1)] as $call) {
            try {
                $call();
                self::fail('expected an exception');
            } catch (InvalidConfigurationException) {
                ++$rejected;
            }
        }
        self::assertSame(3, $rejected);
    }

    public function testCooldownPlusSixtySecondsGraceAtTheBoundary(): void
    {
        $grace = 60_000_000;
        self::assertSame(RedisTime::EXACT_MAX, RedisTime::withGrace(RedisTime::EXACT_MAX - $grace, $grace));
        $this->expectException(InvalidConfigurationException::class);
        RedisTime::withGrace(RedisTime::EXACT_MAX - $grace + 1, $grace);
    }
}
