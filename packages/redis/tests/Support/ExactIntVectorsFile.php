<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Support;

/**
 * Reads tests/Vectors/exact_int.json (decimal strings throughout).
 */
final class ExactIntVectorsFile
{
    public static function path(): string
    {
        return dirname(__DIR__) . '/Vectors/exact_int.json';
    }

    /**
     * @return array{parse: list<array{0: string, 1: ?string}>, format: list<array{0: string, 1: ?string}>, mad: list<list<string>>, cmp: list<list<string>>, msc: list<list<string>>, cws: list<list<string>>, big: array<string, list<list<string>>>}
     */
    public static function load(): array
    {
        $text = file_get_contents(self::path());
        if ($text === false) {
            throw new \RuntimeException('Vector file is missing: ' . self::path());
        }

        /** @var array{parse: list<array{0: string, 1: ?string}>, format: list<array{0: string, 1: ?string}>, mad: list<list<string>>, cmp: list<list<string>>, msc: list<list<string>>, cws: list<list<string>>, big: array<string, list<list<string>>>} */
        return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }
}
