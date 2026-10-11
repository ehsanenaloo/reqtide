<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline\Support;

use Enaxon\Reqtide\Execution\ExecutionContext;
use Enaxon\Reqtide\Fallback\FallbackOperation;
use Enaxon\Reqtide\Outcome\Outcome;

/**
 * @implements FallbackOperation<mixed>
 */
final class ClosureFallback implements FallbackOperation
{
    public int $calls = 0;

    /** @var Outcome<mixed>|null */
    public ?Outcome $received = null;

    /** @param \Closure(): mixed $body */
    public function __construct(private readonly \Closure $body) {}

    public function invoke(Outcome $original, ExecutionContext $context): mixed
    {
        ++$this->calls;
        $this->received = $original;

        return ($this->body)();
    }
}
