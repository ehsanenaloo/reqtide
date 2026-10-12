<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\OperationCancelledException;
use Enaxon\Reqtide\Execution\ExecutionContext;
use Enaxon\Reqtide\Tests\Pipeline\Support\ClosureFallback;
use Enaxon\Reqtide\Tests\Pipeline\Support\DispatchSpy;
use Enaxon\Reqtide\Tests\Pipeline\Support\Harness;
use Enaxon\Reqtide\Time\Cancellation;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;

/** A cancellation requested while the fallback runs is observed like one requested while an attempt runs. */
final class FallbackCancellationTest extends TestCase
{
    private static function withFallback(Harness $h, ClosureFallback $fallback, ?Cancellation $cancellation = null): ExecutionContext
    {
        $context = $h->context(Duration::seconds(5), $fallback);

        return new ExecutionContext(
            $context->operationId,
            $context->partition,
            $context->deadline,
            $cancellation ?? $context->cancellation,
            $context->metadata,
            $fallback,
        );
    }

    public function testACancellationRequestedDuringTheFallbackGivesCancelledAndKeepsTheFallbackOutcome(): void
    {
        $h = new Harness();
        $fallback = new ClosureFallback(static function () use ($h): string {
            $h->cancel->cancel();

            return 'substitute';
        });
        $context = self::withFallback($h, $fallback);
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $context);

        self::assertSame(CompletionDisposition::Cancelled, $completion->disposition);
        self::assertInstanceOf(OperationCancelledException::class, $completion->policyCause);
        self::assertSame('substitute', $completion->fallbackOutcome?->value());
        self::assertSame(1, $completion->summary->fallbackExecutions);
        self::assertNotNull($completion->terminalOutcome);
        self::assertSame(1, $fallback->calls);
    }

    public function testSimpleExecutionThrowsTheCancellationAndNotTheFallbackValue(): void
    {
        $h = new Harness();
        $fallback = new ClosureFallback(static function () use ($h): string {
            $h->cancel->cancel();

            return 'substitute';
        });
        $context = self::withFallback($h, $fallback);
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $this->expectException(OperationCancelledException::class);
        $h->pipeline()->execute($spy->callable(), $context);
    }

    public function testAThrowingFallbackAndACancellationGiveCancelledWithTheFallbackException(): void
    {
        $h = new Harness();
        $broken = new \LogicException('fallback broke');
        $fallback = new ClosureFallback(static function () use ($h, $broken): never {
            $h->cancel->cancel();

            throw $broken;
        });
        $context = self::withFallback($h, $fallback);
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $context);

        self::assertSame(CompletionDisposition::Cancelled, $completion->disposition);
        self::assertSame($broken, $completion->fallbackOutcome?->exception());
    }

    public function testTheDeadlineWinsWhenItExpiresTogetherWithTheCancellation(): void
    {
        $h = new Harness();
        $context = $h->context(Duration::seconds(5));
        $fallback = new ClosureFallback(static function () use ($h, $context): string {
            $h->cancel->cancel();
            $h->clock->now = $context->deadline->monotonicNanoseconds + 1;

            return 'late';
        });
        $context = self::withFallback($h, $fallback);
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $context);

        self::assertSame(CompletionDisposition::DeadlineExceeded, $completion->disposition);
        self::assertSame('late', $completion->fallbackOutcome?->value());
    }

    public function testACancellationThatCannotBeSampledAfterTheFallbackKeepsTheFallbackResult(): void
    {
        $h = new Harness();
        $armed = false;
        $broken = new class ($armed) implements Cancellation {
            public function __construct(private bool &$armed) {}

            public function isRequested(): bool
            {
                if ($this->armed) {
                    throw new \RuntimeException('sink down');
                }

                return false;
            }
        };
        $fallback = new ClosureFallback(static function () use (&$armed): string {
            $armed = true;

            return 'substitute';
        });
        $context = self::withFallback($h, $fallback, $broken);
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $context);

        self::assertSame(CompletionDisposition::FallbackSucceeded, $completion->disposition);
        self::assertFalse($completion->summary->accountingComplete);
        self::assertContains(
            ReasonCode::CancellationSampleFailed->value,
            array_map(static fn(Diagnostic $d): string => $d->reasonCode, $completion->diagnostics),
        );
    }

    public function testAFallbackWithoutCancellationStillSucceeds(): void
    {
        $h = new Harness();
        $fallback = new ClosureFallback(static fn(): string => 'substitute');
        $context = self::withFallback($h, $fallback);
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $context);

        self::assertSame(CompletionDisposition::FallbackSucceeded, $completion->disposition);
    }
}
