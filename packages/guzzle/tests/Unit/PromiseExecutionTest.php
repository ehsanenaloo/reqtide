<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Enaxon\Reqtide\Completion\SettlementOverride;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Guzzle\Runtime\PromiseExecution;
use Enaxon\Reqtide\Guzzle\Tests\Support\StubbornPromise;
use Enaxon\Reqtide\Outcome\OutcomeKind;
use Enaxon\Reqtide\Runtime\AttemptControls;
use Enaxon\Reqtide\Testing\VirtualClock;
use Enaxon\Reqtide\Time\Cancellation;
use Enaxon\Reqtide\Time\CancellationToken;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Value\Duration;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\CancellationException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * The promise lifecycle, deterministically: a promise that the test settles and a
 * drive step that advances a virtual clock stand in for the transport.
 */
final class PromiseExecutionTest extends TestCase
{
    private ?VirtualClock $clockInstance = null;

    private ?CancellationToken $tokenInstance = null;

    private int $drives = 0;

    private int $cancels = 0;

    protected function setUp(): void
    {
        $this->clockInstance = new VirtualClock();
        $this->tokenInstance = new CancellationToken();
        $this->drives = 0;
        $this->cancels = 0;
    }

    private function clock(): VirtualClock
    {
        return $this->clockInstance ??= new VirtualClock();
    }

    private function token(): CancellationToken
    {
        return $this->tokenInstance ??= new CancellationToken();
    }

    private function controls(Duration $budget = new Duration(1_000_000_000), ?Cancellation $cancellation = null): AttemptControls
    {
        return new AttemptControls(Duration::seconds(1), Deadline::after($budget, $this->clock()), $cancellation ?? $this->token(), $this->clock());
    }

    /** A pending promise that counts the transport cancel requests it receives. */
    private function pending(): Promise
    {
        return new Promise(null, function (): void {
            ++$this->cancels;
        });
    }

    /** @param \Closure(int): void $onDrive called with the 1-based drive number, after the clock advanced by 1 ms */
    private function execution(PromiseInterface $promise, \Closure $onDrive): PromiseExecution
    {
        return new PromiseExecution(
            $promise,
            function () use ($onDrive): void {
                ++$this->drives;
                $this->clock()->advance(Duration::milliseconds(1));
                $onDrive($this->drives);
            },
            $this->clock()->monotonicNow(),
        );
    }

    public function testAnAlreadySettledPromiseNeedsNoDriving(): void
    {
        $response = new Response(200);
        $execution = $this->execution(Create::promiseFor($response), static function (): void {});
        $observation = $execution->awaitObservedSettlement($this->controls());

        self::assertSame(0, $this->drives);
        self::assertSame(OutcomeKind::Value, $observation->outcome->kind);
        self::assertSame($response, $observation->outcome->value());
        self::assertSame(SettlementOverride::None, $observation->logicalOverride);
        self::assertTrue($observation->accountingComplete);
        self::assertSame([], $observation->diagnostics);
    }

    public function testItDrivesTheQueueUntilTheTransferActuallySettles(): void
    {
        $promise = $this->pending();
        $response = new Response(200);
        $execution = $this->execution($promise, static function (int $n) use ($promise, $response): void {
            if ($n === 3) {
                $promise->resolve($response);
            }
        });
        $observation = $execution->awaitObservedSettlement($this->controls());

        self::assertSame(3, $this->drives, 'it kept driving while the promise was pending and stopped once it settled');
        self::assertSame($response, $observation->outcome->value());
        self::assertSame(3_000_000, $observation->elapsed?->nanoseconds);
        self::assertSame(SettlementOverride::None, $observation->logicalOverride);
        self::assertSame(0, $this->cancels);
    }

    public function testARejectionIsTheOriginalExceptionAsTheOutcome(): void
    {
        $promise = $this->pending();
        $failure = new ConnectException('refused', new Request('GET', 'https://api.example.com/'));
        $observation = $this->execution($promise, static function (int $n) use ($promise, $failure): void {
            if ($n === 2) {
                $promise->reject($failure);
            }
        })->awaitObservedSettlement($this->controls());

        self::assertSame(OutcomeKind::Exception, $observation->outcome->kind);
        self::assertSame($failure, $observation->outcome->exception());
        self::assertSame(SettlementOverride::None, $observation->logicalOverride);
    }

    public function testACancellationRequestIsSentOnceBetweenTicksAndIsNotACompletionClaim(): void
    {
        $promise = $this->pending();
        $observation = $this->execution($promise, function (int $n): void {
            if ($n === 2) {
                $this->token()->cancel();
            }
        })->awaitObservedSettlement($this->controls());

        self::assertSame(1, $this->cancels, 'the transport was asked to cancel exactly once');
        self::assertSame(2, $this->drives, 'no drive step after the request: the promise rejected at once');
        self::assertSame(SettlementOverride::Cancelled, $observation->logicalOverride);
        self::assertInstanceOf(CancellationException::class, $observation->outcome->exception());
    }

