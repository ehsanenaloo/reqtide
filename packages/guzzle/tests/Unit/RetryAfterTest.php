<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Enaxon\Reqtide\Guzzle\RetryAfter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The grammar cases: seconds, the three date forms, malformed input, several
 * lines, overflow. Times are fixed so that every expectation is exact.
 */
final class RetryAfterTest extends TestCase
{
    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2038-12-31T23:59:50.250000Z');
    }

    /** @return int|null the delay in microseconds of the greatest valid line, or null */
    private function micros(string ...$lines): ?int
    {
        $delay = RetryAfter::read(array_values($lines), self::now())->delay;

        return $delay === null ? null : $delay->ceilMicroseconds();
    }

    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function lines(): iterable
    {
        yield 'seconds' => ['7', 7_000_000];
        yield 'zero' => ['0', 0];
        yield 'leading zeros are digits' => ['007', 7_000_000];
        yield 'surrounding spaces and tabs' => [" \t7 ", 7_000_000];
        yield 'largest seconds in range' => ['9007199254', 9_007_199_254_000_000];
        yield 'imf-fixdate' => ['Fri, 31 Dec 2038 23:59:59 GMT', 8_750_000];
        yield 'imf-fixdate in the past is zero, not invalid' => ['Mon, 01 Jan 2001 00:00:00 GMT', 0];
        yield 'imf-fixdate equal to now rounds to zero' => ['Fri, 31 Dec 2038 23:59:50 GMT', 0];
        yield 'rfc 850' => ['Friday, 31-Dec-38 23:59:59 GMT', 8_750_000];
        yield 'asctime' => ['Fri Dec 31 23:59:59 2038', 8_750_000];
        yield 'asctime with a padded day' => ['Thu Jan  1 00:00:00 1970', 0];
        yield 'asctime with a zero-padded day (date3 allows 2DIGIT)' => ['Sat Jan 01 00:00:00 2039', 9_750_000];

        yield 'empty' => ['', null];
        yield 'negative' => ['-1', null];
        yield 'plus sign' => ['+7', null];
        yield 'fraction' => ['1.5', null];
        yield 'exponent' => ['1e3', null];
        yield 'hex' => ['0x10', null];
        yield 'unit words' => ['5 minutes', null];
        yield 'relative words' => ['tomorrow', null];
        yield 'a list on one line' => ['7, 120', null];
        yield 'seconds above the range' => ['9007199255', null];
        yield 'twenty-one digits' => ['123456789012345678901', null];
        yield 'twenty digits of zeros then a value' => ['000000000000000000007', null];
        yield 'asctime with day zero' => ['Thu Jan 00 00:00:00 1970', null];
        yield 'asctime with an unpadded one-digit day' => ['Thu Jan 1 00:00:00 1970', null];
        yield 'wrong weekday' => ['Sat, 31 Dec 2038 23:59:59 GMT', null];
        yield 'impossible date' => ['Sat, 30 Feb 2038 23:59:59 GMT', null];
        yield 'zone is not GMT' => ['Fri, 31 Dec 2038 23:59:59 UTC', null];
        yield 'lower-case gmt' => ['Fri, 31 Dec 2038 23:59:59 gmt', null];
        yield 'hour 24' => ['Fri, 31 Dec 2038 24:00:00 GMT', null];
        yield 'leap second' => ['Fri, 31 Dec 2038 23:59:60 GMT', null];
        yield 'trailing text' => ['Fri, 31 Dec 2038 23:59:59 GMT extra', null];
        yield 'iso 8601' => ['2038-12-31T23:59:59Z', null];
        yield 'date far beyond the range is rejected, not truncated' => ['Fri, 31 Dec 9999 23:59:59 GMT', null];
    }

    #[DataProvider('lines')]
    public function testEachLineIsReadStrictly(string $line, ?int $micros): void
    {
        self::assertSame($micros, $this->micros($line));
    }

    public function testTheGreatestValidLineWinsAndBadLinesAreCountedNotUsed(): void
    {
        $reading = RetryAfter::read(['7', '120', 'abc', 'Fri, 31 Dec 2038 23:59:59 GMT'], self::now());
        self::assertSame(120_000_000, $reading->delay?->ceilMicroseconds());
        self::assertSame(3, $reading->validLines);
        self::assertSame(1, $reading->rejectedLines);
        self::assertFalse($reading->outOfRange);
    }

    public function testNoValidLineMeansNoLowerBoundNeverZero(): void
    {
        $none = RetryAfter::read([], self::now());
        self::assertNull($none->delay);
        self::assertSame([0, 0], [$none->validLines, $none->rejectedLines]);

        $bad = RetryAfter::read(['abc', '9007199255'], self::now());
        self::assertNull($bad->delay, 'a rejected value must not become a zero or a truncated bound');
        self::assertSame(2, $bad->rejectedLines);
        self::assertTrue($bad->outOfRange);
    }

    public function testAnOutOfRangeLineDoesNotHideAValidOne(): void
    {
        $reading = RetryAfter::read(['99999999999', '5'], self::now());
        self::assertSame(5_000_000, $reading->delay?->ceilMicroseconds());
        self::assertTrue($reading->outOfRange);
    }

    public function testTheTwoDigitYearRuleFollowsRfc9110(): void
    {
        $now = new \DateTimeImmutable('2026-10-09T12:00:00Z');
        // 29 is within 50 years ahead: 2029. 1 Jan 2029 is a Monday.
        $future = RetryAfter::read(['Monday, 01-Jan-29 00:00:00 GMT'], $now)->delay;
        self::assertSame((strtotime('2029-01-01T00:00:00Z') - strtotime('2026-10-09T12:00:00Z')) * 1_000_000_000, $future?->nanoseconds);

        // 77 would be 2077, more than 50 years ahead, so it means 1977 (a Saturday): a past date, zero delay.
        self::assertSame(0, RetryAfter::read(['Saturday, 01-Jan-77 00:00:00 GMT'], $now)->delay?->ceilMicroseconds());
        // 1 Jan 1977 was a Saturday, so the weekday "Friday" does not match the date and the line is rejected.
        self::assertNull(RetryAfter::read(['Friday, 01-Jan-77 00:00:00 GMT'], $now)->delay);
    }
}
