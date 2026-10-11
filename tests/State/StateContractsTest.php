<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State;

use Enaxon\Reqtide\Admission\AdmissionDecision;
use Enaxon\Reqtide\Admission\AdmissionStatus;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\State\CircuitAdmission;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\CircuitPermit;
use Enaxon\Reqtide\State\CircuitPermitKind;
use Enaxon\Reqtide\State\ConcurrencyAdmission;
use Enaxon\Reqtide\State\ConcurrencyAdmissionStatus;
use Enaxon\Reqtide\State\ConcurrencyPermit;
use Enaxon\Reqtide\State\CooldownDecision;
use Enaxon\Reqtide\State\CooldownStatus;
use Enaxon\Reqtide\State\RetryBudgetFinalizationResult;
use Enaxon\Reqtide\State\RetryBudgetFinalizationStatus;
use Enaxon\Reqtide\State\RetryBudgetReservation;
use Enaxon\Reqtide\State\RetryReservationDecision;
use Enaxon\Reqtide\State\RetryReservationStatus;
use Enaxon\Reqtide\State\StateBackendCapabilities;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\BackendExpiry;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\Generation;
use Enaxon\Reqtide\Value\IncarnationId;
use Enaxon\Reqtide\Value\NonNegativeInt;
use Enaxon\Reqtide\Value\PermitOwnerId;
use PHPUnit\Framework\TestCase;

final class StateContractsTest extends TestCase
{
    /** @param callable(): object $build */
    private function assertRejected(callable $build, string $label): void
    {
        try {
            $build();
        } catch (InvalidConfigurationException $e) {
            self::assertInstanceOf(InvalidConfigurationException::class, $e);

            return;
        }
        self::fail('Expected InvalidConfigurationException for ' . $label);
    }

    private static function circuitPermit(Duration $lease = new Duration(1)): CircuitPermit
    {
        return new CircuitPermit(
            Fixtures::policyIdentity(),
            IncarnationId::fromLowerHex(Fixtures::hex(1)),
            Generation::first(),
            AttemptId::fromLowerHex(Fixtures::hex(2)),
            PermitOwnerId::fromLowerHex(Fixtures::hex(3)),
            CircuitPermitKind::ClosedSample,
            $lease,
            BackendExpiry::local(10),
        );
    }

    private static function concurrencyPermit(Duration $lease = new Duration(1)): ConcurrencyPermit
    {
        return new ConcurrencyPermit(
            Fixtures::policyIdentity(),
            IncarnationId::fromLowerHex(Fixtures::hex(1)),
            Generation::first(),
            PermitOwnerId::fromLowerHex(Fixtures::hex(3)),
            $lease,
            BackendExpiry::local(10),
        );
    }

    private static function reservation(): RetryBudgetReservation
    {
        return new RetryBudgetReservation(
            Fixtures::policyIdentity(),
            IncarnationId::fromLowerHex(Fixtures::hex(1)),
            Generation::first(),
            AttemptId::fromLowerHex(Fixtures::hex(2)),
            PermitOwnerId::fromLowerHex(Fixtures::hex(3)),
            new Duration(1),
            BackendExpiry::local(10),
        );
    }

    // ---- AdmissionDecision matrix ----

    public function testAdmissionMatrix(): void
    {
        $one = Duration::seconds(1);
        $remaining = new NonNegativeInt(3);
        self::assertSame(AdmissionStatus::Accepted, (new AdmissionDecision(AdmissionStatus::Accepted, 'ok', $remaining, null))->status);
        self::assertSame(AdmissionStatus::Deferred, (new AdmissionDecision(AdmissionStatus::Deferred, 'wait', $remaining, $one))->status);
        self::assertNull((new AdmissionDecision(AdmissionStatus::Rejected, 'no', null, null))->retryAfter);
        self::assertSame(AdmissionStatus::Rejected, (new AdmissionDecision(AdmissionStatus::Rejected, 'no', $remaining, $one))->status);
        self::assertSame(AdmissionStatus::Impossible, (new AdmissionDecision(AdmissionStatus::Impossible, 'never', null, null))->status);

        $this->assertRejected(fn() => new AdmissionDecision(AdmissionStatus::Accepted, 'ok', $remaining, $one), 'accepted with wait');
        $this->assertRejected(fn() => new AdmissionDecision(AdmissionStatus::Deferred, 'wait', $remaining, null), 'deferred without wait');
        $this->assertRejected(fn() => new AdmissionDecision(AdmissionStatus::Deferred, 'wait', $remaining, Duration::zero()), 'deferred with zero wait');
        $this->assertRejected(fn() => new AdmissionDecision(AdmissionStatus::Impossible, 'never', $remaining, null), 'impossible with remaining');
        $this->assertRejected(fn() => new AdmissionDecision(AdmissionStatus::Impossible, 'never', null, $one), 'impossible with wait');
        $this->assertRejected(fn() => new AdmissionDecision(AdmissionStatus::Rejected, 'no', null, Duration::zero()), 'rejected with zero wait');
        $this->assertRejected(fn() => new AdmissionDecision(AdmissionStatus::Accepted, 'not a code!', null, null), 'unbounded reason');
    }

