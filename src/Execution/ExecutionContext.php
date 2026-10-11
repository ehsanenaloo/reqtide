<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Execution;

use Enaxon\Reqtide\Fallback\FallbackOperation;
use Enaxon\Reqtide\Time\Cancellation;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\PartitionIdentity;

/**
 * Immutable per-operation facts. Build one per logical operation, ideally with `CompiledPipeline::newContext()`.
 *
 * `T` is the result type of the optional fallback. It defaults to `never`: a context without a
 * fallback is `ExecutionContext<never>`, which `CompiledPipeline::execute()` accepts for any callable, so static
 * analysis infers the result type from the callable alone.
 *
 * @template T = never
 */
final readonly class ExecutionContext
{
    /** @param FallbackOperation<T>|null $fallback */
    public function __construct(
        public OperationId $operationId,
        public PartitionIdentity $partition,
        public Deadline $deadline,
        public Cancellation $cancellation,
        public OperationMetadata $metadata,
        public ?FallbackOperation $fallback,
    ) {}
}
