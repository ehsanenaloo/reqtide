<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Execution;

/** Bounded semantic facts only: never a body, complete URL, query, header or credential. */
final readonly class OperationMetadata
{
    public function __construct(public OperationSafety $safety, public BoundedMetadata $attributes) {}
}
