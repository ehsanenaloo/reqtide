<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Enaxon\Reqtide\Guzzle\Operation\OperationPromise;
use GuzzleHttp\Promise\CancellationException;
use GuzzleHttp\Promise\PromiseInterface;
use PHPUnit\Framework\TestCase;

/** The promise of a whole logical operation: lazy, settled only after the operation finished, run at most once. */
final class OperationPromiseTest extends TestCase
{
    /** @return \ArrayObject<string, int> */
    private function counter(): \ArrayObject
    {
        return new \ArrayObject(['runs' => 0]);
    }

    public function testItIsPendingAndRunsNothingUntilItIsWaitedFor(): void
    {
        $counter = $this->counter();
        $promise = OperationPromise::lazy(static function () use ($counter): string {
            $counter['runs'] = $counter['runs'] + 1;

            return 'done';
        });

        self::assertSame(PromiseInterface::PENDING, $promise->getState());
        self::assertSame(0, $counter['runs'], 'there is no event loop: nothing starts by itself');
        self::assertSame('done', $promise->wait());
        self::assertSame(1, $counter['runs']);
        self::assertSame(PromiseInterface::FULFILLED, $promise->getState());
    }

    public function testItSettlesOnlyAfterTheOperationHasFinished(): void
    {
        $log = new \ArrayObject();
        $promise = OperationPromise::lazy(static function () use ($log): int {
            $log[] = 'operation';
            $log[] = 'finalized';

            return 7;
        });
        $promise->then(static function (int $value) use ($log): void {
            $log[] = 'callback ' . $value;
        });

        self::assertCount(0, $log);
        $promise->wait();
        self::assertSame(['operation', 'finalized', 'callback 7'], $log->getArrayCopy(), 'callbacks see a settled promise after finalization');
    }

    public function testTheOperationsOwnExceptionIsTheRejection(): void
    {
        $failure = new \DomainException('original');
        $promise = OperationPromise::lazy(static fn(): never => throw $failure);

        try {
            $promise->wait();
            self::fail('expected the original exception');
        } catch (\DomainException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(PromiseInterface::REJECTED, $promise->getState());
    }

    public function testTheOperationRunsAtMostOnceHoweverOftenItIsWaitedFor(): void
    {
        $counter = $this->counter();
        $promise = OperationPromise::lazy(static function () use ($counter): int {
            $counter['runs'] = $counter['runs'] + 1;

            return $counter['runs'];
        });
        $first = $promise->wait();
        $second = $promise->wait();
        self::assertSame($first, $second);
        $promise->then(static fn(int $v): int => $v);
        self::assertSame(1, $counter['runs']);
    }

    public function testCancellingBeforeItStartedRejectsWithoutRunningTheOperation(): void
    {
        $counter = $this->counter();
        $promise = OperationPromise::lazy(static function () use ($counter): int {
            $counter['runs'] = $counter['runs'] + 1;

            return 1;
        });
        $promise->cancel();

        self::assertSame(PromiseInterface::REJECTED, $promise->getState());
        self::assertSame(0, $counter['runs']);
        $this->expectException(CancellationException::class);
        $promise->wait();
    }

    public function testCancellingAfterItSettledChangesNothing(): void
    {
        $promise = OperationPromise::lazy(static fn(): string => 'kept');
        $promise->wait();
        $promise->cancel();

        self::assertSame(PromiseInterface::FULFILLED, $promise->getState());
        self::assertSame('kept', $promise->wait());
    }
    public function testCancellingFromInsideTheRunningOperationDoesNotInterruptItOrBreakTheWait(): void
    {
        $counter = $this->counter();
        $promise = null;
        $promise = OperationPromise::lazy(static function () use ($counter, &$promise): string {
            $counter['runs'] = $counter['runs'] + 1;
            \assert($promise instanceof PromiseInterface);
            $promise->cancel();
            $counter['runs'] = $counter['runs'] + 10;

            return 'finished';
        });

        $caught = null;
        try {
            $promise->wait();
        } catch (\Throwable $e) {
            $caught = $e;
        }
        self::assertInstanceOf(CancellationException::class, $caught, 'a cancelled promise is rejected');
        self::assertSame(11, $counter['runs'], 'the operation ran to its end');
        self::assertSame(PromiseInterface::REJECTED, $promise->getState());
    }

    public function testAFailureAfterCancellingFromInsideTheOperationIsTheOneThatReachesTheWaiter(): void
    {
        $failure = new \DomainException('real outcome');
        $promise = null;
        $promise = OperationPromise::lazy(static function () use (&$promise, $failure): never {
            \assert($promise instanceof PromiseInterface);
            $promise->cancel();

            throw $failure;
        });

        $caught = null;
        try {
            $promise->wait();
        } catch (\Throwable $e) {
            $caught = $e;
        }
        self::assertSame($failure, $caught, 'not a Guzzle LogicException about the promise state');
    }
}
