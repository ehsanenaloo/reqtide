<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Time;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;

final class SecureRandomSource implements RandomSource
{
    public function integer(int $minimumInclusive, int $maximumInclusive): int
    {
        if ($minimumInclusive > $maximumInclusive) {
            throw new InvalidConfigurationException('Random range minimum must not exceed maximum.');
        }

        return random_int($minimumInclusive, $maximumInclusive);
    }
}
