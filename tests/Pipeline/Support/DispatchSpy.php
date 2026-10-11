<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline\Support;

use Enaxon\Reqtide\Execution\AttemptContext;

/**
 * Counts real callback invocations. `steps[n]` runs on the (n+1)-th dispatch; the last step repeats. A step
 * returns its value or throws, exactly like a real operation.
 */
final class DispatchSpy
{
    public int $dispatches = 0;

    /** @var list<AttemptContext> */
    public array $contexts = [];

    /** @var list<\Closure(AttemptContext, int): mixed> */
    private array $steps;

    /** @param \Closure(AttemptContext, int): mixed ...$steps */
    public function __construct(private readonly ?CallLog $log, \Closure ...$steps)
    {
        $this->steps = array_values($steps);
    }

    /** @param \Closure(AttemptContext, int): mixed ...$steps */
    public static function of(?CallLog $log, \Closure ...$steps): self
    {
        return new self($log, ...$steps);
    }

    public static function returning(mixed $value, ?CallLog $log = null): self
    {
        return new self($log, static fn(): mixed => $value);
    }

    public static function throwing(\Throwable $failure, ?CallLog $log = null): self
    {
        return new self($log, static function () use ($failure): never {
            throw $failure;
        });
    }

    /** @return \Closure(AttemptContext): mixed */
    public function callable(): \Closure
    {
        return function (AttemptContext $context): mixed {
            $index = $this->dispatches;
            ++$this->dispatches;
            $this->contexts[] = $context;
            $this->log?->add('dispatch');
            $step = $this->steps[min($index, count($this->steps) - 1)];

            return $step($context, $index);
        };
    }
}
