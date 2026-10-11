<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Value;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;

/**
 * Non-empty printable ASCII identity of at most 128 bytes. Never a raw credential.
 */
abstract readonly class BoundedId
{
    final public function __construct(public string $value)
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $value) !== 1) {
            throw new InvalidConfigurationException(
                static::class . ' must match [A-Za-z0-9][A-Za-z0-9._-]{0,127}.',
            );
        }
    }

    final public function equals(self $other): bool
    {
        return $other::class === static::class && $other->value === $this->value;
    }

    final public function __toString(): string
    {
        return $this->value;
    }
}
