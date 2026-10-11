<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Support;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;

/** A promise whose cancel() fails and leaves it pending, like a transport that cannot stop a transfer. */
final class StubbornPromise implements PromiseInterface
{
    public int $cancelAttempts = 0;

    private readonly Promise $inner;

    public function __construct()
    {
        $this->inner = new Promise();
    }

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface
    {
        return $this->inner->then($onFulfilled, $onRejected);
    }

    public function otherwise(callable $onRejected): PromiseInterface
    {
        return $this->inner->otherwise($onRejected);
    }

    public function getState(): string
    {
        return $this->inner->getState();
    }

    public function resolve($value): void
    {
        $this->inner->resolve($value);
    }

    public function reject($reason): void
    {
        $this->inner->reject($reason);
    }

    public function cancel(): void
    {
        ++$this->cancelAttempts;
        throw new \RuntimeException('cancel refused');
    }

    public function wait(bool $unwrap = true)
    {
        return $this->inner->wait($unwrap);
    }
}
