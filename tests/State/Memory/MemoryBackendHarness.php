<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\State\CircuitBackend;
use Enaxon\Reqtide\State\ConcurrencyBackend;
use Enaxon\Reqtide\State\CooldownBackend;
use Enaxon\Reqtide\State\Memory\MemoryCircuitBackend;
use Enaxon\Reqtide\State\Memory\MemoryConcurrencyBackend;
use Enaxon\Reqtide\State\Memory\MemoryCooldownBackend;
use Enaxon\Reqtide\State\Memory\MemoryRateLimitBackend;
use Enaxon\Reqtide\State\Memory\MemoryRetryBudgetBackend;
use Enaxon\Reqtide\State\RateLimitBackend;
use Enaxon\Reqtide\State\RetryBudgetBackend;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Tests\Contract\CircuitHarness;
use Enaxon\Reqtide\Tests\Contract\ConcurrencyHarness;
use Enaxon\Reqtide\Tests\Contract\ControllableTime;
use Enaxon\Reqtide\Tests\Contract\CooldownHarness;
use Enaxon\Reqtide\Tests\Contract\RateLimitHarness;
use Enaxon\Reqtide\Tests\Contract\RetryBudgetHarness;
use PHPUnit\Framework\Assert;

/** Runs the shared backend contract suites against the five process-local memory backends on one controllable clock. */
final class MemoryBackendHarness implements CircuitHarness, ConcurrencyHarness, CooldownHarness, RateLimitHarness, RetryBudgetHarness
{
    private readonly AnchoredClock $clock;

    private readonly MemoryControllableTime $time;

    /** @var list<object> */
    private array $created = [];

    public function __construct()
    {
        $this->clock = new AnchoredClock(0);
        $this->time = new MemoryControllableTime($this->clock);
    }

    public function time(): ControllableTime
    {
        return $this->time;
    }

    public function expectedScope(): StateScope
    {
        return StateScope::ProcessLocal;
    }

    public function expectsFiniteIoTimeout(): bool
    {
        return false;
    }

    public function reclaimExpired(): void
    {
        foreach ($this->created as $backend) {
            // Each call removes a bounded batch; repeat until a call removes nothing.
            for ($round = 0; $round < 1_000; ++$round) {
                $removed = match (true) {
                    $backend instanceof MemoryCooldownBackend,
                    $backend instanceof MemoryConcurrencyBackend,
                    $backend instanceof MemoryRetryBudgetBackend,
                    $backend instanceof MemoryRateLimitBackend,
                    $backend instanceof MemoryCircuitBackend => $backend->maintain(),
                    default => 0,
                };
                if ($removed === 0) {
                    break;
                }
            }
        }
    }

    public function cooldown(int $maxPartitions = 10_000, int $maxRecords = 20_000): CooldownBackend
    {
        return $this->created[] = new MemoryCooldownBackend($this->clock, $maxPartitions, $maxRecords);
    }

    public function concurrency(int $maxPartitions = 10_000, int $maxRecords = 20_000): ConcurrencyBackend
    {
        return $this->created[] = new MemoryConcurrencyBackend($this->clock, new MemoryTestSupport(), $maxPartitions, $maxRecords);
    }

    public function rateLimit(int $maxPartitions = 10_000): RateLimitBackend
    {
        return $this->created[] = new MemoryRateLimitBackend($this->clock, $maxPartitions);
    }

    public function retryBudget(int $maxPartitions = 10_000, int $maxRecords = 20_000): RetryBudgetBackend
    {
        return $this->created[] = new MemoryRetryBudgetBackend($this->clock, new MemoryTestSupport(), $maxPartitions, $maxRecords);
    }

    public function circuit(int $maxPartitions = 10_000, int $maxRecords = 20_000): CircuitBackend
    {
        return $this->created[] = new MemoryCircuitBackend($this->clock, new SequenceIdentityGenerator(), $maxPartitions, $maxRecords);
    }

    public function observeBudget(RetryBudgetBackend $backend, RetryBudgetPolicy $policy): ?array
    {
        Assert::assertInstanceOf(MemoryRetryBudgetBackend::class, $backend);

        return $backend->snapshot($policy);
    }
}