    // ---- permits and admissions ----

    public function testPermitsRequirePositiveLease(): void
    {
        self::assertSame(1, self::circuitPermit()->lease->nanoseconds);
        $this->assertRejected(fn() => self::circuitPermit(Duration::zero()), 'circuit lease zero');
        $this->assertRejected(fn() => self::concurrencyPermit(Duration::zero()), 'concurrency lease zero');
    }

    public function testCircuitAdmissionPermitIffAccepted(): void
    {
        $permit = self::circuitPermit();
        self::assertNotNull((new CircuitAdmission(CircuitAdmissionStatus::Accepted, $permit, null))->permit);
        self::assertSame(5, (new CircuitAdmission(CircuitAdmissionStatus::Open, null, new Duration(5)))->retryAfter?->nanoseconds);
        self::assertNull((new CircuitAdmission(CircuitAdmissionStatus::ProbeCapacityExhausted, null, null))->permit);
        $this->assertRejected(fn() => new CircuitAdmission(CircuitAdmissionStatus::Accepted, null, null), 'accepted without permit');
        $this->assertRejected(fn() => new CircuitAdmission(CircuitAdmissionStatus::Open, $permit, null), 'open with permit');
        $this->assertRejected(fn() => new CircuitAdmission(CircuitAdmissionStatus::ProbeCapacityExhausted, null, new Duration(1)), 'probe exhausted with wait');
        $this->assertRejected(fn() => new CircuitAdmission(CircuitAdmissionStatus::Accepted, $permit, new Duration(1)), 'accepted with wait');
    }

    public function testConcurrencyAdmissionPermitIffAccepted(): void
    {
        self::assertNotNull((new ConcurrencyAdmission(ConcurrencyAdmissionStatus::Accepted, self::concurrencyPermit()))->permit);
        self::assertNull((new ConcurrencyAdmission(ConcurrencyAdmissionStatus::CapacityExhausted, null))->permit);
        $this->assertRejected(fn() => new ConcurrencyAdmission(ConcurrencyAdmissionStatus::Accepted, null), 'accepted without permit');
        $this->assertRejected(fn() => new ConcurrencyAdmission(ConcurrencyAdmissionStatus::CapacityExhausted, self::concurrencyPermit()), 'exhausted with permit');
    }

    public function testRetryReservationDecisionReservationIffReserved(): void
    {
        $reservation = self::reservation();
        self::assertNotNull((new RetryReservationDecision(RetryReservationStatus::Reserved, $reservation, null))->reservation);
        self::assertNull((new RetryReservationDecision(RetryReservationStatus::Exhausted, null, new Duration(1)))->reservation);
        $this->assertRejected(fn() => new RetryReservationDecision(RetryReservationStatus::Reserved, null, null), 'reserved without reservation');
        $this->assertRejected(fn() => new RetryReservationDecision(RetryReservationStatus::Exhausted, $reservation, null), 'exhausted with reservation');
        $this->assertRejected(fn() => new RetryReservationDecision(RetryReservationStatus::Reserved, $reservation, new Duration(1)), 'reserved with wait');
        $this->assertRejected(fn() => new RetryBudgetReservation(
            Fixtures::policyIdentity(),
            IncarnationId::fromLowerHex(Fixtures::hex(1)),
            Generation::first(),
            AttemptId::fromLowerHex(Fixtures::hex(2)),
            PermitOwnerId::fromLowerHex(Fixtures::hex(3)),
            Duration::zero(),
            BackendExpiry::local(10),
        ), 'reservation lease zero');
    }

    public function testOnlyFirstCommitAuthorizesDispatch(): void
    {
        foreach (RetryBudgetFinalizationStatus::cases() as $status) {
            self::assertSame(
                $status === RetryBudgetFinalizationStatus::Committed,
                (new RetryBudgetFinalizationResult($status))->authorizesDispatch(),
                $status->value,
            );
        }
    }

    public function testCooldownDecisionMatrix(): void
    {
        self::assertTrue((new CooldownDecision(CooldownStatus::Clear, Duration::zero()))->remaining->isZero());
        self::assertTrue((new CooldownDecision(CooldownStatus::Active, new Duration(1)))->remaining->isPositive());
        $this->assertRejected(fn() => new CooldownDecision(CooldownStatus::Clear, new Duration(1)), 'clear with remaining');
        $this->assertRejected(fn() => new CooldownDecision(CooldownStatus::Active, Duration::zero()), 'active with zero');
    }

    public function testBackendCapabilities(): void
    {
        $caps = new StateBackendCapabilities(StateScope::SharedAuthority, null, new Duration(1_000));
        self::assertNull($caps->ioTimeout);
        self::assertSame(5, (new StateBackendCapabilities(StateScope::ProcessLocal, new Duration(5), new Duration(1)))->ioTimeout?->nanoseconds);
        $this->assertRejected(fn() => new StateBackendCapabilities(StateScope::ProcessLocal, Duration::zero(), new Duration(1)), 'zero io timeout');
        $this->assertRejected(fn() => new StateBackendCapabilities(StateScope::ProcessLocal, null, Duration::zero()), 'zero resolution');
    }
}