    public function testTheDeadlineIsNoticedAtTheNextCheckpointAndCancelsTheTransfer(): void
    {
        $promise = $this->pending();
        $observation = $this->execution($promise, function (int $n): void {
            if ($n === 2) {
                $this->clock()->advance(Duration::seconds(5));
            }
        })->awaitObservedSettlement($this->controls(Duration::milliseconds(100)));

        self::assertSame(1, $this->cancels);
        self::assertSame(SettlementOverride::DeadlineExceeded, $observation->logicalOverride);
        self::assertSame(2, $this->drives);
    }

    public function testDeadlineWinsOverCancellationWhenBothAreDue(): void
    {
        $promise = $this->pending();
        $observation = $this->execution($promise, function (int $n): void {
            if ($n === 1) {
                $this->token()->cancel();
                $this->clock()->advance(Duration::seconds(5));
            }
        })->awaitObservedSettlement($this->controls(Duration::milliseconds(100)));

        self::assertSame(SettlementOverride::DeadlineExceeded, $observation->logicalOverride);
        self::assertSame(1, $this->cancels);
    }

    public function testAResponseAfterTheCancelRequestChangesNothingAndTheObservationIsFixed(): void
    {
        $promise = $this->pending();
        $execution = $this->execution($promise, function (int $n): void {
            if ($n === 1) {
                $this->token()->cancel();
            }
        });
        $first = $execution->awaitObservedSettlement($this->controls());

        try {
            $promise->resolve(new Response(200));
            self::fail('a settled promise cannot settle again');
        } catch (\LogicException) {
            // This is the duplicate-settlement guard of the promise itself.
        }
        $drivesBefore = $this->drives;
        $second = $execution->awaitObservedSettlement($this->controls());

        self::assertSame($first, $second, 'awaiting again returns the same observation');
        self::assertSame($drivesBefore, $this->drives, 'and drives nothing');
        self::assertSame(OutcomeKind::Exception, $first->outcome->kind);
        self::assertSame(SettlementOverride::Cancelled, $first->logicalOverride);
    }

    public function testAnUnreadableClockIsNeverReportedAsOnTime(): void
    {
        $execution = $this->execution(Create::promiseFor(new Response(200)), static function (): void {});
        $controls = $this->controls();
        $this->clock()->failNextMonotonicReads(1);
        $observation = $execution->awaitObservedSettlement($controls);

        self::assertNull($observation->observedMonotonicNanoseconds);
        self::assertFalse($observation->accountingComplete);
        self::assertContains(ReasonCode::ClockUnavailable, array_map(static fn($d): ?ReasonCode => $d->reason(), $observation->diagnostics));
        self::assertSame(SettlementOverride::None, $observation->logicalOverride, 'nothing is invented when the time is unknown');
    }

    public function testATransportThatRefusesToCancelIsAskedOnceAndKeepsBeingDriven(): void
    {
        $promise = new StubbornPromise();
        $response = new Response(200);
        $observation = $this->execution($promise, function (int $n) use ($promise, $response): void {
            if ($n === 1) {
                $this->token()->cancel();
            }
            if ($n === 4) {
                $promise->resolve($response);
            }
        })->awaitObservedSettlement($this->controls());

        self::assertSame(1, $promise->cancelAttempts, 'asked once, not on every tick');
        self::assertSame(4, $this->drives, 'it kept driving until the transfer actually settled');
        self::assertSame($response, $observation->outcome->value());
        self::assertSame(SettlementOverride::Cancelled, $observation->logicalOverride, 'the late response is still marked as belonging to a cancelled attempt');
    }

    public function testADriveFailureCancelsTheAttemptInsteadOfSpinning(): void
    {
        $promise = $this->pending();
        $execution = new PromiseExecution($promise, function (): void {
            ++$this->drives;
            throw new \RuntimeException('the multi handler failed');
        }, $this->clock()->monotonicNow());
        $observation = $execution->awaitObservedSettlement($this->controls());

        self::assertSame(1, $this->drives);
        self::assertSame(1, $this->cancels);
        self::assertFalse($observation->accountingComplete);
        self::assertSame(ReasonCode::RuntimeAdapterFailure, $observation->diagnostics[0]->reason());
        self::assertSame(OutcomeKind::Exception, $observation->outcome->kind);
    }

    public function testACancellationSamplerThatFailsIsReportedAndDoesNotStopTheTransfer(): void
    {
        $failing = new class implements Cancellation {
            public function isRequested(): bool
            {
                throw new \RuntimeException('sampler failed');
            }
        };
        $promise = $this->pending();
        $response = new Response(200);
        $observation = $this->execution($promise, static function (int $n) use ($promise, $response): void {
            if ($n === 2) {
                $promise->resolve($response);
            }
        })->awaitObservedSettlement($this->controls(Duration::seconds(1), $failing));

        self::assertSame($response, $observation->outcome->value());
        self::assertFalse($observation->accountingComplete);
        $reasons = array_map(static fn($d): ?ReasonCode => $d->reason(), $observation->diagnostics);
        self::assertContains(ReasonCode::CancellationSampleFailed, $reasons);
        self::assertSame(1, count(array_filter($reasons, static fn($r): bool => $r === ReasonCode::CancellationSampleFailed)), 'reported once');
    }
}
