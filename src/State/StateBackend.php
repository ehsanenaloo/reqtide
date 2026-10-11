<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

interface StateBackend
{
    /** Immutable capability description; no backend I/O or state mutation. */
    public function capabilities(): StateBackendCapabilities;
}
