<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Value;

use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;

/** Exact positive generation in [1, 2^53-1]. Never wraps: {@see next()} fails at the ceiling. */
final readonly class Generation
{
    public const MAX = 9_007_199_254_740_991;

    public function __construct(public int $value)
    {
        if ($value < 1 || $value > self::MAX) {
            throw new InvalidConfigurationException('Generation must be in [1, 9007199254740991].');
        }
    }

    public static function first(): self
    {
        return new self(1);
    }

    /** @throws CorruptStateException when the generation space is exhausted */
    public function next(): self
    {
        if ($this->value >= self::MAX) {
            throw new CorruptStateException('Generation space exhausted.');
        }

        return new self($this->value + 1);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
