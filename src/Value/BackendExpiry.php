<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Value;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;

/**
 * Backend fact describing when an owned permit stops being valid.
 *
 * Only the issuing backend evaluates it. A shared expiry is never compared with local monotonic
 * time; a local expiry is valid only inside the issuing process.
 */
final readonly class BackendExpiry
{
    private function __construct(
        public BackendClockKind $clockKind,
        public ?int $localMonotonicNanoseconds,
        public ?BackendInstant $sharedInstant,
    ) {}

    public static function local(int $monotonicNanoseconds): self
    {
        if ($monotonicNanoseconds < 0) {
            throw new InvalidConfigurationException('Local expiry must be non-negative.');
        }

        return new self(BackendClockKind::LocalMonotonic, $monotonicNanoseconds, null);
    }

    public static function shared(BackendInstant $instant): self
    {
        return new self(BackendClockKind::SharedAuthority, null, $instant);
    }
}
