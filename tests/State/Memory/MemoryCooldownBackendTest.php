<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\State\CooldownStatus;
use Enaxon\Reqtide\State\Memory\MemoryCooldownBackend;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Tests\Support\FakeClock;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;

final class MemoryCooldownBackendTest extends TestCase
{
    private const SECOND = 1_000_000_000;

    private ?FakeClock $fakeClock = null;

    private ?MemoryTestSupport $identities = null;

    private function clock(): FakeClock
    {
        return $this->fakeClock ??= new FakeClock(100 * self::SECOND);
    }

    private function ids(): MemoryTestSupport
    {
        return $this->identities ??= new MemoryTestSupport();
    }





    private function backend(int $partitions = 10_000, int $records = 20_000): MemoryCooldownBackend
    {
        return new MemoryCooldownBackend($this->clock(), $partitions, $records);
    }

    private function policy(string $subject = 'subject', int $graceSeconds = 60): CooldownPolicy
    {
        return new CooldownPolicy(MemoryTestSupport::identity($subject), Duration::seconds($graceSeconds));
    }

    private function command(): CommandId
    {
        return $this->ids()->command();
    }

    public function testCapabilitiesAreProcessLocal(): void
    {
        self::assertSame(StateScope::ProcessLocal, $this->backend()->capabilities()->scope);
    }

    public function testCheckedAdditionRejectsOverflowBeforeMutation(): void
    {
        $backend = $this->backend();
        $policy = $this->policy();
        $this->clock()->now = PHP_INT_MAX - 5;

        try {
            $backend->extend($policy, Duration::nanoseconds(10), $this->command());
            self::fail('now + extension overflows and must be rejected.');
        } catch (InvalidConfigurationException) {
        }
        self::assertSame(CooldownStatus::Clear, $backend->inspect($policy)->status);

        // Cleanup grace participates in the checked addition.
        $this->clock()->now = PHP_INT_MAX - 100;
        $graceOverflow = new CooldownPolicy(MemoryTestSupport::identity('g'), Duration::nanoseconds(200));
        try {
            $backend->extend($graceOverflow, Duration::nanoseconds(50), $this->command());
            self::fail('notBefore + grace overflows and must be rejected.');
        } catch (InvalidConfigurationException) {
        }
        self::assertSame(CooldownStatus::Clear, $backend->inspect($graceOverflow)->status);
    }

    public function testMaintenanceKeepsActiveAndGraceThenReclaimsForNewAllocation(): void
    {
        $backend = $this->backend(1, 10);
        $a = $this->policy('a', 60);
        $backend->extend($a, Duration::seconds(10), $this->command());

        $this->clock()->now += 10 * self::SECOND; // cooldown over, 60 s grace still retained
        self::assertSame(0, $backend->maintain());
        $this->clock()->now += 59 * self::SECOND;
        self::assertSame(0, $backend->maintain());

        $this->clock()->now += 1 * self::SECOND; // notBefore + grace reached
        // Allocation at the ceiling triggers bounded maintenance and succeeds.
        self::assertSame(CooldownStatus::Active, $backend->extend($this->policy('b'), Duration::seconds(1), $this->command())->status);
    }

    public function testMaintenanceNeverRemovesAnActiveCooldownEvenWithZeroGrace(): void
    {
        $backend = $this->backend();
        $policy = $this->policy('a', 0);
        $backend->extend($policy, Duration::seconds(10), $this->command());

        $this->clock()->now += 9 * self::SECOND;
        self::assertSame(0, $backend->maintain());
        self::assertSame(self::SECOND, $backend->inspect($policy)->remaining->nanoseconds);

        $this->clock()->now += self::SECOND;
        self::assertSame(1, $backend->maintain());
    }

    public function testMaintenanceIsBounded(): void
    {
        $backend = $this->backend();
        for ($i = 0; $i < 200; ++$i) {
            $backend->extend($this->policy('s' . $i, 0), Duration::seconds(1), $this->command());
        }
        $this->clock()->now += self::SECOND;

        self::assertSame(128, $backend->maintain(1_000));
        self::assertSame(72, $backend->maintain());
        self::assertSame(0, $backend->maintain());
    }

    public function testMaintenanceRejectsNonPositiveBatch(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->backend()->maintain(0);
    }
}
