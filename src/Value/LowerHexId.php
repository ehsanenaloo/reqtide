<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Value;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;

/**
 * 128-bit identity rendered as exactly 32 lowercase hexadecimal characters.
 *
 * Parsing only validates shape and grants no ownership; backends compare the full token
 * against their authoritative active record.
 */
abstract readonly class LowerHexId
{
    final protected function __construct(public string $lowerHex)
    {
        if (preg_match('/\A[0-9a-f]{32}\z/', $lowerHex) !== 1) {
            throw new InvalidConfigurationException(static::class . ' must be exactly 32 lowercase hex characters.');
        }
    }

    final public function equals(self $other): bool
    {
        return $other::class === static::class && hash_equals($this->lowerHex, $other->lowerHex);
    }

    final public function __toString(): string
    {
        return $this->lowerHex;
    }
}
