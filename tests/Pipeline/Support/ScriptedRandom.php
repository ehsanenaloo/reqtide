<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline\Support;

use Enaxon\Reqtide\Time\RandomSource;

/** Returns the maximum (or minimum) of every requested range and records the ranges it was asked for. */
final class ScriptedRandom implements RandomSource
{
    /** @var list<array{int, int}> */
    public array $ranges = [];

    /** When set, every draw returns this value regardless of the requested range (to exercise range checks). */
    public ?int $forced = null;

    /** When set, every draw throws it. */
    public ?\Throwable $failure = null;

    public function __construct(private readonly bool $high = true) {}

    public function integer(int $minimumInclusive, int $maximumInclusive): int
    {
        $this->ranges[] = [$minimumInclusive, $maximumInclusive];
        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->forced !== null) {
            return $this->forced;
        }

        return $this->high ? $maximumInclusive : $minimumInclusive;
    }
}
