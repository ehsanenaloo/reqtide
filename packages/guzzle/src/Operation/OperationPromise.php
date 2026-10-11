<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Operation;

use GuzzleHttp\Promise\CancellationException;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * The promise of a whole logical operation. It is pending until somebody waits for it, and it
 * settles only after the Core operation has finished, finalization included: the closure given to {@see lazy()} runs
 * the complete pipeline, and its return value or its exception becomes the settlement. Guzzle has no event loop, so
 * nothing starts by itself; `wait()` runs the operation, and `then()` callbacks run when the queue is run after it.
 *
 * It claims no concurrent logical operations: waiting for one promise runs that operation to its end before anything
 * else, and the closure runs at most once however often the promise is waited for. Cancelling it before it has started
 * rejects it without running the operation. Cancelling it afterwards does not interrupt the operation, because the
 * operation cannot be interrupted from outside (cancel the operation's own cancellation token for that). Guzzle still
 * marks a pending promise as rejected when it is cancelled, so a promise cancelled while its operation runs ends
 * rejected with a CancellationException and the operation's return value is dropped; a failure of the operation is
 * thrown from the wait instead of being replaced by a Guzzle state error.
 */
final class OperationPromise
{
    private function __construct() {}

    /**
     * @param \Closure(): mixed $run runs the complete logical operation and returns its result, or throws its failure
     */
    public static function lazy(\Closure $run): PromiseInterface
    {
        $state = new OperationPromiseState();
        $promise = new Promise(
            static function () use ($state, $run): void {
                if ($state->started) {
                    return;
                }
                $state->started = true;
                $target = $state->promise;
                \assert($target !== null);
                try {
                    $result = $run();
                } catch (\Throwable $failure) {
                    if ($target->getState() !== PromiseInterface::PENDING) {
                        throw $failure;
                    }
                    $target->reject($failure);

                    return;
                }
                if ($target->getState() === PromiseInterface::PENDING) {
                    $target->resolve($result);
                }
            },
            static function () use ($state): void {
                if ($state->started) {
                    return;
                }
                $state->started = true;
                $target = $state->promise;
                \assert($target !== null);
                $target->reject(new CancellationException('The operation was cancelled before it started.'));
            },
        );
        $state->promise = $promise;

        return $promise;
    }
}
