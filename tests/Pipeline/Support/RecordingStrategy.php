<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline\Support;

use Enaxon\Reqtide\Pipeline\DefinitionValidationContext;
use Enaxon\Reqtide\Pipeline\LifecycleStage;
use Enaxon\Reqtide\Pipeline\Strategy;
use Enaxon\Reqtide\Runtime\RuntimeCapabilities;

final class RecordingStrategy implements Strategy
{
    /** @var list<RuntimeCapabilities> */
    public array $seen = [];

    public function stage(): LifecycleStage
    {
        return LifecycleStage::BeforeDispatch;
    }

    public function validate(DefinitionValidationContext $context): void
    {
        $this->seen[] = $context->capabilities;
    }
}
