<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline\Support;

use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Runtime\AttemptControls;
use Enaxon\Reqtide\Runtime\PreparedAttempt;
use Enaxon\Reqtide\Runtime\RuntimeAdapter;
use Enaxon\Reqtide\Runtime\RuntimeCapabilities;
use Enaxon\Reqtide\Runtime\RuntimeExecution;

/** Runtime that only declares capabilities; it can never be invoked. Used to exercise compile-time validation. */
final class StubCapabilityRuntime implements RuntimeAdapter
{
    public function __construct(private readonly RuntimeCapabilities $capabilities) {}

    public function capabilities(): RuntimeCapabilities
    {
        return $this->capabilities;
    }

    public function invoke(PreparedAttempt $operation, AttemptContext $context, AttemptControls $controls): RuntimeExecution
    {
        throw new \LogicException('The stub runtime never invokes anything.');
    }
}
