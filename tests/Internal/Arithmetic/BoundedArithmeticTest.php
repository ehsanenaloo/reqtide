<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Internal\Arithmetic;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Internal\Arithmetic\BoundedArithmetic;
use Enaxon\Reqtide\Internal\Arithmetic\Limbs;
use PHPUnit\Framework\TestCase;

/**
 * Compares the native fast path and the limb path with an independent decimal big-integer oracle (bcmath).
 */
final class BoundedArithmeticTest extends TestCase
{
    private const ITERATIONS = 3_000;

    protected function setUp(): void
    {
        if (!extension_loaded('bcmath')) {
            self::markTestSkipped('bcmath is required as the independent big-integer oracle.');
        }
        mt_srand(20_261_008);
    }

    public function testLimbRoundTripAndBaseEdges(): void
    {
        self::assertSame([], Limbs::fromInt(0));
        self::assertSame([8191], Limbs::fromInt(8191));
        self::assertSame([0, 1], Limbs::fromInt(8192));
        self::assertSame(PHP_INT_MAX, Limbs::toInt(Limbs::fromInt(PHP_INT_MAX)));
        self::assertSame(0, Limbs::toInt([]));
        // PHP_INT_MAX + 1 does not fit a native integer.
        self::assertNull(Limbs::toInt(Limbs::add(Limbs::fromInt(PHP_INT_MAX), [1])));
        self::assertSame(['9223372036854775808'], [$this->dec(Limbs::add(Limbs::fromInt(PHP_INT_MAX), [1]))]);
    }

