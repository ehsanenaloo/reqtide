<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Pipeline;

use Enaxon\Reqtide\Runtime\RuntimeCapabilities;

/**
 * @internal Reserved seam; the builder installs none.
 */
final readonly class DefinitionValidationContext
{
    public function __construct(public RuntimeCapabilities $capabilities) {}
}
