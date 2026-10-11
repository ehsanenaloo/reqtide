<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Contract;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\State\CooldownBackend;
use Enaxon\Reqtide\State\CooldownStatus;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour every CooldownBackend must show, whatever its storage. Runs against a harness that owns the authority
 * clock. Boundary expectations use the harness step (the smallest distinguishable time) instead of one nanosecond.
 *
 * Intentionally backend-specific and therefore not here: bounded maintenance counts, constructor argument checks and
 * checked-overflow behaviour at the top of the nanosecond range (process-local); registry and script behaviour (shared).
 */
abstract class CooldownBackendContract extends TestCase
{
    protected const SECOND = 1_000_000_000;

    private const START = 100 * self::SECOND;

    private ?CooldownHarness $harness = null;

    private ?ContractIdentities $identities = null;

    private ?CooldownBackend $defaultBackend = null;

    abstract protected function createHarness(): CooldownHarness;

    final protected function harness(): CooldownHarness
    {
        if ($this->harness === null) {
            $this->harness = $this->createHarness();
            $this->harness->time()->moveTo(self::START);
        }

        return $this->harness;
    }

    final protected function time(): ControllableTime
    {
        return $this->harness()->time();
    }

    final protected function ids(): ContractIdentities
    {
        return $this->identities ??= new ContractIdentities();
    }

    final protected function backend(): CooldownBackend
    {
        return $this->defaultBackend ??= $this->harness()->cooldown();
    }

    private function limited(int $partitions, int $records): CooldownBackend
    {
        return $this->harness()->cooldown($partitions, $records);
    }

    private function policy(string $subject = 'subject', int $graceSeconds = 60): CooldownPolicy
    {
        return new CooldownPolicy(ContractIdentities::identity($subject), Duration::seconds($graceSeconds));
    }

    private function command(): CommandId
    {
        return $this->ids()->command();
    }

    public function testCapabilitiesMatchTheHarnessDeclaration(): void
    {
        $capabilities = $this->backend()->capabilities();
        self::assertSame($this->harness()->expectedScope(), $capabilities->scope);
        self::assertSame($this->harness()->expectsFiniteIoTimeout(), $capabilities->ioTimeout !== null);
        self::assertLessThanOrEqual($this->time()->step(), $capabilities->clockResolution->nanoseconds);
    }

    public function testAbsentCooldownIsClear(): void
    {
        $decision = $this->backend()->inspect($this->policy());
        self::assertSame(CooldownStatus::Clear, $decision->status);
        self::assertTrue($decision->remaining->isZero());
    }

    public function testExtendBlocksUntilNotBeforeAndIsClearAtEquality(): void
    {
        $backend = $this->backend();
        $policy = $this->policy();

        $decision = $backend->extend($policy, Duration::seconds(5), $this->command());
        self::assertSame(CooldownStatus::Active, $decision->status);
        self::assertSame(5 * self::SECOND, $decision->remaining->nanoseconds);

        $this->time()->advance(3 * self::SECOND);
        self::assertSame(2 * self::SECOND, $backend->inspect($policy)->remaining->nanoseconds);

        $this->time()->advance(2 * self::SECOND - $this->time()->step());
        $last = $backend->inspect($policy);
        self::assertSame(CooldownStatus::Active, $last->status);
        self::assertSame($this->time()->step(), $last->remaining->nanoseconds);

        $this->time()->advance($this->time()->step()); // equality: admission allowed
        self::assertSame(CooldownStatus::Clear, $backend->inspect($policy)->status);
        self::assertTrue($backend->inspect($policy)->remaining->isZero());
    }

    public function testExtendIsMaxOfExistingAndNewAndNeverShortens(): void
    {
        $backend = $this->backend();
        $policy = $this->policy();
        $backend->extend($policy, Duration::seconds(10), $this->command());

        $shorter = $backend->extend($policy, Duration::seconds(1), $this->command());
        self::assertSame(10 * self::SECOND, $shorter->remaining->nanoseconds);

        $zero = $backend->extend($policy, Duration::zero(), $this->command());
        self::assertSame(10 * self::SECOND, $zero->remaining->nanoseconds);

        $this->time()->advance(4 * self::SECOND);
        $longer = $backend->extend($policy, Duration::seconds(20), $this->command());
        self::assertSame(20 * self::SECOND, $longer->remaining->nanoseconds);

        // Repeating the same extension is idempotent.
        $again = $backend->extend($policy, Duration::seconds(20), $this->command());
        self::assertSame(20 * self::SECOND, $again->remaining->nanoseconds);
    }

    public function testZeroExtensionOfAbsentCooldownIsClearAndAllocatesNothing(): void
    {
        $backend = $this->limited(1, 1);
        $decision = $backend->extend($this->policy('a'), Duration::zero(), $this->command());
        self::assertSame(CooldownStatus::Clear, $decision->status);

        // The single slot is still free.
        self::assertSame(CooldownStatus::Active, $backend->extend($this->policy('b'), Duration::seconds(1), $this->command())->status);
    }

    public function testClockRegressionCannotShortenACooldown(): void
    {
        $backend = $this->backend();
        $policy = $this->policy();
        $backend->extend($policy, Duration::seconds(10), $this->command());

        $this->time()->moveTo(0);
        self::assertSame(10 * self::SECOND, $backend->inspect($policy)->remaining->nanoseconds);
        self::assertSame(10 * self::SECOND, $backend->extend($policy, Duration::seconds(1), $this->command())->remaining->nanoseconds);

        $this->time()->moveTo(104 * self::SECOND); // 4 s past the protected watermark
        self::assertSame(6 * self::SECOND, $backend->inspect($policy)->remaining->nanoseconds);
    }

    public function testStateA05CeilingRejectsNewAllocationButExistingExtendAndInspectSucceed(): void
    {
        $backend = $this->limited(1, 10);
        $a = $this->policy('a');
        $backend->extend($a, Duration::seconds(10), $this->command());

        try {
            $backend->extend($this->policy('b'), Duration::seconds(1), $this->command());
            self::fail('A new cooldown partition beyond the ceiling must be rejected.');
        } catch (StateCapacityExceededException) {
        }

        self::assertSame(CooldownStatus::Clear, $backend->inspect($this->policy('b'))->status);
        self::assertSame(10 * self::SECOND, $backend->inspect($a)->remaining->nanoseconds, 'active protection not evicted');
        self::assertSame(15 * self::SECOND, $backend->extend($a, Duration::seconds(15), $this->command())->remaining->nanoseconds);
    }

    public function testRecordCeilingIsIndependentOfPartitionCeiling(): void
    {
        $backend = $this->limited(10, 1);
        $backend->extend($this->policy('a'), Duration::seconds(10), $this->command());

        $this->expectException(StateCapacityExceededException::class);
        $backend->extend($this->policy('b'), Duration::seconds(1), $this->command());
    }

    public function testSettingsAreImmutablePerPolicyIdentity(): void
    {
        $backend = $this->backend();
        $backend->extend($this->policy('a', 60), Duration::seconds(1), $this->command());

        $this->expectException(InvalidConfigurationException::class);
        $backend->inspect($this->policy('a', 30));
    }
}
