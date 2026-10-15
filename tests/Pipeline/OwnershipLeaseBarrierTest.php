<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\State\CircuitPermitKind;
use Enaxon\Reqtide\Telemetry\AdmissionRejected;
use Enaxon\Reqtide\Tests\Pipeline\Support\DispatchSpy;
use Enaxon\Reqtide\Tests\Pipeline\Support\Harness;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;

/**
 * An attempt is dispatched only while the permits it holds are still valid for the whole attempt. Validity is measured from
 * a local monotonic reading taken before the permit was requested, so the check never overestimates what is left. It is a
 * local check before dispatch and not a fencing token: a pause after the check cannot be seen.
 *
 * The harness gives the concurrency lease and the circuit permit lease 60 s and the attempt at most 30 s.
 */
final class OwnershipLeaseBarrierTest extends TestCase
{
    private static function slowAdmission(Harness $h, int $seconds, int $extraNanoseconds = 0): void
    {
        $h->rate->onCall = static function (string $call) use ($h, $seconds, $extraNanoseconds): void {
            if ($call === 'rate.consume') {
                $h->clock->advance($seconds * 1_000_000_000 + $extraNanoseconds);
            }
        };
    }

    private static function refusal(Harness $h, DispatchSpy $spy, int $budgetSeconds = 300): AdmissionRejectedException
    {
        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds($budgetSeconds)));

        self::assertSame(CompletionDisposition::Rejected, $completion->disposition);
        self::assertInstanceOf(AdmissionRejectedException::class, $completion->policyCause);
        self::assertSame('ownership_lease_insufficient', $completion->policyCause->decision->reasonCode);

        return $completion->policyCause;
    }

    public function testAnAttemptWhoseConcurrencyLeaseExpiredDuringAdmissionIsNotDispatched(): void
    {
        $h = new Harness(false, true, true);
        self::slowAdmission($h, 40);
        $spy = DispatchSpy::returning('ok', $h->log);

        self::refusal($h, $spy);
        self::assertSame(0, $spy->dispatches, 'nothing was sent under an expired permit');
        self::assertSame(1, $h->concurrency->released, 'the permit is released');
        self::assertSame('ownership_lease_insufficient', $h->sink->of(AdmissionRejected::class)[0]->reasonCode);
        self::assertSame('concurrency', $h->sink->of(AdmissionRejected::class)[0]->policyKind);
    }

    public function testAnAttemptIsDispatchedWhileTheLeaseStillCoversIt(): void
    {
        $h = new Harness(false, true, true);
        self::slowAdmission($h, 29);
        $spy = DispatchSpy::returning('ok', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(300)));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertSame(1, $spy->dispatches);
    }

    public function testAnExactlyEqualRemainingLeaseIsNotEnough(): void
    {
        $h = new Harness(false, true, true);
        self::slowAdmission($h, 30);
        $spy = DispatchSpy::returning('ok', $h->log);

        self::refusal($h, $spy);
        self::assertSame(0, $spy->dispatches);
    }

    public function testTheClockResolutionOfTheBackendIsTheSmallestMarginOfTheCheck(): void
    {
        // Lease 60 s, attempt 30 s, resolution 1 us: a remaining lease of 30 s + 1 us is not enough, one nanosecond more is.
        $refused = new Harness(false, true, true);
        self::slowAdmission($refused, 29, 999_999_000);
        $refusedSpy = DispatchSpy::returning('ok', $refused->log);
        self::refusal($refused, $refusedSpy);
        self::assertSame(0, $refusedSpy->dispatches);

        $allowed = new Harness(false, true, true);
        self::slowAdmission($allowed, 29, 999_998_999);
        $allowedSpy = DispatchSpy::returning('ok', $allowed->log);
        $completion = $allowed->pipeline()->executeDetailed($allowedSpy->callable(), $allowed->context(Duration::seconds(300)));
        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertSame(1, $allowedSpy->dispatches);
    }

    public function testNoClockIsReadForTheCheckWhenNoPermitThatLimitsConcurrencyIsHeld(): void
    {
        $h = new Harness(false, false, true);
        self::slowAdmission($h, 400);
        $spy = DispatchSpy::returning('ok', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(900)));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
    }

    public function testAShorterRemainingDeadlineShortensWhatTheLeaseMustCover(): void
    {
        $h = new Harness(false, true, true);
        self::slowAdmission($h, 40);
        $spy = DispatchSpy::returning('ok', $h->log);

        // The deadline leaves 10 s after the slow step and the lease 20 s: the attempt is bounded by the deadline.
        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(50)));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertSame(1, $spy->dispatches);
    }

    public function testAHalfOpenProbeWhoseLeaseExpiredIsNotDispatched(): void
    {
        $h = new Harness(true, false, true);
        $h->circuit->permitKind = CircuitPermitKind::HalfOpenProbe;
        self::slowAdmission($h, 40);
        $spy = DispatchSpy::returning('ok', $h->log);

        self::refusal($h, $spy);
        self::assertSame(0, $spy->dispatches);
        self::assertSame(1, $h->circuit->abandoned, 'the probe permit is given back');
        self::assertSame('circuit', $h->sink->of(AdmissionRejected::class)[0]->policyKind);
    }

    public function testAClosedSampleIsStillDispatchedBecauseItLimitsNothing(): void
    {
        $h = new Harness(true, false, true);
        self::slowAdmission($h, 40);
        $spy = DispatchSpy::returning('ok', $h->log);

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(300)));

        self::assertSame(CompletionDisposition::Succeeded, $completion->disposition);
        self::assertSame(1, $spy->dispatches);
    }

    public function testTheReadingBeforeTheRequestCountsNotTheReadingAfterIt(): void
    {
        $h = new Harness(false, true, false);
        // The acquire call itself takes 40 s: the lease may have started at any moment of it.
        $h->concurrency->onCall = static function (string $call) use ($h): void {
            if ($call === 'concurrency.acquire') {
                $h->clock->advance(40_000_000_000);
            }
        };
        $spy = DispatchSpy::returning('ok', $h->log);

        self::refusal($h, $spy);
        self::assertSame(0, $spy->dispatches);
    }

    public function testALeaseThatExpiresDuringTheRetryCommitStopsTheRetryBeforeTheSecondDispatch(): void
    {
        $h = new Harness(false, true, false, true);
        $h->budget->onCall = static function (string $call) use ($h): void {
            if ($call === 'budget.commit') {
                $h->clock->advance(40_000_000_000);
            }
        };
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $h->context(Duration::seconds(600), null, Harness::replayable()));

        self::assertSame(CompletionDisposition::Rejected, $completion->disposition);
        self::assertInstanceOf(AdmissionRejectedException::class, $completion->policyCause);
        self::assertSame('ownership_lease_insufficient', $completion->policyCause->decision->reasonCode);
        self::assertSame(1, $spy->dispatches, 'only the first attempt was sent');
    }
}
