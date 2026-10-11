<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Error\Severity;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\CircuitTransition;
use Enaxon\Reqtide\Telemetry\AdmissionRejected;
use Enaxon\Reqtide\Telemetry\AttemptCompleted;
use Enaxon\Reqtide\Telemetry\CircuitTransitioned;
use Enaxon\Reqtide\Telemetry\OperationCompleted;
use Enaxon\Reqtide\Tests\Pipeline\Support\DispatchSpy;
use Enaxon\Reqtide\Tests\Pipeline\Support\Harness;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;

/** One CircuitTransitioned event per transition a circuit backend call reports, failure-isolated. */
final class CircuitTransitionEventTest extends TestCase
{
    private static function transition(string $from, string $to, ReasonCode $reason): CircuitTransition
    {
        return new CircuitTransition($from, $to, $reason->value);
    }

    /** @return ExecutionCompletion<mixed> */
    private static function execute(Harness $h, DispatchSpy $spy): ExecutionCompletion
    {
        return $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));
    }

    public function testObs01EveryObservedTransitionEmitsExactlyOneEventInBackendCallOrderBeforeTheCompletion(): void
    {
        $h = new Harness(true);
        $h->circuit->acquireTransition = self::transition('open', 'half_open', ReasonCode::CircuitOpenElapsed);
        $h->circuit->recordTransition = self::transition('half_open', 'closed', ReasonCode::CircuitProbesSucceeded);

        $completion = self::execute($h, DispatchSpy::returning('ok', $h->log));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        $events = $h->sink->of(CircuitTransitioned::class);
        self::assertCount(2, $events);
        self::assertSame(['open', 'half_open', 'circuit.open_elapsed'], [$events[0]->fromState, $events[0]->toState, $events[0]->reasonCode]);
        self::assertSame(['half_open', 'closed', 'circuit.probes_succeeded'], [$events[1]->fromState, $events[1]->toState, $events[1]->reasonCode]);
        self::assertSame(ReasonCode::CircuitOpenElapsed, $events[0]->reason());
        self::assertSame(Severity::Info, $events[1]->severity());
        self::assertSame('op-1', $events[0]->operationId()->value);

        $order = array_map(static fn(object $e): string => $e::class, $h->sink->events);
        self::assertSame(
            [CircuitTransitioned::class, AttemptCompleted::class],
            [$order[0], $order[2]],
            'acquire transition first; the record transition precedes the attempt event',
        );
        self::assertSame(CircuitTransitioned::class, $order[1]);
        self::assertSame(OperationCompleted::class, $order[array_key_last($order)]);
    }

    public function testObs01NoReportedTransitionMeansNoEvent(): void
    {
        $h = new Harness(true);

        self::execute($h, DispatchSpy::returning('ok', $h->log));

        self::assertSame([], $h->sink->of(CircuitTransitioned::class));
    }

    public function testObs01ARefusedAdmissionStillReportsTheTransitionTheCallCaused(): void
    {
        $h = new Harness(true);
        $h->circuit->status = CircuitAdmissionStatus::Open;
        $h->circuit->acquireTransition = self::transition('closed', 'open', ReasonCode::CircuitThresholdExceeded);

        $completion = self::execute($h, DispatchSpy::returning('ok', $h->log));

        self::assertSame(CompletionDisposition::Rejected, $completion->disposition);
        $order = array_map(static fn(object $e): string => $e::class, $h->sink->events);
        self::assertSame(CircuitTransitioned::class, $order[0]);
        self::assertSame(AdmissionRejected::class, $order[1]);
        self::assertSame(Severity::Warning, $h->sink->of(CircuitTransitioned::class)[0]->severity());
    }

    public function testObs01AnAbandonTransitionIsObservedToo(): void
    {
        $h = new Harness(true);
        $h->classifier->throw = new \RuntimeException('classifier broke');
        $h->circuit->abandonTransition = self::transition('half_open', 'open', ReasonCode::CircuitProbeFailed);

        $completion = self::execute($h, DispatchSpy::returning('ok', $h->log));

        self::assertSame(CompletionDisposition::Failed, $completion->disposition);
        $events = $h->sink->of(CircuitTransitioned::class);
        self::assertCount(1, $events);
        self::assertSame('circuit.probe_failed', $events[0]->reasonCode);
        self::assertSame(1, $h->circuit->abandoned);
    }

    public function testObs01EachAttemptReportsItsOwnTransition(): void
    {
        $h = new Harness(true, false, false, true);
        $h->circuit->recordTransition = self::transition('closed', 'open', ReasonCode::CircuitThresholdExceeded);
        $spy = DispatchSpy::of(
            $h->log,
            static fn(): never => throw new \RuntimeException('first'),
            static fn(): string => 'ok',
        );

        $completion = $h->pipeline(Harness::retry(2))->executeDetailed($spy->callable(), $h->context(Duration::seconds(60), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertCount(2, $h->sink->of(CircuitTransitioned::class), 'one event per observed transition, none merged or dropped');
    }

    public function testObs01AThrowingSinkIsIsolatedAndNeverChangesTheBusinessResult(): void
    {
        $h = new Harness(true);
        $h->sink->throwOnEvery = true;
        $h->circuit->recordTransition = self::transition('closed', 'open', ReasonCode::CircuitThresholdExceeded);

        $completion = self::execute($h, DispatchSpy::returning('ok', $h->log));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertFalse($completion->summary->accountingComplete);
        self::assertContains(
            ReasonCode::EventSinkFailed->value,
            array_map(static fn($d): string => $d->reasonCode, $completion->diagnostics),
        );
        self::assertSame(1, count($h->sink->of(CircuitTransitioned::class)), 'delivered once, never retried or recursed');
    }
}
