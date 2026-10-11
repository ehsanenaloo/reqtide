<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Internal\Arithmetic;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Internal\Arithmetic\BoundedArithmetic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive edge grid (not random sampling) for the bounded arithmetic: every operand domain error one operand at a
 * time, the exact native-path boundaries, capped results (which must report remainder 0 and capped = true) and
 * comparisons that tie. The oracle is bcmath, independent of the library's limb code.
 */
final class BoundedArithmeticBoundaryTest extends TestCase
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
            0, 1, 2, 8191, 8192, 2 ** 32, 3_037_000_499, 3_037_000_500, 2 ** 62, PHP_INT_MAX - 1, PHP_INT_MAX,
        ];
    }

    // ---- domain errors: each operand alone must be rejected ------------------------------------------

    /** @return iterable<string, array{0: callable(): mixed}> */
    public static function domainErrors(): iterable
    {
        yield 'mulAddDiv a<0' => [static fn(): mixed => BoundedArithmetic::mulAddDivFloorCapped(-1, 1, 1, 1, 1)];
        yield 'mulAddDiv b<0' => [static fn(): mixed => BoundedArithmetic::mulAddDivFloorCapped(1, -1, 1, 1, 1)];
        yield 'mulAddDiv addend<0' => [static fn(): mixed => BoundedArithmetic::mulAddDivFloorCapped(1, 1, -1, 1, 1)];
        yield 'mulAddDiv divisor=0' => [static fn(): mixed => BoundedArithmetic::mulAddDivFloorCapped(1, 1, 1, 0, 1)];
        yield 'mulAddDiv divisor<0' => [static fn(): mixed => BoundedArithmetic::mulAddDivFloorCapped(1, 1, 1, -1, 1)];
        yield 'mulAddDiv cap<0' => [static fn(): mixed => BoundedArithmetic::mulAddDivFloorCapped(1, 1, 1, 1, -1)];
        yield 'mulSubCeil a<0' => [static fn(): mixed => BoundedArithmetic::mulSubCeilDivCapped(-1, 1, 1, 1, 1)];
        yield 'mulSubCeil b<0' => [static fn(): mixed => BoundedArithmetic::mulSubCeilDivCapped(1, -1, 1, 1, 1)];
        yield 'mulSubCeil sub<0' => [static fn(): mixed => BoundedArithmetic::mulSubCeilDivCapped(1, 1, -1, 1, 1)];
        yield 'mulSubCeil divisor=0' => [static fn(): mixed => BoundedArithmetic::mulSubCeilDivCapped(1, 1, 1, 0, 1)];
        yield 'mulSubCeil divisor<0' => [static fn(): mixed => BoundedArithmetic::mulSubCeilDivCapped(1, 1, 1, -1, 1)];
        yield 'mulSubCeil cap<0' => [static fn(): mixed => BoundedArithmetic::mulSubCeilDivCapped(1, 1, 1, 1, -1)];
        yield 'compareProducts a<0' => [static fn(): mixed => BoundedArithmetic::compareProducts(-1, 1, 1, 1)];
        yield 'compareProducts b<0' => [static fn(): mixed => BoundedArithmetic::compareProducts(1, -1, 1, 1)];
        yield 'compareProducts c<0' => [static fn(): mixed => BoundedArithmetic::compareProducts(1, 1, -1, 1)];
        yield 'compareProducts d<0' => [static fn(): mixed => BoundedArithmetic::compareProducts(1, 1, 1, -1)];
        yield 'compareWeightedSum a<0' => [static fn(): mixed => BoundedArithmetic::compareWeightedSum(-1, 1, 1, 1, 1, 1)];
        yield 'compareWeightedSum b<0' => [static fn(): mixed => BoundedArithmetic::compareWeightedSum(1, -1, 1, 1, 1, 1)];
        yield 'compareWeightedSum c<0' => [static fn(): mixed => BoundedArithmetic::compareWeightedSum(1, 1, -1, 1, 1, 1)];
        yield 'compareWeightedSum d<0' => [static fn(): mixed => BoundedArithmetic::compareWeightedSum(1, 1, 1, -1, 1, 1)];
        yield 'compareWeightedSum e<0' => [static fn(): mixed => BoundedArithmetic::compareWeightedSum(1, 1, 1, 1, -1, 1)];
        yield 'compareWeightedSum f<0' => [static fn(): mixed => BoundedArithmetic::compareWeightedSum(1, 1, 1, 1, 1, -1)];
    }

    /** @param callable(): mixed $call */
    #[DataProvider('domainErrors')]
    public function testEveryOperandOutsideTheUnsignedDomainIsRejectedOnItsOwn(callable $call): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('outside their unsigned domain');
        $call();
    }

    public function testZeroIsInsideTheDomainEverywhereExceptTheDivisor(): void
    {
        $q = BoundedArithmetic::mulAddDivFloorCapped(0, 0, 0, 1, 0);
        self::assertTrue($q->capped, 'quotient 0 >= cap 0 is capped');
        self::assertSame([0, 0], [$q->quotient, $q->remainder]);
        $q = BoundedArithmetic::mulSubCeilDivCapped(0, 0, 0, 1, 1);
        self::assertSame([0, 0, false], [$q->quotient, $q->remainder, $q->capped]);
        self::assertSame(0, BoundedArithmetic::compareProducts(0, 0, 0, 0));
        self::assertSame(0, BoundedArithmetic::compareWeightedSum(0, 0, 0, 0, 0, 0));
    }

    // ---- results against the oracle over the full edge grid -------------------------------------------

    public function testMulAddDivFloorCappedOverTheEdgeGrid(): void
    {
        $edges = self::edges();
        $checked = 0;
        foreach ($edges as $a) {
            foreach ($edges as $b) {
                foreach ([0, 1, PHP_INT_MAX] as $addend) {
                    foreach ([1, 3, 8192, PHP_INT_MAX] as $divisor) {
                        foreach ([0, 2, 1_000_000_000, PHP_INT_MAX] as $cap) {
                            $numerator = bcadd(bcmul((string) $a, (string) $b), (string) $addend);
                            $quotient = bcdiv($numerator, (string) $divisor, 0);
                            $capped = bccomp($quotient, (string) $cap) >= 0;
                            $actual = BoundedArithmetic::mulAddDivFloorCapped($a, $b, $addend, $divisor, $cap);
                            $label = "mulAddDiv($a, $b, $addend, $divisor, $cap)";
                            self::assertSame($capped, $actual->capped, $label . ' capped');
                            self::assertSame($capped ? (string) $cap : $quotient, (string) $actual->quotient, $label . ' quotient');
                            self::assertSame($capped ? '0' : bcmod($numerator, (string) $divisor), (string) $actual->remainder, $label . ' remainder');
                            ++$checked;
                        }
                    }
                }
            }
        }
        self::assertGreaterThan(5_000, $checked);
    }

    /** a is the largest value for which the native product plus addend still fits, and its neighbours. */
    public function testMulAddDivFloorCappedAtTheExactNativeOverflowBoundary(): void
    {
        foreach ([1, 2, 3, 7, 1_000_003, 3_037_000_499, 3_037_000_500] as $b) {
            foreach ([0, 1, 12_345] as $addend) {
                $limit = intdiv(PHP_INT_MAX - $addend, $b);
                foreach ([$limit - 1, $limit, $limit < PHP_INT_MAX ? $limit + 1 : $limit] as $a) {
                    $numerator = bcadd(bcmul((string) $a, (string) $b), (string) $addend);
                    foreach ([1, 7, 1_000_000_007] as $divisor) {
                        $actual = BoundedArithmetic::mulAddDivFloorCapped($a, $b, $addend, $divisor, PHP_INT_MAX);
                        $expected = bcdiv($numerator, (string) $divisor, 0);
                        $capped = bccomp($expected, (string) PHP_INT_MAX) >= 0;
                        self::assertSame($capped, $actual->capped, "capped a=$a b=$b addend=$addend d=$divisor");
                        self::assertSame($capped ? (string) PHP_INT_MAX : $expected, (string) $actual->quotient, "q a=$a b=$b addend=$addend d=$divisor");
                        self::assertSame($capped ? '0' : bcmod($numerator, (string) $divisor), (string) $actual->remainder, "r a=$a b=$b addend=$addend d=$divisor");
                    }
                }
            }
        }
    }

    public function testMulSubCeilDivCappedOverTheEdgeGrid(): void
    {
        $edges = self::edges();
        $checked = 0;
        foreach ($edges as $a) {
            foreach ($edges as $b) {
                foreach ([0, 1, 3_037_000_500, PHP_INT_MAX] as $subtrahend) {
                    foreach ([1, 3, 8192, PHP_INT_MAX] as $divisor) {
                        foreach ([0, 2, 1_000_000_000, PHP_INT_MAX] as $cap) {
                            $product = bcmul((string) $a, (string) $b);
                            $numerator = bccomp($product, (string) $subtrahend) > 0 ? bcsub($product, (string) $subtrahend) : '0';
                            $quotient = bcdiv($numerator, (string) $divisor, 0);
                            if (bcmod($numerator, (string) $divisor) !== '0') {
                                $quotient = bcadd($quotient, '1');
                            }
                            $capped = bccomp($quotient, (string) $cap) >= 0;
                            $actual = BoundedArithmetic::mulSubCeilDivCapped($a, $b, $subtrahend, $divisor, $cap);
                            $label = "mulSubCeil($a, $b, $subtrahend, $divisor, $cap)";
                            self::assertSame($capped, $actual->capped, $label . ' capped');
                            self::assertSame($capped ? (string) $cap : $quotient, (string) $actual->quotient, $label . ' quotient');
                            self::assertSame(0, $actual->remainder, $label . ' remainder is always zero');
                            ++$checked;
                        }
                    }
                }
            }
        }
        self::assertGreaterThan(5_000, $checked);
    }

    public function testMulSubCeilDivCappedAtTheExactNativeOverflowBoundary(): void
    {
        foreach ([1, 2, 3, 7, 1_000_003, 3_037_000_499, 3_037_000_500] as $b) {
            $limit = intdiv(PHP_INT_MAX, $b);
            foreach ([$limit - 1, $limit, $limit < PHP_INT_MAX ? $limit + 1 : $limit] as $a) {
                $product = bcmul((string) $a, (string) $b);
                foreach ([0, 1, 1_000_000] as $subtrahend) {
                    foreach ([1, 7] as $divisor) {
                        $numerator = bccomp($product, (string) $subtrahend) > 0 ? bcsub($product, (string) $subtrahend) : '0';
                        $expected = bcdiv($numerator, (string) $divisor, 0);
                        if (bcmod($numerator, (string) $divisor) !== '0') {
                            $expected = bcadd($expected, '1');
                        }
                        $actual = BoundedArithmetic::mulSubCeilDivCapped($a, $b, $subtrahend, $divisor, PHP_INT_MAX);
                        $capped = bccomp($expected, (string) PHP_INT_MAX) >= 0;
                        self::assertSame($capped, $actual->capped, "capped a=$a b=$b sub=$subtrahend d=$divisor");
                        self::assertSame($capped ? (string) PHP_INT_MAX : $expected, (string) $actual->quotient, "q a=$a b=$b sub=$subtrahend d=$divisor");
                        self::assertSame(0, $actual->remainder, "r a=$a b=$b sub=$subtrahend d=$divisor");
                    }
                }
            }
        }
    }

    /** A product that equals the subtrahend leaves nothing to divide; one more unit rounds up to 1. */
    public function testMulSubCeilDivRoundingAroundTheSubtrahend(): void
    {
        $exact = BoundedArithmetic::mulSubCeilDivCapped(6, 7, 42, 5, 100);
        self::assertSame([0, 0, false], [$exact->quotient, $exact->remainder, $exact->capped]);
        $oneAbove = BoundedArithmetic::mulSubCeilDivCapped(6, 7, 41, 5, 100);
        self::assertSame([1, 0, false], [$oneAbove->quotient, $oneAbove->remainder, $oneAbove->capped]);
        $oneBelow = BoundedArithmetic::mulSubCeilDivCapped(6, 7, 43, 5, 100);
        self::assertSame([0, 0, false], [$oneBelow->quotient, $oneBelow->remainder, $oneBelow->capped]);
        // Limb path: both operands large enough that the native product overflows.
        $big = BoundedArithmetic::mulSubCeilDivCapped(PHP_INT_MAX, 2, PHP_INT_MAX, 1, PHP_INT_MAX);
        self::assertSame(['9223372036854775807', true], [(string) $big->quotient, $big->capped]);
        $tie = BoundedArithmetic::mulSubCeilDivCapped(PHP_INT_MAX, PHP_INT_MAX, 0, PHP_INT_MAX, PHP_INT_MAX);
        self::assertTrue($tie->capped);
    }

    public function testCapEqualToTheQuotientIsCapped(): void
    {
        // quotient == cap is capped (>=), quotient == cap - 1 is not; both numeric paths.
        $native = BoundedArithmetic::mulAddDivFloorCapped(10, 10, 0, 10, 10);
        self::assertSame([10, 0, true], [$native->quotient, $native->remainder, $native->capped]);
        $below = BoundedArithmetic::mulAddDivFloorCapped(10, 10, 0, 10, 11);
        self::assertSame([10, 0, false], [$below->quotient, $below->remainder, $below->capped]);
        $limb = BoundedArithmetic::mulAddDivFloorCapped(PHP_INT_MAX, 4, 0, 4, PHP_INT_MAX);
        self::assertSame([PHP_INT_MAX, 0, true], [$limb->quotient, $limb->remainder, $limb->capped]);
        $limbBelow = BoundedArithmetic::mulAddDivFloorCapped(PHP_INT_MAX - 1, 4, 3, 4, PHP_INT_MAX);
        self::assertSame([PHP_INT_MAX - 1, 3, false], [$limbBelow->quotient, $limbBelow->remainder, $limbBelow->capped]);
    }

    // ---- comparisons --------------------------------------------------------------------------------

    public function testCompareProductsOverTheEdgeGrid(): void
    {
        $edges = self::edges();
        foreach ($edges as $a) {
            foreach ($edges as $b) {
                foreach ($edges as $c) {
                    foreach ([0, 1, 2, 3_037_000_500, PHP_INT_MAX] as $d) {
                        $expected = bccomp(bcmul((string) $a, (string) $b), bcmul((string) $c, (string) $d));
                        self::assertSame($expected, BoundedArithmetic::compareProducts($a, $b, $c, $d), "compareProducts($a, $b, $c, $d)");
                    }
                }
            }
        }
    }

    public function testCompareProductsWhereExactlyOneSideOverflowsTheNativeRange(): void
    {
        // a*b overflows, c*d does not (and the reverse): the native shortcut must not be taken for either.
        self::assertSame(1, BoundedArithmetic::compareProducts(PHP_INT_MAX, 2, 5, 5));
        self::assertSame(-1, BoundedArithmetic::compareProducts(5, 5, PHP_INT_MAX, 2));
        self::assertSame(1, BoundedArithmetic::compareProducts(PHP_INT_MAX, 2, 1, 1));
        self::assertSame(-1, BoundedArithmetic::compareProducts(1, 1, 2, PHP_INT_MAX));
        self::assertSame(0, BoundedArithmetic::compareProducts(PHP_INT_MAX, 2, 2, PHP_INT_MAX));
        // 3_037_000_500^2 > PHP_INT_MAX but 3_037_000_499^2 is not.
        self::assertSame(1, BoundedArithmetic::compareProducts(3_037_000_500, 3_037_000_500, 3_037_000_499, 3_037_000_499));
        self::assertSame(-1, BoundedArithmetic::compareProducts(3_037_000_499, 3_037_000_499, 3_037_000_500, 3_037_000_500));
    }

    public function testCompareWeightedSumOverTheEdgeGrid(): void
    {
        $values = [0, 1, 2, 3_037_000_499, 3_037_000_500, 2 ** 62, PHP_INT_MAX];
        foreach ($values as $a) {
            foreach ([0, 1, 3_037_000_500, PHP_INT_MAX] as $b) {
                foreach ($values as $c) {
                    foreach ([0, 1, 3_037_000_500] as $d) {
                        foreach ([0, 1, 2 ** 62, PHP_INT_MAX] as $e) {
                            foreach ([0, 1, 2, PHP_INT_MAX] as $f) {
                                $left = bcadd(bcmul((string) $a, (string) $b), bcmul((string) $c, (string) $d));
                                $expected = bccomp($left, bcmul((string) $e, (string) $f));
                                self::assertSame($expected, BoundedArithmetic::compareWeightedSum($a, $b, $c, $d, $e, $f), "compareWeightedSum($a, $b, $c, $d, $e, $f)");
                            }
                        }
                    }
                }
            }
        }
    }

    public function testCompareWeightedSumAtTheExactSumOverflowBoundary(): void
    {
        // left + second == PHP_INT_MAX exactly (fits natively), and one more unit (does not).
        $half = intdiv(PHP_INT_MAX, 2);
        self::assertSame(0, BoundedArithmetic::compareWeightedSum($half, 1, $half + 1, 1, PHP_INT_MAX, 1));
        self::assertSame(1, BoundedArithmetic::compareWeightedSum($half + 1, 1, $half + 1, 1, PHP_INT_MAX, 1));
        self::assertSame(-1, BoundedArithmetic::compareWeightedSum($half, 1, $half, 1, PHP_INT_MAX, 1));
        // One factor product alone overflows while the other two products fit.
        self::assertSame(-1, BoundedArithmetic::compareWeightedSum(1, 1, 1, 1, PHP_INT_MAX, 2));
        self::assertSame(1, BoundedArithmetic::compareWeightedSum(PHP_INT_MAX, 2, 1, 1, 1, 1));
        self::assertSame(1, BoundedArithmetic::compareWeightedSum(1, 1, PHP_INT_MAX, 2, 1, 1));
        self::assertSame(0, BoundedArithmetic::compareWeightedSum(PHP_INT_MAX, 2, 0, 0, 2, PHP_INT_MAX));
    }
}
