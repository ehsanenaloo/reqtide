<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State\Memory;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;

/**
 * Exact overflow-free `floor((a * b + c) / d)` for non-negative int64 inputs.
 *
 * This is a documented native fallback for the process-local backends: it never forms a product that can
 * exceed int64. It splits `a = qa*d + ra`, so `a*b = qa*b*d + ra*b`, bounds `qa*b` by the cap, and computes
 * `(ra*b) divmod d` with binary double-and-add on a (quotient, remainder) pair where the remainder stays
 * below `d`. Every intermediate quotient is at most the final quotient, which is below `b`, so no step wraps.
 * If a shared `Internal\Arithmetic` limb implementation is later added it should replace this class.
 *
 * @internal
 */
final class ScaledArithmetic
{
    private function __construct() {}

    /**
     * @param int $c addend, requires `0 <= c < d`
     * @param int $cap quotient ceiling, `>= 0`
     * @return array{0: int, 1: int, 2: bool} quotient (equal to `$cap` when capped), remainder (0 when capped),
     *                                       and whether the exact quotient reached the cap
     */
    public static function mulAddDivFloorCapped(int $a, int $b, int $c, int $d, int $cap): array
    {
        if ($a < 0 || $b < 0 || $c < 0 || $d < 1 || $cap < 0 || $c >= $d) {
            throw new InvalidConfigurationException('Arithmetic operands are outside their domain.');
        }
        if ($cap === 0) {
            return [0, 0, true];
        }

        $qa = intdiv($a, $d);
        $ra = $a % $d;

        $head = 0;
        if ($qa !== 0 && $b !== 0) {
            if ($b > intdiv($cap, $qa)) {
                return [$cap, 0, true];
            }
            $head = $qa * $b;
        }

        $q = 0;
        $m = 0;
        for ($bit = 62; $bit >= 0; --$bit) {
            // double
            $carry = 0;
            if ($m >= $d - $m) {
                $m -= $d - $m;
                $carry = 1;
            } else {
                $m += $m;
            }
            $q = $q * 2 + $carry;
            if ((($b >> $bit) & 1) === 1) {
                if ($m >= $d - $ra) {
                    $m -= $d - $ra;
                    ++$q;
                } else {
                    $m += $ra;
                }
            }
        }

        // add c
        if ($m >= $d - $c) {
            $m -= $d - $c;
            ++$q;
        } else {
            $m += $c;
        }

        if ($q > $cap - $head) {
            return [$cap, 0, true];
        }
        $total = $head + $q;
        if ($total >= $cap) {
            return [$cap, 0, true];
        }

        return [$total, $m, false];
    }

    /**
     * Smallest integer `e >= 0` with `e * rate + remainder >= deficit * interval`, saturating at PHP_INT_MAX.
     *
     * @param int $remainder accumulated numerator remainder in `[0, interval)`
     */
    public static function ceilTicksToCover(int $deficit, int $interval, int $rate, int $remainder): int
    {
        if ($deficit < 1 || $interval < 1 || $rate < 1 || $remainder < 0) {
            throw new InvalidConfigurationException('Arithmetic operands are outside their domain.');
        }
        [$q, $rem, $capped] = self::mulAddDivFloorCapped($deficit, $interval, 0, $rate, PHP_INT_MAX);
        if ($capped) {
            return PHP_INT_MAX;
        }
        // x = deficit*interval = q*rate + rem; need ceil((x - remainder) / rate).
        $t = $rem - $remainder;
        if ($t > 0) {
            return $q === PHP_INT_MAX ? PHP_INT_MAX : $q + 1;
        }
        if ($t === 0) {
            return $q;
        }

        return max(0, $q - intdiv(-$t, $rate));
    }
}
