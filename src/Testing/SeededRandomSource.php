<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Testing;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Time\RandomSource;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Reproducible RandomSource: the same seed yields the same sequence on every run.
 *
 * @api-for-tests
 */
final class SeededRandomSource implements RandomSource
{
    private readonly Randomizer $randomizer;
    private int $draws = 0;

    public function __construct(public readonly int $seed)
    {
        $this->randomizer = new Randomizer(new Mt19937($seed));
    }

    /** @throws InvalidConfigurationException when the minimum exceeds the maximum */
    public function integer(int $minimumInclusive, int $maximumInclusive): int
    {
        if ($minimumInclusive > $maximumInclusive) {
            throw new InvalidConfigurationException('Random range minimum must not exceed maximum.');
        }
        ++$this->draws;

        return $this->randomizer->getInt($minimumInclusive, $maximumInclusive);
    }

    public function draws(): int
    {
        return $this->draws;
    }
}
