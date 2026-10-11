<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Value;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;

final readonly class PositiveInt
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new InvalidConfigurationException('Value must be greater than zero.');
        }
    }
}
