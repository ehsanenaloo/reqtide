<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Internal\NumericLimits;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\State\ConcurrencyAdmissionStatus;
use Enaxon\Reqtide\State\ConcurrencyPermit;
use Enaxon\Reqtide\State\Memory\MemoryConcurrencyBackend;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Tests\Support\FakeClock;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\TestCase;

final class MemoryConcurrencyBackendTest extends TestCase
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





    private function backend(int $partitions = 10_000, int $records = 20_000): MemoryConcurrencyBackend
    {
        return new MemoryConcurrencyBackend($this->clock(), $this->ids(), $partitions, $records);
    }

    private function policy(int $capacity = 1, int $leaseSeconds = 10, string $subject = 'subject'): ConcurrencyPolicy
    {
        return new ConcurrencyPolicy(MemoryTestSupport::identity($subject), new PositiveInt($capacity), Duration::seconds($leaseSeconds));
    }

    private function acquire(MemoryConcurrencyBackend $backend, ConcurrencyPolicy $policy): ConcurrencyPermit
    {
        $admission = $backend->acquire($policy, $this->ids()->owner(), $this->ids()->command());
        self::assertSame(ConcurrencyAdmissionStatus::Accepted, $admission->status);
        self::assertNotNull($admission->permit);

        return $admission->permit;
    }

    public function testCapabilitiesAreProcessLocal(): void
    {
        $capabilities = $this->backend()->capabilities();
        self::assertSame(StateScope::ProcessLocal, $capabilities->scope);
        self::assertNull($capabilities->ioTimeout);
    }

    /** Memory-specific half of the shared acquire test: a process-local permit expires at a local monotonic tick. */
    public function testAcquiredPermitsExpireAtTheLocalMonotonicLeaseEnd(): void
    {
        $backend = $this->backend();
        $permit = $this->acquire($backend, $this->policy(2));

        self::assertSame((100 + 10) * self::SECOND, $permit->expiresAt->localMonotonicNanoseconds);
    }

    public function testLeaseOverflowIsRejectedBeforeMutation(): void
    {
        $backend = $this->backend();
        $policy = new ConcurrencyPolicy(MemoryTestSupport::identity(), new PositiveInt(1), Duration::nanoseconds(NumericLimits::MAX_DEADLINE_HORIZON_NANOSECONDS));
        $this->clock()->now = NumericLimits::MAX_DEADLINE_HORIZON_NANOSECONDS;

        try {
            $backend->acquire($policy, $this->ids()->owner(), $this->ids()->command());
            self::fail('Expiry overflow must be a typed failure.');
        } catch (InvalidConfigurationException) {
        }

        // Nothing was allocated.
        $this->clock()->now = 5;
        $fine = new ConcurrencyPolicy(MemoryTestSupport::identity('other'), new PositiveInt(1), Duration::seconds(1));
        $this->acquire($backend, $fine);
    }

    public function testMaintenanceIsBoundedAndNeverRemovesValidLeases(): void
    {
        $backend = $this->backend();
        $policy = $this->policy(300, 10);
        for ($i = 0; $i < 300; ++$i) {
            $this->acquire($backend, $policy);
        }

        $this->clock()->now += 5 * self::SECOND;
        self::assertSame(0, $backend->maintain(), 'valid leases are never evicted');

        $this->clock()->now += 5 * self::SECOND; // all 300 expired
        self::assertSame(128, $backend->maintain(10_000), 'one call removes at most 128 records');
        self::assertSame(128, $backend->maintain());
        self::assertSame(44, $backend->maintain());
        self::assertSame(0, $backend->maintain());
    }

    public function testMaintenanceRejectsNonPositiveBatch(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->backend()->maintain(0);
    }

    public function testConstructorRejectsNonPositiveCeilings(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->backend(0, 10);
    }
}
