<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Integration;

use Enaxon\Reqtide\Redis\Tests\Support\ExactIntVectors;
use Enaxon\Reqtide\Redis\Tests\Support\ExactIntVectorsFile;
use Enaxon\Reqtide\Redis\Tests\Support\RedisEndpoint;
use PHPUnit\Framework\TestCase;
use Predis\Client;

/**
 * Runs the shared vector file (the same one the Core PHP arithmetic must meet) against the Lua library on a real server.
 * The library text is placed in front of a test-only driver and sent with EVAL; the server's Lua is the system under test.
 */
final class ExactIntLuaTest extends TestCase
{
    private const BATCH = 400;

    private static string $script;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        $library = file_get_contents($root . '/resources/lua/lib/exact_int.lua');
        $driver = file_get_contents($root . '/tests/Support/lua/exact_int_driver.lua');
        self::assertIsString($library);
        self::assertIsString($driver);
        self::$script = $library . "\n" . $driver;
    }

    private function client(): Client
    {
        return new Client(RedisEndpoint::forTest()->parameters(30.0));
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    private function evalDriver(string $operation, array $arguments): array
    {
        $reply = $this->client()->eval(self::$script, 0, $operation, ...$arguments);
        self::assertIsArray($reply);

        return array_map(static function (mixed $item): string {
            self::assertIsString($item);

            return $item;
        }, array_values($reply));
    }

    /**
     * @param list<list<string>> $cases rows whose first $inputs entries are the arguments
     * @return list<array{0: list<string>, 1: list<string>}> each case with its reply slice
     */
    private function runCases(string $operation, array $cases, int $inputs, int $outputs): array
    {
        $paired = [];
        foreach (array_chunk($cases, self::BATCH) as $chunk) {
            $arguments = [];
            foreach ($chunk as $case) {
                array_push($arguments, ...array_slice($case, 0, $inputs));
            }
            $reply = $this->evalDriver($operation, $arguments);
            self::assertCount(count($chunk) * $outputs, $reply, $operation);
            foreach ($chunk as $index => $case) {
                $paired[] = [$case, array_slice($reply, $index * $outputs, $outputs)];
            }
        }

        return $paired;
    }

    public function testRuntimeIsLua51AsAssumedByTheLibrary(): void
    {
        $reply = $this->client()->eval('return {_VERSION, tostring(9007199254740991)}', 0);
        self::assertIsArray($reply);
        self::assertSame('Lua 5.1', $reply[0]);
        self::assertSame('9.007199254741e+15', $reply[1], 'The trap the library exists to avoid must still be real on this server.');
    }

    public function testStrictParserAndTheOnlyFormatter(): void
    {
        $cases = ExactIntVectorsFile::load()['parse'];
        $reply = $this->evalDriver('parse', array_map(static fn(array $case): string => $case[0], $cases));
        foreach ($cases as $index => [$input, $expected]) {
            self::assertSame($expected ?? 'err', $reply[$index], 'parse ' . json_encode($input));
        }

        $cases = ExactIntVectorsFile::load()['format'];
        $reply = $this->evalDriver('format', array_map(static fn(array $case): string => $case[0], $cases));
        foreach ($cases as $index => [$input, $expected]) {
            self::assertSame($expected ?? 'err', $reply[$index], 'format ' . $input);
        }
    }

    public function testMulAddDivFloorCappedMeetsTheVectors(): void
    {
        $count = 0;
        foreach ($this->runCases('mad', ExactIntVectorsFile::load()['mad'], 5, 3) as [$case, $reply]) {
            self::assertSame([$case[5], $case[6], $case[7]], $reply, implode(',', array_slice($case, 0, 5)));
            ++$count;
        }
        self::assertGreaterThan(10_000, $count);
    }

    public function testCompareProductsMeetsTheVectors(): void
    {
        $count = 0;
        foreach ($this->runCases('cmp', ExactIntVectorsFile::load()['cmp'], 4, 1) as [$case, $reply]) {
            self::assertSame($case[4], $reply[0], implode(',', array_slice($case, 0, 4)));
            ++$count;
        }
        self::assertGreaterThan(4_000, $count);
    }

    public function testMulSubCeilDivCappedMeetsTheVectors(): void
    {
        $count = 0;
        foreach ($this->runCases('msc', ExactIntVectorsFile::load()['msc'], 5, 2) as [$case, $reply]) {
            self::assertSame([$case[5], $case[6]], $reply, implode(',', array_slice($case, 0, 5)));
            ++$count;
        }
        self::assertGreaterThan(8_000, $count);
    }

    public function testCompareWeightedSumMeetsTheVectors(): void
    {
        $count = 0;
        foreach ($this->runCases('cws', ExactIntVectorsFile::load()['cws'], 6, 1) as [$case, $reply]) {
            self::assertSame($case[6], $reply[0], implode(',', array_slice($case, 0, 6)));
            ++$count;
        }
        self::assertGreaterThan(5_000, $count);
    }

    public function testLimbOperationsBeyondTheExactRange(): void
    {
        $big = ExactIntVectorsFile::load()['big'];
        foreach (['mul', 'add', 'sub'] as $operation) {
            foreach ($this->runCases($operation, $big[$operation], 2, 1) as [$case, $reply]) {
                self::assertSame($case[2], $reply[0], $operation . ' ' . $case[0] . ' ' . $case[1]);
            }
        }
        foreach ($this->runCases('divmod', $big['divmod'], 2, 2) as [$case, $reply]) {
            self::assertSame([$case[2], $case[3]], $reply, 'divmod ' . $case[0] . ' ' . $case[1]);
        }
    }

    public function testQuotientAndRemainderIdentityOnTheVectors(): void
    {
        self::assertTrue(extension_loaded('bcmath'));
        foreach ($this->runCases('mad', ExactIntVectorsFile::load()['mad'], 5, 3) as [$case, $reply]) {
            if ($reply[2] === '1') {
                self::assertSame($case[4], $reply[0]);
                continue;
            }
            $rebuilt = ExactIntVectors::add(ExactIntVectors::mul($reply[0], $case[3], 0), $reply[1], 0);
            self::assertSame(ExactIntVectors::add(ExactIntVectors::mul($case[0], $case[1], 0), $case[2], 0), $rebuilt, 'q*d+rem for ' . implode(',', array_slice($case, 0, 5)));
            self::assertLessThan(0, ExactIntVectors::comp($reply[1], $case[3]), 'remainder below the divisor');
        }
    }

    public function testDomainViolationsAreRejectedNotRounded(): void
    {
        $names = ['negative', 'fraction', 'above_max', 'zero_divisor', 'empty_divisor', 'sub_below_zero', 'mad_above_max', 'cmp_fraction', 'msc_zero_divisor', 'msc_above_max', 'cws_fraction'];
        self::assertSame(array_fill(0, count($names), 'rejected'), $this->evalDriver('rejects', $names));
    }
}
