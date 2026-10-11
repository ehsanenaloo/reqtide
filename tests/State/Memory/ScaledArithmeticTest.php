<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\State\Memory\ScaledArithmetic;
use PHPUnit\Framework\TestCase;

final class ScaledArithmeticTest extends TestCase
{
    public function testSpecVectorExactlyOneTokenAt600000Microseconds(): void
    {
        // 100 tokens / 60 s in microsecond ticks: R = 100_000_000, W = 60_000_000.
        self::assertSame([1_000_000, 0, false], ScaledArithmetic::mulAddDivFloorCapped(600_000, 100_000_000, 0, 60_000_000, PHP_INT_MAX));
    }

    public function testSpecVectorBelowOneTokenAt599999Microseconds(): void
    {
        self::assertSame([999_998, 20_000_000, false], ScaledArithmetic::mulAddDivFloorCapped(599_999, 100_000_000, 0, 60_000_000, PHP_INT_MAX));
    }

    public function testProductsBeyondInt64AreExact(): void
    {
        $max = PHP_INT_MAX;
        // 2^62 * 2^62 / 2^62 = 2^62
        self::assertSame([1 << 62, 0, false], ScaledArithmetic::mulAddDivFloorCapped(1 << 62, 1 << 62, 0, 1 << 62, $max));
        // (2^62 * 4 + 2) / 3 = 6148914691236517206 exactly (2^64 + 2 = 3 * 6148914691236517206)
        self::assertSame([6_148_914_691_236_517_206, 0, false], ScaledArithmetic::mulAddDivFloorCapped(1 << 62, 4, 2, 3, $max));
        // (2^62 * 4 + 1) / 3: 2^64 + 1 = 3 * 6148914691236517205 + 2
        self::assertSame([6_148_914_691_236_517_205, 2, false], ScaledArithmetic::mulAddDivFloorCapped(1 << 62, 4, 1, 3, $max));
    }

    public function testQuotientAtOrAboveCapIsCappedAndRemainderDiscarded(): void
    {
        self::assertSame([1_000, 0, true], ScaledArithmetic::mulAddDivFloorCapped(PHP_INT_MAX, PHP_INT_MAX, 0, 1, 1_000));
        self::assertSame([PHP_INT_MAX, 0, true], ScaledArithmetic::mulAddDivFloorCapped(PHP_INT_MAX, PHP_INT_MAX, 0, 1, PHP_INT_MAX));
        self::assertSame([10, 0, true], ScaledArithmetic::mulAddDivFloorCapped(10, 1, 0, 1, 10), 'equality with the cap counts as reaching it');
        self::assertSame([9, 0, false], ScaledArithmetic::mulAddDivFloorCapped(9, 1, 0, 1, 10));
        self::assertSame([0, 0, true], ScaledArithmetic::mulAddDivFloorCapped(5, 5, 0, 7, 0));
    }

    public function testMatchesNativeArithmeticWhereNativeIsExact(): void
    {
        mt_srand(20261006);
        for ($i = 0; $i < 2_000; ++$i) {
            $a = mt_rand(0, 2_000_000_000);
            $b = mt_rand(0, 2_000_000_000);
            $d = mt_rand(1, 1 << 40);
            $c = mt_rand(0, $d - 1);
            $exact = intdiv($a * $b + $c, $d);
            [$q, $r, $capped] = ScaledArithmetic::mulAddDivFloorCapped($a, $b, $c, $d, PHP_INT_MAX);
            self::assertFalse($capped);
            self::assertSame($exact, $q);
            self::assertSame(($a * $b + $c) % $d, $r);
        }
    }

    public function testQuotientRemainderIdentityOnLargeOperands(): void
    {
        mt_srand(7);
        for ($i = 0; $i < 500; ++$i) {
            $a = mt_rand(1 << 40, PHP_INT_MAX);
            $b = mt_rand(1, 1 << 20);
            $d = mt_rand(1 << 50, PHP_INT_MAX);
            [$q, $r, $capped] = ScaledArithmetic::mulAddDivFloorCapped($a, $b, 0, $d, PHP_INT_MAX);
            self::assertFalse($capped);
            self::assertGreaterThanOrEqual(0, $r);
            self::assertLessThan($d, $r);
            self::assertSame($this->decMul((string) $a, (string) $b), $this->decAdd($this->decMul((string) $q, (string) $d), (string) $r));
        }
    }

    public function testCeilTicksToCoverIsTheSmallestSufficientTick(): void
    {
        $interval = 60 * 1_000_000_000;
        $rate = 10_000_000; // 10 tokens per 60 s
        $deficit = 1_000_000;

        self::assertSame(6_000_000_000, ScaledArithmetic::ceilTicksToCover($deficit, $interval, $rate, 0));
        self::assertSame(6_000_000_000, ScaledArithmetic::ceilTicksToCover($deficit, $interval, $rate, 5));
        self::assertSame(5_999_999_998, ScaledArithmetic::ceilTicksToCover($deficit, $interval, $rate, 20_000_000));
        self::assertSame(1, ScaledArithmetic::ceilTicksToCover(1, 1, 1, 0));

        for ($e = 5_999_999_996; $e <= 6_000_000_001; ++$e) {
            $covers = $e * $rate + 20_000_000 >= $deficit * $interval;
            self::assertSame($e >= 5_999_999_998, $covers);
        }
    }

    public function testCeilTicksSaturatesInsteadOfWrapping(): void
    {
        self::assertSame(PHP_INT_MAX, ScaledArithmetic::ceilTicksToCover(PHP_INT_MAX, PHP_INT_MAX, 1, 0));
    }

    public function testRejectsOperandsOutsideTheDomain(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        ScaledArithmetic::mulAddDivFloorCapped(1, 1, 5, 5, 10);
    }

    /** Independent decimal big-integer oracle: schoolbook multiplication of decimal strings. */
    private function decMul(string $x, string $y): string
    {
        $cells = array_fill(0, strlen($x) + strlen($y), 0);
        for ($i = strlen($x) - 1; $i >= 0; --$i) {
            for ($j = strlen($y) - 1; $j >= 0; --$j) {
                $cells[$i + $j + 1] += (int) $x[$i] * (int) $y[$j];
            }
        }
        for ($k = count($cells) - 1; $k > 0; --$k) {
            $cells[$k - 1] += intdiv($cells[$k], 10);
            $cells[$k] %= 10;
        }
        $digits = ltrim(implode('', $cells), '0');

        return $digits === '' ? '0' : $digits;
    }

    private function decAdd(string $x, string $y): string
    {
        $width = max(strlen($x), strlen($y)) + 1;
        $x = str_pad($x, $width, '0', STR_PAD_LEFT);
        $y = str_pad($y, $width, '0', STR_PAD_LEFT);
        $carry = 0;
        $out = '';
        for ($i = $width - 1; $i >= 0; --$i) {
            $sum = (int) $x[$i] + (int) $y[$i] + $carry;
            $out = ($sum % 10) . $out;
            $carry = intdiv($sum, 10);
        }
        $digits = ltrim($out, '0');

        return $digits === '' ? '0' : $digits;
    }
}
