<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Testing;

use Enaxon\Reqtide\Exception\ClockFailureException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Internal\Identity\IdentityGenerator;
use Enaxon\Reqtide\Testing\DeterministicIdentityGenerator;
use Enaxon\Reqtide\Testing\ScriptedRandomSource;
use Enaxon\Reqtide\Testing\SeededRandomSource;
use Enaxon\Reqtide\Testing\VirtualClock;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;

final class ClockAndRandomTest extends TestCase
{
    public function testClockDoesNotMoveByItself(): void
    {
        $clock = new VirtualClock(5);
        self::assertSame(5, $clock->monotonicNow());
        self::assertSame(5, $clock->monotonicNow());
        self::assertSame('2026-01-01T00:00:00+00:00', $clock->wallNow()->format('c'));
    }

    public function testTheDefaultStartIsAFixedRealisticDateDocumentedByAConstant(): void
    {
        $clock = new VirtualClock();

        self::assertSame(VirtualClock::DEFAULT_WALL_START, $clock->wallNow()->format('c'));
        self::assertSame('2026-01-01T00:00:00+00:00', VirtualClock::DEFAULT_WALL_START);
        self::assertSame(1_000_000_000, $clock->monotonicNow());
        self::assertSame($clock->wallNow()->format('c'), (new VirtualClock())->wallNow()->format('c'), 'two default clocks agree, so tests are reproducible');
    }

    public function testStartingAtReturnsAFreshClockOnTheGivenWallTimeKeepingTheMonotonicReading(): void
    {
        $original = new VirtualClock(42);
        $original->advance(Duration::seconds(1));
        $original->failNextMonotonicReads(1);

        $anchored = $original->startingAt(new \DateTimeImmutable('2030-06-15T12:00:00+00:00'));

        self::assertNotSame($original, $anchored);
        self::assertSame('2030-06-15T12:00:00+00:00', $anchored->wallNow()->format('c'));
        self::assertSame(0, $anchored->monotonicReadCount(), 'counters start fresh');
        self::assertSame(1_000_000_042, $anchored->monotonicNow(), 'the monotonic reading carries over and the injected failure does not');
        self::assertSame('2026-01-01T00:00:01+00:00', $original->wallNow()->format('c'), 'the original clock is untouched');
        $anchored->advance(Duration::seconds(90));
        self::assertSame('2030-06-15T12:01:30+00:00', $anchored->wallNow()->format('c'));
    }

    public function testAnAnchoredClockDrivesAReplayContractMadeFromItself(): void
    {
        $clock = (new VirtualClock())->startingAt(new \DateTimeImmutable('2031-01-01T00:00:00+00:00'));

        $contract = ReplaySafetyContract::forReadOnlyCallable('read', Duration::seconds(60), $clock);

        self::assertSame('2031-01-01T00:00:00+00:00', $contract->establishedAt->format('c'));
        self::assertSame('2031-01-01T00:01:00+00:00', $contract->validUntil->format('c'));
    }

    public function testAdvanceMovesBothDomains(): void
    {
        $clock = new VirtualClock(0);
        $clock->advance(Duration::seconds(90));
        self::assertSame(90_000_000_000, $clock->monotonicNow());
        self::assertSame('2026-01-01T00:01:30+00:00', $clock->wallNow()->format('c'));
    }

    public function testWallJumpIsIndependentOfMonotonic(): void
    {
        $clock = new VirtualClock(100);
        $clock->setWall(new \DateTimeImmutable('2020-05-05T00:00:00+00:00'));
        self::assertSame(100, $clock->monotonicNow());
        self::assertSame('2020-05-05T00:00:00+00:00', $clock->wallNow()->format('c'));

        $clock->advanceMonotonicOnly(Duration::nanoseconds(7));
        self::assertSame(107, $clock->monotonicNow());
        self::assertSame('2020-05-05T00:00:00+00:00', $clock->wallNow()->format('c'));
    }

    public function testSetMonotonicMovesForwardOnly(): void
    {
        $clock = new VirtualClock(10);
        $clock->setMonotonic(10);
        $clock->setMonotonic(50);
        self::assertSame(50, $clock->monotonicNow());
        $this->expectException(\InvalidArgumentException::class);
        $clock->setMonotonic(49);
    }

    public function testAdvanceOverflowAndNegativeInitialAreRejected(): void
    {
        $clock = new VirtualClock(PHP_INT_MAX);
        try {
            $clock->advanceMonotonicOnly(Duration::nanoseconds(1));
            self::fail('overflow expected');
        } catch (\OverflowException) {
            self::assertSame(PHP_INT_MAX, $clock->peekMonotonic());
        }
        $this->expectException(\InvalidArgumentException::class);
        new VirtualClock(-1);
    }

