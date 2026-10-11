<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Testing;

use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Runtime\AttemptControls;

/**
 * One real dispatch observed by ControlledRuntimeAdapter.
 *
 * @api-for-tests
 */
final readonly class DispatchRecord
{
    public function __construct(
        public AttemptContext $context,
        public AttemptControls $controls,
    ) {}
}
