<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Testing;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Time\RandomSource;

/**
 * RandomSource that returns a fixed queue of integers, so a test can pin jitter exactly.
 *
 * An exhausted queue or a value outside the requested range is a test bug and throws loudly.
 *
 * @api-for-tests
 */
final class ScriptedRandomSource implements RandomSource
{
    /** @var list<int> */
    private array $queue;
    /** @var list<array{int, int}> */
    private array $requests = [];

    /** @param list<int> $values */
    public function __construct(array $values)
    {
        $this->queue = $values;
    }

    public function enqueue(int ...$values): void
    {
        foreach ($values as $value) {
            $this->queue[] = $value;
        }
    }

    /**
     * @throws InvalidConfigurationException when the minimum exceeds the maximum
     * @throws \UnderflowException when no scripted value is left
     * @throws \OutOfRangeException when the next scripted value lies outside the requested range
     */
    public function integer(int $minimumInclusive, int $maximumInclusive): int
    {
        if ($minimumInclusive > $maximumInclusive) {
            throw new InvalidConfigurationException('Random range minimum must not exceed maximum.');
        }
        $this->requests[] = [$minimumInclusive, $maximumInclusive];
        if ($this->queue === []) {
            throw new \UnderflowException('ScriptedRandomSource queue is exhausted.');
        }
        $value = array_shift($this->queue);
        if ($value < $minimumInclusive || $value > $maximumInclusive) {
            throw new \OutOfRangeException(sprintf('Scripted value %d is outside [%d, %d].', $value, $minimumInclusive, $maximumInclusive));
        }

        return $value;
    }

    public function remaining(): int
    {
        return count($this->queue);
    }

    /** @return list<array{int, int}> every requested [minimum, maximum] range, in order */
    public function requests(): array
    {
        return $this->requests;
    }
}
