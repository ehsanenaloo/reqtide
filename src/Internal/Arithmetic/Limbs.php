<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Internal\Arithmetic;

/**
 * Unsigned big-integer vectors in base 8192 (13-bit limbs), least-significant limb first.
 *
 * A vector is normalized when its most-significant limb is non-zero; zero is the empty vector. Every
 * function accepts and returns normalized vectors. Each individual limb operation stays far below the
 * native int64 range, so no step can overflow or be rounded. This is a bounded helper for exact
 * integer arithmetic, not a general-purpose arbitrary-precision dependency.
 *
 * @internal
 */
final class Limbs
{
    public const BITS = 13;

    public const BASE = 8192;

    public const MASK = 8191;

    private function __construct() {}

    /**
     * @return list<int>
     */
    public static function fromInt(int $value): array
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('Limb vectors are unsigned.');
        }

        $limbs = [];
        while ($value > 0) {
            $limbs[] = $value & self::MASK;
            $value >>= self::BITS;
        }

        return $limbs;
    }

    /**
     * Exact native value, or null when the vector exceeds PHP_INT_MAX.
     *
     * @param list<int> $a
     */
    public static function toInt(array $a): ?int
    {
        $limit = PHP_INT_MAX >> self::BITS;
        $value = 0;
        for ($i = count($a) - 1; $i >= 0; --$i) {
            if ($value > $limit) {
                return null;
            }
            $value = ($value << self::BITS) | $a[$i];
        }

        return $value;
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     */
    public static function compare(array $a, array $b): int
    {
        $na = count($a);
        $nb = count($b);
        if ($na !== $nb) {
            return $na <=> $nb;
        }
        for ($i = $na - 1; $i >= 0; --$i) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] <=> $b[$i];
            }
        }

        return 0;
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     * @return list<int>
     */
    public static function add(array $a, array $b): array
    {
        $n = max(count($a), count($b));
        $result = [];
        $carry = 0;
        for ($i = 0; $i < $n; ++$i) {
            $sum = ($a[$i] ?? 0) + ($b[$i] ?? 0) + $carry;
            $result[] = $sum & self::MASK;
            $carry = $sum >> self::BITS;
        }
        if ($carry > 0) {
            $result[] = $carry;
        }

        return $result;
    }

    /**
     * @param list<int> $a
     * @param list<int> $b requires $a >= $b
     * @return list<int>
     */
    public static function subtract(array $a, array $b): array
    {
        if (self::compare($a, $b) < 0) {
            throw new \InvalidArgumentException('Limb subtraction requires minuend >= subtrahend.');
        }

        return self::subtractUnchecked($a, $b);
    }

    /**
     * Schoolbook convolution followed by carry normalization. One output cell sums at most
     * min(count(a), count(b)) products of two 13-bit limbs, far below the int64 range.
     *
     * @param list<int> $a
     * @param list<int> $b
     * @return list<int>
     */
    public static function multiply(array $a, array $b): array
    {
        $na = count($a);
        $nb = count($b);
        if ($na === 0 || $nb === 0) {
            return [];
        }

        $cells = array_fill(0, $na + $nb, 0);
        for ($i = 0; $i < $na; ++$i) {
            $ai = $a[$i];
            if ($ai === 0) {
                continue;
            }
            for ($j = 0; $j < $nb; ++$j) {
                $cells[$i + $j] += $ai * $b[$j];
            }
        }

        $result = [];
        $carry = 0;
        foreach ($cells as $cell) {
            $total = $cell + $carry;
            $result[] = $total & self::MASK;
            $carry = $total >> self::BITS;
        }

        return self::trim($result);
    }

    /**
     * Binary long division: for each source bit from most to least significant, shift the remainder left,
     * append the bit and subtract the divisor once when the remainder is not smaller.
     *
     * @param list<int> $numerator
     * @param list<int> $divisor non-zero
     * @return array{0: list<int>, 1: list<int>} quotient and remainder
     */
    public static function divide(array $numerator, array $divisor): array
    {
        if ($divisor === []) {
            throw new \DivisionByZeroError('Limb division by zero.');
        }
        if (self::compare($numerator, $divisor) < 0) {
            return [[], $numerator];
        }

        $limbCount = count($numerator);
        $quotient = array_fill(0, $limbCount, 0);
        /** @var list<int> $remainder */
        $remainder = [];

        for ($limbIndex = $limbCount - 1; $limbIndex >= 0; --$limbIndex) {
            $limb = $numerator[$limbIndex];
            for ($bit = self::BITS - 1; $bit >= 0; --$bit) {
                $carry = ($limb >> $bit) & 1;
                foreach ($remainder as $k => $part) {
                    $shifted = ($part << 1) | $carry;
                    $remainder[$k] = $shifted & self::MASK;
                    $carry = $shifted >> self::BITS;
                }
                if ($carry > 0) {
                    $remainder[] = $carry;
                }
                if (self::compare($remainder, $divisor) >= 0) {
                    $remainder = self::subtractUnchecked($remainder, $divisor);
                    $quotient[$limbIndex] |= 1 << $bit;
                }
            }
        }

        return [self::trim(array_values($quotient)), $remainder];
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     * @return list<int>
     */
    private static function subtractUnchecked(array $a, array $b): array
    {
        $result = [];
        $borrow = 0;
        $n = count($a);
        for ($i = 0; $i < $n; ++$i) {
            $difference = $a[$i] - ($b[$i] ?? 0) - $borrow;
            if ($difference < 0) {
                $difference += self::BASE;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result[] = $difference;
        }

        return self::trim($result);
    }

    /**
     * @param list<int> $a
     * @return list<int>
     */
    private static function trim(array $a): array
    {
        $n = count($a);
        while ($n > 0 && $a[$n - 1] === 0) {
            --$n;
        }

        return $n === count($a) ? $a : array_slice($a, 0, $n);
    }
}
