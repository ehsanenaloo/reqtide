<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State\Memory;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;

/**
 * Overflow-free comparison of products of two non-negative 63-bit integers.
 *
 * Operands are split into three 21-bit limbs; every limb product is below 2^42 and each output cell
 * receives at most three of them, so all intermediate sums are exact in a native int. The result is
 * therefore independent of the magnitude of the operands.
 *
 * @internal
 */
final class ExactProduct
{
    private const LIMB_BITS = 21;
    private const LIMB_MASK = 0x1FFFFF;

    private function __construct() {}

    /** Returns the sign of (a * b) - (c * d), as -1, 0 or 1. */
    public static function compare(int $a, int $b, int $c, int $d): int
    {
        $left = self::product($a, $b);
        $right = self::product($c, $d);
        for ($i = 5; $i >= 0; --$i) {
            if ($left[$i] !== $right[$i]) {
                return $left[$i] <=> $right[$i];
            }
        }

        return 0;
    }

    /** @return list<int> six normalised 21-bit limbs, least significant first */
    private static function product(int $a, int $b): array
    {
        if ($a < 0 || $b < 0) {
            throw new InvalidConfigurationException('Exact products are defined for non-negative operands only.');
        }
        $x = [$a & self::LIMB_MASK, ($a >> self::LIMB_BITS) & self::LIMB_MASK, $a >> (2 * self::LIMB_BITS)];
        $y = [$b & self::LIMB_MASK, ($b >> self::LIMB_BITS) & self::LIMB_MASK, $b >> (2 * self::LIMB_BITS)];
        $cells = [0, 0, 0, 0, 0, 0];
        for ($i = 0; $i < 3; ++$i) {
            for ($j = 0; $j < 3; ++$j) {
                $cells[$i + $j] += $x[$i] * $y[$j];
            }
        }
        $carry = 0;
        for ($k = 0; $k < 6; ++$k) {
            $cell = $cells[$k] + $carry;
            $carry = $cell >> self::LIMB_BITS;
            $cells[$k] = $cell & self::LIMB_MASK;
        }

        return $cells;
    }
}
