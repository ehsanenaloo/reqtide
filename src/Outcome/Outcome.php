<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Outcome;

use Enaxon\Reqtide\Execution\BoundedMetadata;

/**
 * Explicit discriminated result: a successful null remains a value.
 *
 * @template T
 */
final readonly class Outcome
{
    private function __construct(
        public OutcomeKind $kind,
        private mixed $storedValue,
        private ?\Throwable $storedException,
        public BoundedMetadata $metadata,
    ) {}

    /**
     * @template V
     * @param V $value
     * @return self<V>
     */
    public static function fromValue(mixed $value, BoundedMetadata $metadata): self
    {
        /** @var self<V> $outcome */
        $outcome = new self(OutcomeKind::Value, $value, null, $metadata);

        return $outcome;
    }

    /** @return self<never> */
    public static function fromException(\Throwable $exception, BoundedMetadata $metadata): self
    {
        return new self(OutcomeKind::Exception, null, $exception, $metadata);
    }

    /**
     * @return T
     * @throws \LogicException when the outcome is an exception
     */
    public function value(): mixed
    {
        if ($this->kind !== OutcomeKind::Value) {
            throw new \LogicException('Outcome holds an exception, not a value.');
        }

        return $this->storedValue;
    }

    /** @throws \LogicException when the outcome is a value */
    public function exception(): \Throwable
    {
        if ($this->kind !== OutcomeKind::Exception || $this->storedException === null) {
            throw new \LogicException('Outcome holds a value, not an exception.');
        }

        return $this->storedException;
    }
}
