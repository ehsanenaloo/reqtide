<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\State\Memory\ExactProduct;
use Enaxon\Reqtide\State\Memory\ScaledArithmetic;
use PHPUnit\Framework\TestCase;

/**
 * Edge-grid comparison of the overflow-free native fallbacks with a bcmath oracle: limb boundaries, the 63-bit
 * ceiling, caps equal to and around the exact quotient, and the minimum-tick computation.
 */
final class ExactArithmeticOracleTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('bcmath')) {
            self::markTestSkipped('bcmath is required as the independent big-integer oracle.');
        }
    }

    /** @return list<int> */
    private static function edges(): array
    {
        return [
            0, 1, 2, 3, 0x1FFFFF, 0x200000, 0x200001, 0x3FFFFFFFFFF, 0x40000000000, 0x123456789ABCDEF,
            (1 << 42) + (1 << 21) + 1, 1 << 62, PHP_INT_MAX - 1, PHP_INT_MAX,
        ];
    }

    // ---- ExactProduct ---------------------------------------------------------------------------------

    public function testExactProductComparisonOverTheEdgeGrid(): void
    {
        $edges = self::edges();
        foreach ($edges as $a) {
            foreach ($edges as $b) {
                foreach ($edges as $c) {
                    foreach ($edges as $d) {
                        $expected = bccomp(bcmul((string) $a, (string) $b), bcmul((string) $c, (string) $d));
                        self::assertSame($expected, ExactProduct::compare($a, $b, $c, $d), "compare($a, $b, $c, $d)");
                    }
                }
            }
        }
    }

    public function testExactProductDistinguishesProductsThatDifferOnlyInTheirTopLimbs(): void
    {
        // Each pair of products has identical low limbs and differs in exactly one higher limb.
        self::assertSame(1, ExactProduct::compare(1 << 62, 2, 1 << 62, 1));
        self::assertSame(-1, ExactProduct::compare(1 << 21, 1 << 21, 1 << 42, 2));
        self::assertSame(0, ExactProduct::compare(1 << 21, 1 << 42, 1 << 42, 1 << 21));
        self::assertSame(1, ExactProduct::compare(1 << 42, 1 << 20, 1 << 21, 1 << 20));
        self::assertSame(-1, ExactProduct::compare(PHP_INT_MAX, PHP_INT_MAX - 1, PHP_INT_MAX, PHP_INT_MAX));
    }

    public function testExactProductRejectsEitherNegativeFactorOfEitherProduct(): void
    {
        foreach ([[-1, 1, 1, 1], [1, -1, 1, 1], [1, 1, -1, 1], [1, 1, 1, -1]] as [$a, $b, $c, $d]) {
            try {
                ExactProduct::compare($a, $b, $c, $d);
                self::fail("compare($a, $b, $c, $d) must be rejected");
            } catch (InvalidConfigurationException $e) {
                self::assertSame('Exact products are defined for non-negative operands only.', $e->getMessage());
            }
        }
        self::assertSame(0, ExactProduct::compare(0, 0, 0, 0), 'zero is a valid factor');
    }

    // ---- ScaledArithmetic::mulAddDivFloorCapped --------------------------------------------------------

    public function testMulAddDivFloorCappedOverTheEdgeGrid(): void
    {
        $edges = self::edges();
        $checked = 0;
        foreach ($edges as $a) {
            foreach ($edges as $b) {
                foreach ([1, 2, 3, 1_000_003, 1 << 40, PHP_INT_MAX] as $d) {
                    foreach (array_unique([0, 1, $d - 1]) as $c) {
                        if ($c >= $d) {
                            continue;
                        }
                        foreach ([0, 1, 10, 1 << 40, PHP_INT_MAX] as $cap) {
                            $numerator = bcadd(bcmul((string) $a, (string) $b), (string) $c);
                            $quotient = bcdiv($numerator, (string) $d, 0);
                            $capped = $cap === 0 || bccomp($quotient, (string) $cap) >= 0;
                            [$q, $r, $isCapped] = ScaledArithmetic::mulAddDivFloorCapped($a, $b, $c, $d, $cap);
                            $label = "mulAddDiv($a, $b, $c, $d, $cap)";
                            self::assertSame($capped, $isCapped, $label . ' capped');
                            self::assertSame($capped ? (string) $cap : $quotient, (string) $q, $label . ' quotient');
                            self::assertSame($capped ? 0 : (int) bcmod($numerator, (string) $d), $r, $label . ' remainder');
                            ++$checked;
                        }
                    }
                }
            }
        }
        self::assertGreaterThan(5_000, $checked);
    }

    public function testQuotientsJustBelowAndAtTheCapAroundTheQuotientOfTheHead(): void
    {
        // a = 5 * d exactly, so the head quotient is 5 * b and the fractional part is zero.
        foreach ([[10, 4, 20], [10, 4, 21], [10, 4, 19]] as [$d, $b, $cap]) {
            $a = 5 * $d;
            [$q, $r, $capped] = ScaledArithmetic::mulAddDivFloorCapped($a, $b, 0, $d, $cap);
            self::assertSame($cap <= 20, $capped, "cap $cap");
            self::assertSame($cap <= 20 ? $cap : 20, $q);
            self::assertSame(0, $r);
        }
    }

    public function testMulAddDivRejectsEveryOperandOutsideItsDomainOnItsOwn(): void
    {
        foreach ([[-1, 1, 0, 2, 1], [1, -1, 0, 2, 1], [1, 1, -1, 2, 1], [1, 1, 0, 0, 1], [1, 1, 0, 2, -1], [1, 1, 2, 2, 1], [1, 1, 3, 2, 1]] as $args) {
            try {
                ScaledArithmetic::mulAddDivFloorCapped(...$args);
                self::fail('(' . implode(', ', $args) . ') must be rejected');
            } catch (InvalidConfigurationException $e) {
                self::assertSame('Arithmetic operands are outside their domain.', $e->getMessage());
            }
        }
        // Zero operands, a zero cap and the largest valid addend (d - 1) are inside the domain.
        self::assertSame([0, 0, false], ScaledArithmetic::mulAddDivFloorCapped(0, 0, 0, 1, 1));
        self::assertSame([0, 1, false], ScaledArithmetic::mulAddDivFloorCapped(0, 5, 1, 2, 1));
        self::assertSame([0, 0, true], ScaledArithmetic::mulAddDivFloorCapped(5, 5, 0, 2, 0));
    }

    // ---- ScaledArithmetic::ceilTicksToCover -------------------------------------------------------------

    public function testCeilTicksToCoverMatchesTheDefinitionOverAGrid(): void
    {
        $checked = 0;
        foreach ([1, 2, 7, 1_000, 1_000_003, 1 << 30] as $deficit) {
            foreach ([1, 3, 1_000_000, 60_000_000_000] as $interval) {
                foreach ([1, 2, 5, 1_000_000, 10_000_000] as $rate) {
                    foreach (array_unique([0, 1, $rate - 1, $rate, $interval - 1]) as $remainder) {
                        if ($remainder < 0) {
                            continue;
                        }
                        $need = bcsub(bcmul((string) $deficit, (string) $interval), (string) $remainder);
                        $expected = bccomp($need, '0') <= 0 ? '0' : bcdiv(bcadd($need, bcsub((string) $rate, '1')), (string) $rate, 0);
                        if (bccomp($expected, (string) PHP_INT_MAX) > 0) {
                            $expected = (string) PHP_INT_MAX; // the result saturates instead of wrapping
                        }
                        self::assertSame($expected, (string) ScaledArithmetic::ceilTicksToCover($deficit, $interval, $rate, $remainder), "ceilTicks($deficit, $interval, $rate, $remainder)");
                        ++$checked;
                    }
                }
            }
        }
        self::assertGreaterThan(300, $checked);
    }

    public function testCeilTicksToCoverEdgesAroundTheRemainder(): void
    {
        // Needs exactly 100 units; each tick yields 10, the stored remainder already covers 0, 5, 10 or 100.
        self::assertSame(10, ScaledArithmetic::ceilTicksToCover(100, 1, 10, 0));
        self::assertSame(10, ScaledArithmetic::ceilTicksToCover(100, 1, 10, 5));
        self::assertSame(9, ScaledArithmetic::ceilTicksToCover(100, 1, 10, 10));
        self::assertSame(0, ScaledArithmetic::ceilTicksToCover(100, 1, 10, 100));
        self::assertSame(0, ScaledArithmetic::ceilTicksToCover(100, 1, 10, 1_000), 'a surplus remainder never yields a negative tick count');
        self::assertSame(1, ScaledArithmetic::ceilTicksToCover(1, 1, 10, 0));
    }

    public function testCeilTicksToCoverSaturatesAtTheLargestInteger(): void
    {
        self::assertSame(PHP_INT_MAX, ScaledArithmetic::ceilTicksToCover(PHP_INT_MAX, PHP_INT_MAX, 1, 0));
        self::assertSame(PHP_INT_MAX, ScaledArithmetic::ceilTicksToCover(1 << 40, 1 << 40, 1, 0));
    }

    public function testCeilTicksToCoverRejectsEveryOperandOutsideItsDomainOnItsOwn(): void
    {
        foreach ([[0, 1, 1, 0], [-1, 1, 1, 0], [1, 0, 1, 0], [1, 1, 0, 0], [1, 1, 1, -1]] as $args) {
            try {
                ScaledArithmetic::ceilTicksToCover(...$args);
                self::fail('(' . implode(', ', $args) . ') must be rejected');
            } catch (InvalidConfigurationException $e) {
                self::assertSame('Arithmetic operands are outside their domain.', $e->getMessage());
            }
        }
        self::assertSame(1, ScaledArithmetic::ceilTicksToCover(1, 1, 1, 0));
    }
}
