<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State\Memory;

/**
 * Outcome of evaluating one cost against a projected view: the state after a debit (the unchanged
 * projection when rejected), the whole units available before the cost, and the next-eligible wait in
 * nanoseconds when one is computable.
 *
 * @internal
 */
final readonly class RateEvaluation
{
    public function __construct(
        public bool $accepted,
        public RateView $view,
        public int $available,
        public ?int $retryAfterNanoseconds,
    ) {}
}
