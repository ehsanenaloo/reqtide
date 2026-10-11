<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Pipeline;

use Enaxon\Reqtide\Value\ProfileId;
use Enaxon\Reqtide\Value\ProfileVersion;

/** Read-only description of a compiled pipeline. Producing it performs no I/O, mutation or dispatch. */
final readonly class PipelineExplanation
{
    /**
     * @param list<string> $enabledPolicies
     * @param list<string> $capabilityNotes
     */
    public function __construct(
        public ProfileId $profileId,
        public ProfileVersion $profileVersion,
        public array $enabledPolicies,
        public array $capabilityNotes,
    ) {}
}