    public function testLimbOperationsMatchOracle(): void
    {
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $a = $this->wide();
            $b = $this->wide();
            self::assertSame(bcadd($a, $b), $this->dec(Limbs::add($this->limbs($a), $this->limbs($b))), "add $a $b");
            self::assertSame(bcmul($a, $b), $this->dec(Limbs::multiply($this->limbs($a), $this->limbs($b))), "mul $a $b");
            self::assertSame(bccomp($a, $b), Limbs::compare($this->limbs($a), $this->limbs($b)), "cmp $a $b");

            [$hi, $lo] = bccomp($a, $b) >= 0 ? [$a, $b] : [$b, $a];
            self::assertSame(bcsub($hi, $lo), $this->dec(Limbs::subtract($this->limbs($hi), $this->limbs($lo))), "sub $hi $lo");

            if ($lo !== '0') {
                [$quotient, $remainder] = Limbs::divide($this->limbs($hi), $this->limbs($lo));
                self::assertSame(bcdiv($hi, $lo, 0), $this->dec($quotient), "div $hi $lo");
                self::assertSame(bcmod($hi, $lo), $this->dec($remainder), "mod $hi $lo");
                // Quotient/remainder identity.
                self::assertSame($hi, bcadd(bcmul($this->dec($quotient), $lo), $this->dec($remainder)));
                self::assertSame(-1, bccomp($this->dec($remainder), $lo));
            }
        }
    }

    public function testLimbSubtractBelowZeroAndDivideByZeroAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Limbs::subtract([1], [2]);
    }

    public function testLimbDivideByZero(): void
    {
        $this->expectException(\DivisionByZeroError::class);
        Limbs::divide([5], []);
    }

    public function testMulAddDivFloorCappedMatchesOracle(): void
    {
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $a = $this->int();
            $b = $this->int();
            $addend = $this->int();
            $divisor = max(1, $this->int());
            $cap = $this->int();

            $exact = bcdiv(bcadd(bcmul((string) $a, (string) $b), (string) $addend), (string) $divisor, 0);
            $result = BoundedArithmetic::mulAddDivFloorCapped($a, $b, $addend, $divisor, $cap);

            if (bccomp($exact, (string) $cap) >= 0) {
                self::assertTrue($result->capped, "capped $a $b $addend $divisor $cap");
                self::assertSame($cap, $result->quotient);
            } else {
                self::assertFalse($result->capped, "uncapped $a $b $addend $divisor $cap");
                self::assertSame($exact, (string) $result->quotient);
                $remainder = bcmod(bcadd(bcmul((string) $a, (string) $b), (string) $addend), (string) $divisor);
                self::assertSame($remainder, (string) $result->remainder);
            }
        }
    }

    public function testMulSubCeilDivCappedMatchesOracleAndNeverUnderestimates(): void
    {
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $a = $this->int();
            $b = $this->int();
            $sub = $this->int();
            $divisor = max(1, $this->int());
            $cap = PHP_INT_MAX;

            $product = bcmul((string) $a, (string) $b);
            $numerator = bccomp($product, (string) $sub) > 0 ? bcsub($product, (string) $sub) : '0';
            $floor = bcdiv($numerator, (string) $divisor, 0);
            $exact = bcmod($numerator, (string) $divisor) === '0' ? $floor : bcadd($floor, '1');
            $result = BoundedArithmetic::mulSubCeilDivCapped($a, $b, $sub, $divisor, $cap);

            if (bccomp($exact, (string) $cap) >= 0) {
                self::assertTrue($result->capped);
            } else {
                self::assertSame($exact, (string) $result->quotient, "ceil $a $b $sub $divisor");
                // result * divisor must reach the numerator (never early) and one less must not.
                self::assertGreaterThanOrEqual(0, bccomp(bcmul((string) $result->quotient, (string) $divisor), $numerator));
                if ($result->quotient > 0) {
                    self::assertLessThan(0, bccomp(bcmul((string) ($result->quotient - 1), (string) $divisor), $numerator));
                }
            }
        }
    }

    public function testCompareProductsMatchesOracle(): void
    {
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            [$a, $b, $c, $d] = [$this->int(), $this->int(), $this->int(), $this->int()];
            self::assertSame(
                bccomp(bcmul((string) $a, (string) $b), bcmul((string) $c, (string) $d)),
                BoundedArithmetic::compareProducts($a, $b, $c, $d),
                "$a*$b ? $c*$d",
            );
        }
        // Equal products reached by different factorizations, including one beyond int64.
        self::assertSame(0, BoundedArithmetic::compareProducts(PHP_INT_MAX, 2, 2, PHP_INT_MAX));
        self::assertSame(1, BoundedArithmetic::compareProducts(PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX - 1));
        self::assertSame(-1, BoundedArithmetic::compareProducts(0, PHP_INT_MAX, 1, 1));
    }

    public function testCompareWeightedSumMatchesOracle(): void
    {
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            [$a, $b, $c, $d, $e, $f] = [$this->int(), $this->int(), $this->int(), $this->int(), $this->int(), $this->int()];
            $left = bcadd(bcmul((string) $a, (string) $b), bcmul((string) $c, (string) $d));
            self::assertSame(
                bccomp($left, bcmul((string) $e, (string) $f)),
                BoundedArithmetic::compareWeightedSum($a, $b, $c, $d, $e, $f),
                "$a*$b+$c*$d ? $e*$f",
            );
        }
        // Sum of two native-safe products that overflows int64 must still compare exactly.
        $half = intdiv(PHP_INT_MAX, 2) + 1;
        self::assertSame(1, BoundedArithmetic::compareWeightedSum($half, 1, $half, 1, PHP_INT_MAX, 1));
    }

    public function testBoundaryOperandsAtInt64Maximum(): void
    {
        // (2^63-1)^2 / (2^63-1) = 2^63-1 reaches the cap without any native overflow on the way.
        $result = BoundedArithmetic::mulAddDivFloorCapped(PHP_INT_MAX, PHP_INT_MAX, 0, PHP_INT_MAX, PHP_INT_MAX);
        self::assertTrue($result->capped);
        $result = BoundedArithmetic::mulAddDivFloorCapped(PHP_INT_MAX, PHP_INT_MAX, 5, PHP_INT_MAX, PHP_INT_MAX);
        self::assertTrue($result->capped);
        $result = BoundedArithmetic::mulAddDivFloorCapped(PHP_INT_MAX, 3, 7, PHP_INT_MAX, 100);
        self::assertFalse($result->capped);
        self::assertSame(3, $result->quotient);
        self::assertSame(7, $result->remainder);
        // cap zero is always reached.
        self::assertTrue(BoundedArithmetic::mulAddDivFloorCapped(0, 0, 0, 1, 0)->capped);
    }

    public function testNegativeAndZeroDivisorDomainsAreRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        BoundedArithmetic::mulAddDivFloorCapped(-1, 1, 0, 1, 1);
    }

    public function testZeroDivisorIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        BoundedArithmetic::mulSubCeilDivCapped(1, 1, 0, 0, 1);
    }

    /** A random non-negative native integer whose bit length is itself random, with frequent boundary values. */
    private function int(): int
    {
        $pick = mt_rand(0, 19);
        if ($pick === 0) {
            return PHP_INT_MAX;
        }
        if ($pick === 1) {
            return 0;
        }
        if ($pick === 2) {
            return 9_007_199_254_740_991;
        }
        if ($pick === 3) {
            return 8192 ** mt_rand(0, 4);
        }

        $value = ((mt_rand() << 33) ^ (mt_rand() << 2) ^ mt_rand(0, 3)) & PHP_INT_MAX;

        return $value >> mt_rand(0, 62);
    }

    /**
     * A decimal string up to about 130 bits, built from native pieces.
     *
     * @return numeric-string
     */
    private function wide(): string
    {
        $value = (string) $this->int();
        if (mt_rand(0, 1) === 1) {
            $value = bcadd(bcmul($value, '9223372036854775808'), (string) $this->int());
        }

        return $value;
    }

    /**
     * @param numeric-string $decimal
     * @return list<int>
     */
    private function limbs(string $decimal): array
    {
        $limbs = [];
        while ($decimal !== '0') {
            $limbs[] = (int) bcmod($decimal, '8192');
            $decimal = bcdiv($decimal, '8192', 0);
        }

        return $limbs;
    }

    /**
     * @param list<int> $limbs
     * @return numeric-string
     */
    private function dec(array $limbs): string
    {
        $value = '0';
        for ($i = count($limbs) - 1; $i >= 0; --$i) {
            $value = bcadd(bcmul($value, '8192'), (string) $limbs[$i]);
        }

        return $value;
    }
}
