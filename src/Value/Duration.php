<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Value;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;

/**
 * Non-negative integer nanoseconds in [0, PHP_INT_MAX]. All arithmetic is checked; overflow throws and
 * never wraps or becomes a float.
 */
final readonly class Duration
{
    public function __construct(public int $nanoseconds)
    {
        if ($nanoseconds < 0) {
            throw new InvalidConfigurationException('Duration must not be negative.');
        }
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function nanoseconds(int $value): self
    {
        return new self($value);
    }

    public static function microseconds(int $value): self
    {
        return self::scaled($value, 1_000);
    }

    public static function milliseconds(int $value): self
    {
        return self::scaled($value, 1_000_000);
    }

    public static function seconds(int $value): self
    {
        return self::scaled($value, 1_000_000_000);
    }

    public function isZero(): bool
    {
        return $this->nanoseconds === 0;
    }

    public function isPositive(): bool
    {
        return $this->nanoseconds > 0;
    }

    public function add(self $other): self
    {
        if ($this->nanoseconds > PHP_INT_MAX - $other->nanoseconds) {
            throw new InvalidConfigurationException('Duration addition overflows.');
        }

        return new self($this->nanoseconds + $other->nanoseconds);
    }

    /** @throws InvalidConfigurationException when $other is longer than this duration */
    public function subtract(self $other): self
    {
        if ($other->nanoseconds > $this->nanoseconds) {
            throw new InvalidConfigurationException('Duration subtraction would be negative.');
        }

        return new self($this->nanoseconds - $other->nanoseconds);
    }

    /** Clamped subtraction for places where "no time left" is the correct answer. */
    public function saturatingSubtract(self $other): self
    {
        return new self(max(0, $this->nanoseconds - $other->nanoseconds));
    }

    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw new InvalidConfigurationException('Duration factor must not be negative.');
        }
        if ($factor !== 0 && $this->nanoseconds > intdiv(PHP_INT_MAX, $factor)) {
            throw new InvalidConfigurationException('Duration multiplication overflows.');
        }

        return new self($this->nanoseconds * $factor);
    }

    public function min(self $other): self
    {
        return $other->nanoseconds < $this->nanoseconds ? $other : $this;
    }

    public function max(self $other): self
    {
        return $other->nanoseconds > $this->nanoseconds ? $other : $this;
    }

    public function compare(self $other): int
    {
        return $this->nanoseconds <=> $other->nanoseconds;
    }

    public function equals(self $other): bool
    {
        return $this->nanoseconds === $other->nanoseconds;
    }

    /** Whole microseconds rounded up, so a shared-state wait is never shorter than the exact value. */
    public function ceilMicroseconds(): int
    {
        return intdiv($this->nanoseconds, 1_000) + ($this->nanoseconds % 1_000 === 0 ? 0 : 1);
    }

    private static function scaled(int $value, int $factor): self
    {
        if ($value < 0) {
            throw new InvalidConfigurationException('Duration must not be negative.');
        }
        if ($value > intdiv(PHP_INT_MAX, $factor)) {
            throw new InvalidConfigurationException('Duration conversion overflows.');
        }

        return new self($value * $factor);
    }
}
