<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Pipeline;

use Enaxon\Reqtide\Exception\CapabilityMismatchException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;

/**
 * Declarative, validation-only extension associated with a fixed lifecycle stage. It contributes pure
 * configuration validation, never a runtime decision, callback interception or per-operation state.
 *
 * @internal Reserved seam; the builder installs none.
 */
interface Strategy
{
    public function stage(): LifecycleStage;

    /** @throws InvalidConfigurationException|CapabilityMismatchException */
    public function validate(DefinitionValidationContext $context): void;
}
