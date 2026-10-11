<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State\Memory;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\State\CooldownBackend;
use Enaxon\Reqtide\State\CooldownDecision;
use Enaxon\Reqtide\State\CooldownStatus;
use Enaxon\Reqtide\State\StateBackendCapabilities;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Time\Clock;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Duration;

/**
 * Process-local shared cooldown.
 *
 * Stores a monotonic not-before instant. `extend` is `max(existing, new)` and never shortens; admission is blocked
 * while `now < notBefore` and clear at equality. A record is kept until `notBefore + cleanupGrace`, then maintenance
 * may remove it. A backward clock reading cannot shorten a cooldown because every call uses the protected
 * nondecreasing effective time. Process-local only; no deduplication record is needed because a local call cannot
 * lose its reply, and `max` makes a repeated extension idempotent.
 *
 * `maintain()` is a backend-specific administrative API of the memory backends. It is not part of the
 * {@see CooldownBackend} interface and callers must not rely on it through that contract.
 *
 * @internal Process-local state; not a supported extension point.
 */
final class MemoryCooldownBackend implements CooldownBackend
{
    /** @var array<string, array{notBefore: int, retainUntil: int, grace: int}> */
    private array $records = [];

    /** @var \SplQueue<string> */
    private \SplQueue $rotation;

    private readonly MonotonicWatermark $time;

    private readonly int $maxPartitions;

    private readonly int $maxRecords;

    public function __construct(
        Clock $clock,
        int $maxPartitions = MemoryLimits::DEFAULT_MAX_PARTITIONS,
        int $maxRecords = MemoryLimits::DEFAULT_MAX_RECORDS,
    ) {
        $this->time = new MonotonicWatermark($clock);
        $this->maxPartitions = MemoryLimits::positive($maxPartitions, 'maxPartitions');
        $this->maxRecords = MemoryLimits::positive($maxRecords, 'maxRecords');
        $this->rotation = new \SplQueue();
    }

    public function capabilities(): StateBackendCapabilities
    {
        return new StateBackendCapabilities(StateScope::ProcessLocal, null, Duration::nanoseconds(1));
    }

    /** Read-only: writes nothing, allocates nothing, performs no cleanup. */
    public function inspect(CooldownPolicy $policy): CooldownDecision
    {
        $record = $this->records[$policy->identity->key()] ?? null;
        if ($record === null) {
            return new CooldownDecision(CooldownStatus::Clear, Duration::zero());
        }
        $this->assertGrace($record['grace'], $policy);

        return $this->decision($record['notBefore'], $this->time->peek());
    }

    public function extend(CooldownPolicy $policy, Duration $extension, CommandId $command): CooldownDecision
    {
        $now = $this->time->advance();
        $key = $policy->identity->key();
        $notBefore = MemoryLimits::checkedAdd($now, $extension->nanoseconds);
        $retainUntil = MemoryLimits::checkedAdd($notBefore, $policy->cleanupGrace->nanoseconds);

        $record = $this->records[$key] ?? null;
        if ($record !== null) {
            $this->assertGrace($record['grace'], $policy);
            $this->records[$key] = [
                'notBefore' => max($record['notBefore'], $notBefore),
                'retainUntil' => max($record['retainUntil'], $retainUntil),
                'grace' => $record['grace'],
            ];

            return $this->decision($this->records[$key]['notBefore'], $now);
        }

        if ($notBefore <= $now) {
            // A zero extension protects nothing and must not allocate.
            return new CooldownDecision(CooldownStatus::Clear, Duration::zero());
        }

        if (count($this->records) >= $this->maxRecords || count($this->records) >= $this->maxPartitions) {
            $this->maintain();
            if (count($this->records) >= $this->maxRecords || count($this->records) >= $this->maxPartitions) {
                throw new StateCapacityExceededException('Cooldown state registry is at capacity; new allocation rejected.');
            }
        }
        $this->records[$key] = ['notBefore' => $notBefore, 'retainUntil' => $retainUntil, 'grace' => $policy->cleanupGrace->nanoseconds];
        $this->rotation->enqueue($key);

        return $this->decision($notBefore, $now);
    }

    /**
     * Bounded cleanup: examines at most `$maxRecords` (capped at 128) records and removes those whose
     * `notBefore + cleanupGrace` has been reached. An active cooldown is never removed.
     *
     * @phpstan-impure
     * @return int number of records removed
     */
    public function maintain(int $maxRecords = MemoryLimits::MAX_MAINTENANCE_BATCH): int
    {
        $budget = MemoryLimits::batch($maxRecords);
        $now = $this->time->advance();
        $removed = 0;
        $visits = min(count($this->rotation), $budget);
        while ($visits-- > 0) {
            $key = $this->rotation->dequeue();
            $record = $this->records[$key] ?? null;
            if ($record === null) {
                continue;
            }
            if ($now >= $record['retainUntil']) {
                unset($this->records[$key]);
                ++$removed;
                continue;
            }
            $this->rotation->enqueue($key);
        }

        return $removed;
    }

    private function decision(int $notBefore, int $now): CooldownDecision
    {
        if ($now >= $notBefore) {
            return new CooldownDecision(CooldownStatus::Clear, Duration::zero());
        }

        return new CooldownDecision(CooldownStatus::Active, Duration::nanoseconds($notBefore - $now));
    }

    private function assertGrace(int $grace, CooldownPolicy $policy): void
    {
        if ($grace !== $policy->cleanupGrace->nanoseconds) {
            throw new InvalidConfigurationException('Policy identity is already bound to a different cleanup grace.');
        }
    }
}
