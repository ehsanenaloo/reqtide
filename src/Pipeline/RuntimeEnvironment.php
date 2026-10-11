<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Pipeline;

use Enaxon\Reqtide\Outcome\OutcomeClassifier;
use Enaxon\Reqtide\Runtime\RuntimeAdapter;
use Enaxon\Reqtide\State\CircuitBackend;
use Enaxon\Reqtide\State\ConcurrencyBackend;
use Enaxon\Reqtide\State\CooldownBackend;
use Enaxon\Reqtide\State\RateLimitBackend;
use Enaxon\Reqtide\State\RetryBudgetBackend;
use Enaxon\Reqtide\Telemetry\EventSink;
use Enaxon\Reqtide\Time\Clock;
use Enaxon\Reqtide\Time\DelayScheduler;
use Enaxon\Reqtide\Time\RandomSource;

/**
 * A null backend is structural: that backend capability was not installed. Compilation rejects any
 * configured policy whose backend is absent. Null never means a backend that is unavailable at runtime.
 */
final readonly class RuntimeEnvironment
{
    public function __construct(
        public Clock $clock,
        public DelayScheduler $scheduler,
        public RandomSource $random,
        public OutcomeClassifier $classifier,
        public RuntimeAdapter $runtime,
        public EventSink $events,
        public ?RateLimitBackend $rateLimit,
        public ?CircuitBackend $circuit,
        public ?ConcurrencyBackend $concurrency,
        public ?RetryBudgetBackend $retryBudget,
        public ?CooldownBackend $cooldown,
    ) {}
}
