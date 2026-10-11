<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

final readonly class FinalizationResult
{
    /** @param CircuitTransition|null $transition circuit backends only: the state change this call caused, if any */
    public function __construct(public FinalizationStatus $status, public ?CircuitTransition $transition = null) {}
}
