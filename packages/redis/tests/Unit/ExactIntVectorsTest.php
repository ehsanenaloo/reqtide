<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Unit;

use Enaxon\Reqtide\Internal\Arithmetic\BoundedArithmetic;
use Enaxon\Reqtide\Redis\Tests\Support\ExactIntVectors;
use Enaxon\Reqtide\Redis\Tests\Support\ExactIntVectorsFile;
use PHPUnit\Framework\TestCase;

/**
 * The vector file is the single bar for the Lua library (integration suite) and for the Core PHP arithmetic (here).
 */
final class ExactIntVectorsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        self::assertTrue(extension_loaded('bcmath'), 'ext-bcmath is required: it is the independent oracle of the vectors.');
    }

    public function testCommittedFileMatchesTheOracleGeneration(): void
    {
        $file = ExactIntVectorsFile::path();
        self::assertFileExists($file);
        self::assertSame(
            ExactIntVectors::encode(ExactIntVectors::generate()),
            file_get_contents($file),
            'tests/Vectors/exact_int.json is stale; run php packages/redis/tools/generate-exact-int-vectors.php',
        );
    }

    public function testVectorsCoverTheIntendedBoundaries(): void
    {
        $vectors = ExactIntVectorsFile::load();
        self::assertGreaterThan(10_000, count($vectors['mad']));
        self::assertGreaterThan(4_000, count($vectors['cmp']));
        $capped = array_filter($vectors['mad'], static fn(array $case): bool => $case[7] === '1');
        $limbRange = array_filter(
            $vectors['mad'],
            static fn(array $case): bool => $case[7] === '0' && ExactIntVectors::comp(ExactIntVectors::mul($case[0], $case[1], 0), ExactIntVectors::EXACT_MAX) > 0,
        );
        self::assertGreaterThan(1_000, count($capped), 'The capped branch must be exercised.');
        self::assertGreaterThan(500, count($limbRange), 'Products above the exact range with an uncapped quotient must be exercised.');
        foreach (['lt', 'eq', 'gt'] as $order) {
            self::assertNotEmpty(array_filter($vectors['cmp'], static fn(array $case): bool => $case[4] === $order), $order);
        }
    }

    public function testCoreMulAddDivFloorCappedMeetsTheVectors(): void
    {
        foreach (ExactIntVectorsFile::load()['mad'] as $case) {
            [$a, $b, $r, $d, $cap, $quotient, $remainder, $capped] = $case;
            $result = BoundedArithmetic::mulAddDivFloorCapped((int) $a, (int) $b, (int) $r, (int) $d, (int) $cap);
            $label = implode(',', [$a, $b, $r, $d, $cap]);
            self::assertSame((int) $quotient, $result->quotient, $label);
            self::assertSame($capped === '1', $result->capped, $label);
            if ($capped === '0') {
                self::assertSame((int) $remainder, $result->remainder, $label);
            }
        }
    }

    public function testCoreCompareProductsMeetsTheVectors(): void
    {
        $names = ['lt' => -1, 'eq' => 0, 'gt' => 1];
        foreach (ExactIntVectorsFile::load()['cmp'] as [$a, $b, $c, $d, $order]) {
            self::assertSame($names[$order], BoundedArithmetic::compareProducts((int) $a, (int) $b, (int) $c, (int) $d), "$a*$b vs $c*$d");
        }
    }

    public function testCoreMulSubCeilDivCappedMeetsTheVectors(): void
    {
        foreach (ExactIntVectorsFile::load()['msc'] as [$a, $b, $c, $d, $cap, $quotient, $capped]) {
            $result = BoundedArithmetic::mulSubCeilDivCapped((int) $a, (int) $b, (int) $c, (int) $d, (int) $cap);
            $label = implode(',', [$a, $b, $c, $d, $cap]);
            self::assertSame((int) $quotient, $result->quotient, $label);
            self::assertSame($capped === '1', $result->capped, $label);
        }
    }

    public function testCoreCompareWeightedSumMeetsTheVectors(): void
    {
        $names = ['lt' => -1, 'eq' => 0, 'gt' => 1];
        foreach (ExactIntVectorsFile::load()['cws'] as [$a, $b, $c, $d, $e, $f, $order]) {
            self::assertSame($names[$order], BoundedArithmetic::compareWeightedSum((int) $a, (int) $b, (int) $c, (int) $d, (int) $e, (int) $f), "$a*$b+$c*$d vs $e*$f");
        }
    }

    public function testNewGroupsCoverTheirBoundaries(): void
    {
        $vectors = ExactIntVectorsFile::load();
        self::assertGreaterThan(8_000, count($vectors['msc']));
        self::assertGreaterThan(5_000, count($vectors['cws']));
        self::assertNotEmpty(array_filter($vectors['msc'], static fn(array $case): bool => $case[6] === '1'), 'capped');
        self::assertNotEmpty(array_filter($vectors['msc'], static fn(array $case): bool => $case[6] === '0' && ExactIntVectors::comp(ExactIntVectors::mul($case[0], $case[1], 0), ExactIntVectors::EXACT_MAX) > 0), 'products above the exact range, uncapped');
        foreach (['lt', 'eq', 'gt'] as $order) {
            self::assertGreaterThan(100, count(array_filter($vectors['cws'], static fn(array $case): bool => $case[6] === $order)), $order);
        }
    }
}
