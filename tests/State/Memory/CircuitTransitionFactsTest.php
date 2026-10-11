<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Internal\NumericLimits;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\Policy\CountCircuitWindow;
use Enaxon\Reqtide\State\CircuitAdmission;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\CircuitPermit;
use Enaxon\Reqtide\State\CircuitTransition;
use Enaxon\Reqtide\State\FinalizationResult;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\Ratio;
use PHPUnit\Framework\TestCase;

/** MemoryCircuitBackend reports the state change a call caused and fails without partial mutation. */
final class CircuitTransitionFactsTest extends TestCase
{
    private const S = CircuitRig::SECOND;

    private ?MemoryTestSupport $support = null;

    private function ids(): MemoryTestSupport
    {
        return $this->support ??= new MemoryTestSupport();
    }

    private function admit(CircuitRig $rig, CircuitPolicy $policy): CircuitAdmission
    {
        return $rig->backend->acquire($policy, $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
    }

    private function permit(CircuitRig $rig, CircuitPolicy $policy): CircuitPermit
    {
        $admission = $this->admit($rig, $policy);
        self::assertSame(CircuitAdmissionStatus::Accepted, $admission->status);
        self::assertNotNull($admission->permit);

        return $admission->permit;
    }

    private function record(CircuitRig $rig, CircuitPolicy $policy, CircuitPermit $permit, CircuitVerdict $verdict): FinalizationResult
    {
        return $rig->backend->record($policy, $permit, $verdict, $this->ids()->command());
    }

    private function assertTransition(?CircuitTransition $transition, string $from, string $to, string $reason): void
    {
        self::assertNotNull($transition);
        self::assertSame($from, $transition->fromState);
        self::assertSame($to, $transition->toState);
        self::assertSame($reason, $transition->reasonCode);
    }

    public function testFullLifecycleReportsEveryTransition(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::countPolicy(window: 4, minimum: 2, openSeconds: 30);

        $first = $this->admit($rig, $policy);
        self::assertNull($first->transition, 'a plain admission is not a transition');
        self::assertNotNull($first->permit);
        $one = $this->record($rig, $policy, $first->permit, CircuitVerdict::Failure);
        self::assertSame(FinalizationStatus::Applied, $one->status);
        self::assertNull($one->transition);

        $two = $this->record($rig, $policy, $this->permit($rig, $policy), CircuitVerdict::Failure);
        $this->assertTransition($two->transition, 'closed', 'open', 'circuit.threshold_exceeded');

        $open = $this->admit($rig, $policy);
        self::assertSame(CircuitAdmissionStatus::Open, $open->status);
        self::assertNull($open->transition);

        $rig->advance(30 * self::S);
        $probe = $this->admit($rig, $policy);
        self::assertSame(CircuitAdmissionStatus::Accepted, $probe->status);
        $this->assertTransition($probe->transition, 'open', 'half_open', 'circuit.open_elapsed');
        self::assertNotNull($probe->permit);

        $closed = $this->record($rig, $policy, $probe->permit, CircuitVerdict::Success);
        $this->assertTransition($closed->transition, 'half_open', 'closed', 'circuit.probes_succeeded');
    }

    public function testFailedProbeReopens(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::countPolicy(window: 2, minimum: 2, openSeconds: 5);
        $this->record($rig, $policy, $this->permit($rig, $policy), CircuitVerdict::Failure);
        $this->record($rig, $policy, $this->permit($rig, $policy), CircuitVerdict::Failure);
        $rig->advance(5 * self::S);

        $probe = $this->permit($rig, $policy);
        $result = $this->record($rig, $policy, $probe, CircuitVerdict::Failure);

        $this->assertTransition($result->transition, 'half_open', 'open', 'circuit.probe_failed');
        self::assertSame(CircuitAdmissionStatus::Open, $this->admit($rig, $policy)->status);
    }

    public function testNeutralOutcomeAndStaleFinalizationReportNoTransition(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::countPolicy(window: 4, minimum: 2);
        $permit = $this->permit($rig, $policy);

        $neutral = $rig->backend->abandon($policy, $permit, $this->ids()->command());
        self::assertSame(FinalizationStatus::Applied, $neutral->status);
        self::assertNull($neutral->transition);

        $duplicate = $rig->backend->abandon($policy, $permit, $this->ids()->command());
        self::assertSame(FinalizationStatus::AlreadyFinalized, $duplicate->status);
        self::assertNull($duplicate->transition);
    }

    public function testProbeExpiryThatClosesTheCircuitIsReportedByTheCallThatObservedIt(): void
    {
        $rig = new CircuitRig();
        $policy = CircuitRig::countPolicy(window: 2, minimum: 2, probeCapacity: 2, threshold: 1, openSeconds: 5, leaseSeconds: 10);
        $this->record($rig, $policy, $this->permit($rig, $policy), CircuitVerdict::Failure);
        $this->record($rig, $policy, $this->permit($rig, $policy), CircuitVerdict::Failure);
        $rig->advance(5 * self::S);

        $p1 = $this->permit($rig, $policy);
        $rig->advance(self::S);
        $this->permit($rig, $policy); // second probe, lease ends 1 s after p1's
        $succeeded = $this->record($rig, $policy, $p1, CircuitVerdict::Success);
        self::assertNull($succeeded->transition, 'a probe is still unresolved');

        $rig->advance(10 * self::S); // the unresolved probe lease lapses neutrally
        $next = $this->admit($rig, $policy);

        self::assertSame(CircuitAdmissionStatus::Accepted, $next->status);
        $this->assertTransition($next->transition, 'half_open', 'closed', 'circuit.probes_succeeded');
    }

    public function testUnrepresentableOpenDeadlineFailsWithoutPartialMutation(): void
    {
        $rig = new CircuitRig();
        $horizon = NumericLimits::MAX_DEADLINE_HORIZON_NANOSECONDS;
        $policy = new CircuitPolicy(
            Fixtures::policyIdentity(),
            new CountCircuitWindow(new PositiveInt(1)),
            new PositiveInt(1),
            Ratio::of(1, 2),
            new Duration($horizon),
            new PositiveInt(1),
            new PositiveInt(1),
            Duration::seconds(10),
        );
        $rig->at($horizon); // now + openDuration = 2^63 overflows int64

        $permit = $this->permit($rig, $policy);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $this->record($rig, $policy, $permit, CircuitVerdict::Failure);
                self::fail('The open deadline cannot be represented.');
            } catch (InvalidConfigurationException) {
                // Typed failure; the owner was not consumed and no sample was added.
            }
        }

        // Still owned and Closed: the permit can be abandoned normally, and no failure was counted.
        self::assertSame(FinalizationStatus::Applied, $rig->backend->abandon($policy, $permit, $this->ids()->command())->status);
        self::assertSame(CircuitAdmissionStatus::Accepted, $this->admit($rig, $policy)->status);
    }
}
