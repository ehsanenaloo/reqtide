<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Value;

final readonly class PolicyIdentity
{
    public function __construct(
        public PartitionIdentity $partition,
        public ProfileVersion $profileVersion,
        public PolicyId $policy,
    ) {}

    public function equals(self $other): bool
    {
        return $this->partition->equals($other->partition)
            && $this->profileVersion->equals($other->profileVersion)
            && $this->policy->equals($other->policy);
    }

    public function key(): string
    {
        return $this->partition->key() . "\x1f" . $this->profileVersion->value . "\x1f" . $this->policy->value;
    }
}
