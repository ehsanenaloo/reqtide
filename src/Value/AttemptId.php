<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Value;

final readonly class AttemptId extends LowerHexId
{
    public static function fromLowerHex(string $value): self
    {
        return new self($value);
    }
}
