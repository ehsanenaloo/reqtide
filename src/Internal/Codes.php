<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Internal;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;

/**
 * Shared validators for bounded stable codes and printable ASCII references.
 *
 * Internal helper, not part of the public API catalogue.
 *
 * @internal
 */
final class Codes
{
    private function __construct() {}

    /** Bounded stable code: [A-Za-z0-9._-]{1,64}. */
    public static function code(string $value, string $what): string
    {
        if (preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $value) !== 1) {
            throw new InvalidConfigurationException($what . ' must match [A-Za-z0-9._-]{1,64}.');
        }

        return $value;
    }

    /** Non-empty printable ASCII of at most $maxBytes bytes. */
    public static function printable(string $value, int $maxBytes, string $what): string
    {
        if ($value === '' || strlen($value) > $maxBytes || preg_match('/\A[\x20-\x7e]+\z/', $value) !== 1) {
            throw new InvalidConfigurationException(
                $what . ' must be non-empty printable ASCII of at most ' . $maxBytes . ' bytes.',
            );
        }

        return $value;
    }
}
