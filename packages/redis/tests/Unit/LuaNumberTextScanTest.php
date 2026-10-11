<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Unit;

use Enaxon\Reqtide\Redis\Tests\Support\LuaNumberTextScan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LuaNumberTextScanTest extends TestCase
{
    public function testShippedLuaHasNoNumberTextConversionOutsideTheAuditedFunctions(): void
    {
        $root = dirname(__DIR__, 2) . '/resources/lua';
        $files = glob($root . '/{lib,scripts}/*.lua', GLOB_BRACE) ?: [];
        self::assertNotSame([], $files, 'No shipped Lua found; the scan would prove nothing.');

        foreach ($files as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);
            self::assertSame([], LuaNumberTextScan::scan($source), $file);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function violations(): iterable
    {
        yield 'tostring' => ["local s = tostring(n)\n", 'tostring'];
        yield 'concat operator with a number' => ["local s = 'x' .. n\n", 'concatenation operator'];
        yield 'table.concat' => ["return table.concat({n})\n", 'concat'];
        yield 'string.format outside format_int' => ["local s = string.format('%d', n)\n", 'format outside format_int'];
        yield 'method format' => ["local s = ('%d'):format(n)\n", 'format outside format_int'];
        yield 'tonumber outside parse_int' => ["local n = tonumber(ARGV[1])\n", 'tonumber outside parse_int'];
        yield 'format inside the parse_int region' => ["-- BEGIN parse_int\nlocal s = string.format('%d', n)\n-- END parse_int\n", 'format outside format_int'];
        yield 'tonumber inside the format_int region' => ["-- BEGIN format_int\nlocal n = tonumber(x)\n-- END format_int\n", 'tonumber outside parse_int'];
        yield 'after a region ends' => ["-- BEGIN format_int\n-- END format_int\nlocal s = string.format('%d', n)\n", 'format outside format_int'];
        yield 'concat inside the parse_int region' => ["-- BEGIN parse_int\nreturn table.concat({n})\n-- END parse_int\n", 'concat'];
        yield 'operator after the join_text region ends' => ["-- BEGIN join_text\n-- END join_text\nlocal s = 'x' .. n\n", 'concatenation operator'];
        yield 'tostring inside the join_text region' => ["-- BEGIN join_text\nlocal s = tostring(n)\n-- END join_text\n", 'tostring'];
        yield 'violation after a string on the same line' => ["local a = 'ok' local s = tostring(n)\n", 'tostring'];
    }

    #[DataProvider('violations')]
    public function testScanDetectsAViolation(string $source, string $rule): void
    {
        $rules = array_column(LuaNumberTextScan::scan($source), 'rule');
        self::assertContains($rule, $rules);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowed(): iterable
    {
        yield 'format inside format_int region' => ["-- BEGIN format_int\nreturn string.format('%.0f', n)\n-- END format_int\n"];
        yield 'tonumber inside parse_int region' => ["-- BEGIN parse_int\nreturn tonumber(text)\n-- END parse_int\n"];
        yield 'concat and operator inside join_text region' => ["-- BEGIN join_text\nreturn table.concat(parts, ' ') .. ''\n-- END join_text\n"];
        yield 'banned words in a comment' => ["-- tostring(n) .. n, string.format, tonumber\nlocal a = 1\n"];
        yield 'banned words in a block comment' => ["--[[ tostring(n)\n .. tonumber ]]\nlocal a = 1\n"];
        yield 'banned words in a string literal' => ["error('do not use tostring or .. or tonumber')\n"];
        yield 'escaped quote in a string' => ["local m = 'it\\'s .. fine'\n"];
        yield 'format_int identifier' => ["local s = exact_int.format_int(n)\n"];
        yield 'varargs-free code' => ["local a = {}\nfor i = 1, 3 do a[i] = i end\n"];
    }

    #[DataProvider('allowed')]
    public function testScanAcceptsAuditedCode(string $source): void
    {
        self::assertSame([], LuaNumberTextScan::scan($source));
    }

    public function testViolationLineNumbersSurviveBlockComments(): void
    {
        $violations = LuaNumberTextScan::scan("--[[\nline two\n]]\nlocal s = tostring(n)\n");
        self::assertSame([['line' => 4, 'rule' => 'tostring']], $violations);
    }
}
