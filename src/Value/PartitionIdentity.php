<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Value;

/**
 * Bounded dimensions a protected quota is partitioned by. A null tenant means the host deliberately
 * selected an application-wide partition; it never means unknown or invalid.
 */
final readonly class PartitionIdentity
{
    public function __construct(
        public ApplicationId $application,
        public EnvironmentId $environment,
        public ?TenantId $tenant,
        public SubjectId $subject,
    ) {}

    public function equals(self $other): bool
    {
        return $this->application->equals($other->application)
            && $this->environment->equals($other->environment)
            && $this->subject->equals($other->subject)
            && (($this->tenant === null && $other->tenant === null)
                || ($this->tenant !== null && $other->tenant !== null && $this->tenant->equals($other->tenant)));
    }

    /** Stable, delimiter-safe key for process-local registries. Identities are bounded ASCII, so it never holds secrets. */
    public function key(): string
    {
        return $this->application->value . "\x1f" . $this->environment->value . "\x1f"
            . ($this->tenant->value ?? "\x1e") . "\x1f" . $this->subject->value;
    }
}
