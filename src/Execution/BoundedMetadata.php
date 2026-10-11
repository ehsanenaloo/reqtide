<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Execution;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;

/**
 * At most 32 entries; keys are 1..64 printable ASCII bytes; string values are valid UTF-8 of at most 256 bytes.
 * Never holds bodies, URLs, headers or credentials.
 */
final readonly class BoundedMetadata
{
    /** @param array<string, bool|int|string> $values */
    public function __construct(public array $values)
    {
        if (count($values) > 32) {
            throw new InvalidConfigurationException('Metadata holds at most 32 entries.');
        }
        foreach ($values as $key => $value) {
            $name = (string) $key;
            if ($name === '' || strlen($name) > 64 || preg_match('/\A[\x20-\x7e]+\z/', $name) !== 1) {
                throw new InvalidConfigurationException('Metadata keys must be 1..64 printable ASCII bytes.');
            }
            if (!is_bool($value) && !is_int($value) && !is_string($value)) {
                throw new InvalidConfigurationException('Metadata values must be bool, int or string.');
            }
            if (is_string($value) && (strlen($value) > 256 || preg_match('//u', $value) !== 1)) {
                throw new InvalidConfigurationException('Metadata string values must be valid UTF-8 of at most 256 bytes.');
            }
        }
    }

    public static function empty(): self
    {
        return new self([]);
    }
}
