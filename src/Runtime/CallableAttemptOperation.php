<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Runtime;

use Enaxon\Reqtide\Execution\AttemptContext;

/**
 * Wraps a generic callable as an AttemptOperation. It infers no replay guarantee: whether the callable may
 * be repeated is decided solely by the explicit safety assertion on the operation metadata.
 *
 * @template T
 * @implements AttemptOperation<T>
 *
 * @internal Built by CompiledPipeline::execute(); implement AttemptOperation instead.
 */
final readonly class CallableAttemptOperation implements AttemptOperation
{
    /** @param callable(AttemptContext):T $callable */
    public function __construct(private mixed $callable) {}

    public function prepare(AttemptContext $context): PreparedAttempt
    {
        /** @var CallablePreparedAttempt<T> $prepared */
        $prepared = new CallablePreparedAttempt($this->callable);

        return $prepared;
    }
}
