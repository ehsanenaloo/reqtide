<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Internal\Arithmetic;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;

/**
 * Exact bounded products, floor/ceiling division and product comparison over non-negative int64 inputs.
 *
 * Every function uses plain native integer arithmetic only when it is provable beforehand that no
 * intermediate value can exceed PHP_INT_MAX (the overflow test itself never overflows); otherwise it
 * computes with base-8192 limb vectors. Results are identical on both paths.
 *
 * @internal
 */
final class BoundedArithmetic
{
    private function __construct() {}

    /**
     * floor((a*b + addend) / divisor), capped.
     *
     * @param int $a non-negative
     * @param int $b non-negative
     * @param int $addend non-negative
     * @param int $divisor positive
     * @param int $cap non-negative
     */
    public static function mulAddDivFloorCapped(int $a, int $b, int $addend, int $divisor, int $cap): CappedQuotient
    {
        if ($a < 0 || $b < 0 || $addend < 0 || $divisor <= 0 || $cap < 0) {
            throw new InvalidConfigurationException('Arithmetic operands are outside their unsigned domain.');
        }

        if ($b === 0 || $a <= intdiv(PHP_INT_MAX - $addend, $b)) {
            $numerator = $a * $b + $addend;
            $quotient = intdiv($numerator, $divisor);

            return $quotient >= $cap
                ? new CappedQuotient($cap, 0, true)
                : new CappedQuotient($quotient, $numerator % $divisor, false);
        }

        [$quotient, $remainder] = Limbs::divide(
            Limbs::add(Limbs::multiply(Limbs::fromInt($a), Limbs::fromInt($b)), Limbs::fromInt($addend)),
            Limbs::fromInt($divisor),
        );

        return self::capped($quotient, $remainder, $cap);
    }

    /**
     * ceil(max(a*b - subtrahend, 0) / divisor), capped. The remainder of the result is always zero.
     *
     * @param int $a non-negative
     * @param int $b non-negative
     * @param int $subtrahend non-negative
     * @param int $divisor positive
     * @param int $cap non-negative
     */
    public static function mulSubCeilDivCapped(int $a, int $b, int $subtrahend, int $divisor, int $cap): CappedQuotient
    {
        if ($a < 0 || $b < 0 || $subtrahend < 0 || $divisor <= 0 || $cap < 0) {
            throw new InvalidConfigurationException('Arithmetic operands are outside their unsigned domain.');
        }

        if ($b === 0 || $a <= intdiv(PHP_INT_MAX, $b)) {
            $product = $a * $b;
            $numerator = $product > $subtrahend ? $product - $subtrahend : 0;
            $quotient = intdiv($numerator, $divisor) + ($numerator % $divisor === 0 ? 0 : 1);

            return $quotient >= $cap ? new CappedQuotient($cap, 0, true) : new CappedQuotient($quotient, 0, false);
        }

        $product = Limbs::multiply(Limbs::fromInt($a), Limbs::fromInt($b));
        $sub = Limbs::fromInt($subtrahend);
        $numerator = Limbs::compare($product, $sub) > 0 ? Limbs::subtract($product, $sub) : [];
        [$quotient, $remainder] = Limbs::divide($numerator, Limbs::fromInt($divisor));
        if ($remainder !== []) {
            $quotient = Limbs::add($quotient, [1]);
        }

        return self::capped($quotient, [], $cap);
    }

    /**
     * Compares a*b with c*d exactly: -1, 0 or 1.
     */
    public static function compareProducts(int $a, int $b, int $c, int $d): int
    {
        if ($a < 0 || $b < 0 || $c < 0 || $d < 0) {
            throw new InvalidConfigurationException('Arithmetic operands are outside their unsigned domain.');
        }

        if (self::productFits($a, $b) && self::productFits($c, $d)) {
            return $a * $b <=> $c * $d;
        }

        return Limbs::compare(
            Limbs::multiply(Limbs::fromInt($a), Limbs::fromInt($b)),
            Limbs::multiply(Limbs::fromInt($c), Limbs::fromInt($d)),
        );
    }

    /**
     * Compares a*b + c*d with e*f exactly: -1, 0 or 1.
     */
    public static function compareWeightedSum(int $a, int $b, int $c, int $d, int $e, int $f): int
    {
        if ($a < 0 || $b < 0 || $c < 0 || $d < 0 || $e < 0 || $f < 0) {
            throw new InvalidConfigurationException('Arithmetic operands are outside their unsigned domain.');
        }

        if (self::productFits($a, $b) && self::productFits($c, $d) && self::productFits($e, $f)) {
            $left = $a * $b;
            $second = $c * $d;
            if ($left <= PHP_INT_MAX - $second) {
                return ($left + $second) <=> ($e * $f);
            }
        }

        return Limbs::compare(
            Limbs::add(
                Limbs::multiply(Limbs::fromInt($a), Limbs::fromInt($b)),
                Limbs::multiply(Limbs::fromInt($c), Limbs::fromInt($d)),
            ),
            Limbs::multiply(Limbs::fromInt($e), Limbs::fromInt($f)),
        );
    }

    private static function productFits(int $a, int $b): bool
    {
        return $b === 0 || $a <= intdiv(PHP_INT_MAX, $b);
    }

    /**
     * @param list<int> $quotient
     * @param list<int> $remainder
     */
    private static function capped(array $quotient, array $remainder, int $cap): CappedQuotient
    {
        if (Limbs::compare($quotient, Limbs::fromInt($cap)) >= 0) {
            return new CappedQuotient($cap, 0, true);
        }

        $value = Limbs::toInt($quotient);
        $rest = Limbs::toInt($remainder);
        if ($value === null || $rest === null) {
            throw new \LogicException('A value below the int cap must be representable.');
        }

        return new CappedQuotient($value, $rest, false);
    }
}
