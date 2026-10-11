<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Time;

use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Value\Duration;

/**
 * Process-local absolute deadline on a monotonic Clock. Must not be persisted.
 */
final readonly class Deadline
{
    private function __construct(public int $monotonicNanoseconds, private Clock $sourceClock) {}

    /**
     * @throws InvalidConfigurationException when now + budget would overflow
     * @throws ClockFailureException
     */
    public static function after(Duration $budget, Clock $clock): self
    {
        $now = self::read($clock);
        if ($now > PHP_INT_MAX - $budget->nanoseconds) {
            throw new InvalidConfigurationException('Deadline construction overflows the monotonic clock domain.');
        }

        return new self($now + $budget->nanoseconds, $clock);
    }

    /**
     * Zero at or after expiry.
     *
     * @throws InvalidConfigurationException when $clock is not the source Clock instance
     * @throws ClockFailureException
     */
    public function remaining(Clock $clock): Duration
    {
        $this->assertSource($clock);
        $now = self::read($clock);

        return $now >= $this->monotonicNanoseconds
            ? Duration::zero()
            : new Duration($this->monotonicNanoseconds - $now);
    }

    /**
     * @throws InvalidConfigurationException when $clock is not the source Clock instance
     * @throws ClockFailureException
     */
    public function isExpired(Clock $clock): bool
    {
        $this->assertSource($clock);

        return self::read($clock) >= $this->monotonicNanoseconds;
    }

    private function assertSource(Clock $clock): void
    {
        if ($clock !== $this->sourceClock) {
            throw new InvalidConfigurationException('A Deadline must be evaluated with the Clock instance that created it.');
        }
    }

    private static function read(Clock $clock): int
    {
        $now = $clock->monotonicNow();
        if ($now < 0) {
            throw new ClockFailureException('The monotonic clock returned a negative reading.');
        }

        return $now;
    }
}
