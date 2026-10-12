<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle;

use Enaxon\Reqtide\Value\Duration;

/**
 * Strict `Retry-After` reader.
 *
 * A line is either `delay-seconds` (digits only) or an HTTP date in IMF-fixdate (`Fri, 31 Dec 2038 23:59:59 GMT`), the
 * obsolete RFC 850 form (`Friday, 31-Dec-38 23:59:59 GMT`) or the asctime form (`Fri Dec 31 23:59:59 2038`). Anything
 * else (a sign, a fraction, an exponent, words, a list such as `7, 120`, a wrong weekday, an impossible date, a zone
 * other than GMT) is rejected. Surrounding spaces and tabs are ignored.
 *
 * A date is converted once against the wall time given by the caller into `max(0, date - now)`, rounded up to the
 * microsecond so that a wait is never early. Several lines: the greatest valid delay wins. A value beyond the
 * shared-state duration range (9,007,199,254 seconds) is rejected, not truncated.
 *
 * Clock skew between the server and this host remains a stated limitation of date values.
 */
final class RetryAfter
{
    /** Largest delay in whole seconds: LUA_EXACT_MAX microseconds, rounded down to seconds. */
    public const MAX_SECONDS = 9_007_199_254;

    private const MONTHS = ['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12];

    private const SHORT_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    private const LONG_DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    private function __construct() {}

    /**
     * @param array<string> $lines the values of every Retry-After header line, as Guzzle's getHeader() returns them
     * @param \DateTimeImmutable $now the observed wall time of the response
     */
    public static function read(array $lines, \DateTimeImmutable $now): RetryAfterReading
    {
        $best = null;
        $valid = 0;
        $rejected = 0;
        $outOfRange = false;
        foreach ($lines as $line) {
            $result = self::line(trim($line, " \t"), $now);
            if ($result === null) {
                ++$rejected;
            } elseif ($result === false) {
                ++$rejected;
                $outOfRange = true;
            } else {
                ++$valid;
                if ($best === null || $result->compare($best) > 0) {
                    $best = $result;
                }
            }
        }

        return new RetryAfterReading($best, $valid, $rejected, $outOfRange);
    }

    /** @return Duration|false|null the delay, false when out of range, null when malformed */
    private static function line(string $text, \DateTimeImmutable $now): Duration|false|null
    {
        if (preg_match('~^[0-9]{1,19}\z~', $text) === 1) {
            $digits = ltrim($text, '0');
            if ($digits === '') {
                return Duration::zero();
            }

            return strlen($digits) > 10 || (int) $digits > self::MAX_SECONDS ? false : Duration::seconds((int) $digits);
        }
        if (preg_match('~^[0-9]+\z~', $text) === 1) {
            return false;
        }

        $date = self::httpDate($text, $now);
        if ($date === null) {
            return null;
        }
        $seconds = $date->getTimestamp() - $now->getTimestamp();
        $micros = $seconds * 1_000_000 - (int) $now->format('u');
        if ($micros <= 0) {
            return Duration::zero();
        }

        return $seconds > self::MAX_SECONDS ? false : Duration::microseconds($micros);
    }

    private static function httpDate(string $text, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $months = implode('|', array_keys(self::MONTHS));
        $short = implode('|', self::SHORT_DAYS);
        $long = implode('|', self::LONG_DAYS);
        $time = '([01][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])';

        if (preg_match('~^(' . $short . '), ([0-9]{2}) (' . $months . ') ([0-9]{4}) ' . $time . ' GMT\z~', $text, $m) === 1) {
            return self::build($m[1], false, (int) $m[2], $m[3], (int) $m[4], (int) $m[5], (int) $m[6], (int) $m[7]);
        }
        if (preg_match('~^(' . $long . '), ([0-9]{2})-(' . $months . ')-([0-9]{2}) ' . $time . ' GMT\z~', $text, $m) === 1) {
            $year = self::fullYear((int) $m[4], $m[3], (int) $m[2], (int) $m[5], (int) $m[6], (int) $m[7], $now);

            return self::build($m[1], true, (int) $m[2], $m[3], $year, (int) $m[5], (int) $m[6], (int) $m[7]);
        }
        if (preg_match('~^(' . $short . ') (' . $months . ') ( [1-9]|[0-9]{2}) ' . $time . ' ([0-9]{4})\z~', $text, $m) === 1) {
            return self::build($m[1], false, (int) trim($m[3]), $m[2], (int) $m[7], (int) $m[4], (int) $m[5], (int) $m[6]);
        }

        return null;
    }

    /**
     * RFC 9110, section 5.6.7: a two-digit year means the most recent year with those digits whose instant is not more
     * than 50 years in the future. The candidates are the same digits in the last, this and the next century, compared
     * by their full instant; the weekday is checked afterwards, so it can never choose the century.
     */
    private static function fullYear(int $twoDigits, string $month, int $day, int $hour, int $minute, int $second, \DateTimeImmutable $now): int
    {
        $limit = $now->setTimezone(new \DateTimeZone('UTC'))->modify('+50 years');
        $century = intdiv((int) $now->format('Y'), 100) * 100;
        $monthNumber = self::MONTHS[$month];
        foreach ([$century + 100, $century, $century - 100] as $candidate) {
            $year = $candidate + $twoDigits;
            $instant = (new \DateTimeImmutable('@0'))->setDate($year, $monthNumber, $day)->setTime($hour, $minute, $second);
            if ($instant <= $limit) {
                return $year;
            }
        }

        return $century - 100 + $twoDigits;
    }

    private static function build(string $dayName, bool $longName, int $day, string $month, int $year, int $hour, int $minute, int $second): ?\DateTimeImmutable
    {
        $monthNumber = self::MONTHS[$month];
        if ($year < 1 || !checkdate($monthNumber, $day, $year)) {
            return null;
        }
        $date = (new \DateTimeImmutable('@0'))->setDate($year, $monthNumber, $day)->setTime($hour, $minute, $second);
        if ($date->format($longName ? 'l' : 'D') !== $dayName) {
            return null;
        }

        return $date;
    }
}
