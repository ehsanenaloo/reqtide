<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline\Support;

use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Runtime\AttemptControls;
use Enaxon\Reqtide\Runtime\PreparedAttempt;
use Enaxon\Reqtide\Runtime\RuntimeAdapter;
use Enaxon\Reqtide\Runtime\RuntimeCapabilities;
use Enaxon\Reqtide\Runtime\RuntimeExecution;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\Runtime\SynchronousRuntimeAdapter;

/** Runtime adapter whose invocation or settlement observation fails; the operation callback is never run. */
final class ThrowingRuntimeAdapter implements RuntimeAdapter
{
    public int $invocations = 0;

    public function __construct(private readonly ?\Throwable $onInvoke, private readonly ?\Throwable $onAwait = null) {}

    public function capabilities(): RuntimeCapabilities
    {
        return (new SynchronousRuntimeAdapter(SchedulingCapability::BoundedBlocking))->capabilities();
    }

    /**
     * @template T
     * @param PreparedAttempt<T> $operation
     * @return RuntimeExecution<T>
     */
    public function invoke(PreparedAttempt $operation, AttemptContext $context, AttemptControls $controls): RuntimeExecution
    {
        ++$this->invocations;
        if ($this->onInvoke !== null) {
            throw $this->onInvoke;
        }

        /** @var ThrowingRuntimeExecution<T> $execution */
        $execution = new ThrowingRuntimeExecution($this->onAwait ?? new \LogicException('unreachable'));

        return $execution;
    }
}