    public function testInjectedFailuresAffectExactlyTheNextReads(): void
    {
        $clock = new VirtualClock(1);
        $clock->failNextMonotonicReads(2);
        for ($i = 0; $i < 2; ++$i) {
            try {
                $clock->monotonicNow();
                self::fail('failure expected');
            } catch (ClockFailureException) {
            }
        }
        self::assertSame(1, $clock->monotonicNow());
        self::assertSame(3, $clock->monotonicReadCount());

        // wall reads are unaffected by monotonic failures and vice versa
        $clock->failNextWallReads();
        self::assertSame(1, $clock->monotonicNow());
        try {
            $clock->wallNow();
            self::fail('failure expected');
        } catch (ClockFailureException) {
        }
        $clock->wallNow();
        self::assertSame(2, $clock->wallReadCount());
    }

    public function testPeekNeitherCountsNorConsumesFailures(): void
    {
        $clock = new VirtualClock(9);
        $clock->failNextMonotonicReads();
        self::assertSame(9, $clock->peekMonotonic());
        self::assertSame(0, $clock->monotonicReadCount());
        $this->expectException(ClockFailureException::class);
        $clock->monotonicNow();
    }

    public function testNegativeFailureCountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new VirtualClock())->failNextWallReads(-1);
    }

    public function testClockDrivesDeadlineExpiryAtEquality(): void
    {
        $clock = new VirtualClock(0);
        $deadline = Deadline::after(Duration::milliseconds(10), $clock);
        $clock->advance(Duration::nanoseconds(9_999_999));
        self::assertFalse($deadline->isExpired($clock));
        $clock->advance(Duration::nanoseconds(1));
        self::assertTrue($deadline->isExpired($clock));
    }

    public function testSeededRandomIsReproducibleAndBounded(): void
    {
        $a = new SeededRandomSource(1234);
        $b = new SeededRandomSource(1234);
        $c = new SeededRandomSource(4321);
        $seqA = $seqB = $seqC = [];
        for ($i = 0; $i < 20; ++$i) {
            $seqA[] = $a->integer(0, 1_000_000);
            $seqB[] = $b->integer(0, 1_000_000);
            $seqC[] = $c->integer(0, 1_000_000);
        }
        self::assertSame($seqA, $seqB);
        self::assertNotSame($seqA, $seqC);
        self::assertSame(20, $a->draws());
        self::assertSame(7, (new SeededRandomSource(1))->integer(7, 7));
        foreach ($seqA as $value) {
            self::assertGreaterThanOrEqual(0, $value);
            self::assertLessThanOrEqual(1_000_000, $value);
        }
    }

    public function testSeededRandomRejectsInvertedRange(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new SeededRandomSource(1))->integer(2, 1);
    }

    public function testScriptedRandomReturnsQueueInOrderAndRecordsRanges(): void
    {
        $random = new ScriptedRandomSource([3, 9]);
        $random->enqueue(0);
        self::assertSame(3, $random->integer(0, 10));
        self::assertSame(9, $random->integer(5, 9));
        self::assertSame(1, $random->remaining());
        self::assertSame(0, $random->integer(0, 0));
        self::assertSame([[0, 10], [5, 9], [0, 0]], $random->requests());
    }

    public function testScriptedRandomFailsLoudly(): void
    {
        $random = new ScriptedRandomSource([50]);
        try {
            $random->integer(0, 10);
            self::fail('out of range expected');
        } catch (\OutOfRangeException) {
        }
        $this->expectException(\UnderflowException::class);
        $random->integer(0, 10);
    }

    public function testScriptedRandomRejectsInvertedRange(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new ScriptedRandomSource([1]))->integer(2, 1);
    }

    public function testDeterministicIdentitiesAreSequentialValidAndShared(): void
    {
        $ids = new DeterministicIdentityGenerator();
        self::assertInstanceOf(IdentityGenerator::class, $ids);
        self::assertSame('00000000000000000000000000000001', $ids->attempt()->lowerHex);
        self::assertSame('00000000000000000000000000000002', $ids->command()->lowerHex);
        self::assertSame('00000000000000000000000000000003', $ids->owner()->lowerHex);
        self::assertSame('00000000000000000000000000000004', $ids->incarnation()->lowerHex);
        self::assertSame(4, $ids->issuedCount());

        $other = new DeterministicIdentityGenerator(255);
        self::assertSame('000000000000000000000000000000ff', $other->attempt()->lowerHex);
    }

    public function testNegativeIdentityStartIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeterministicIdentityGenerator(-1);
    }
}
