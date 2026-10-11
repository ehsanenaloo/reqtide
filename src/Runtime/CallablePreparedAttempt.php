<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Runtime;

use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Outcome\Outcome;

/**
 * Prepared form of a generic callable: replay verification is a no-op and outcome disposal does nothing,
 * because a callable result carries no pipeline-owned resource.
 *
 * @template T
 * @implements PreparedAttempt<T>
 *
 * @internal Built by CallableAttemptOperation.
 */
final readonly class CallablePreparedAttempt implements PreparedAttempt
{
    /** @param callable(AttemptContext):T $callable */
    public function __construct(private mixed $callable) {}

    public function verifyReplay(AttemptContext $context): void {}

    public function invoke(AttemptContext $context): mixed
    {
        /** @var callable(AttemptContext):T $callable */
        $callable = $this->callable;

        return $callable($context);
    }

    public function discardOutcome(Outcome $outcome): void {}
}
