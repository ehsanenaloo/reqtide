<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Value;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    public function testMaximumConstructsAndNegativeIsRejected(): void
    {
        self::assertSame(PHP_INT_MAX, (new Duration(PHP_INT_MAX))->nanoseconds);
        $this->expectException(InvalidConfigurationException::class);
        new Duration(-1);
    }

    public function testAdditionOverflowIsRejectedNotWrapped(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Duration(PHP_INT_MAX))->add(new Duration(1));
    }

    public function testAdditionAtExactMaximumSucceeds(): void
    {
        self::assertSame(PHP_INT_MAX, (new Duration(PHP_INT_MAX - 1))->add(new Duration(1))->nanoseconds);
    }

    public function testSecondsConversionBoundary(): void
    {
        $maxSeconds = intdiv(PHP_INT_MAX, 1_000_000_000);
        self::assertSame($maxSeconds * 1_000_000_000, Duration::seconds($maxSeconds)->nanoseconds);
        $this->expectException(InvalidConfigurationException::class);
        Duration::seconds($maxSeconds + 1);
    }

    public function testMillisecondsConversionOverflow(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        Duration::milliseconds(PHP_INT_MAX);
    }

    public function testMultiplyOverflowAndZero(): void
    {
        self::assertTrue((new Duration(PHP_INT_MAX))->multiply(0)->isZero());
        $this->expectException(InvalidConfigurationException::class);
        (new Duration(PHP_INT_MAX))->multiply(2);
    }

    public function testSubtractBelowZeroRejectedButSaturatingClamps(): void
    {
        self::assertTrue((new Duration(1))->saturatingSubtract(new Duration(5))->isZero());
        $this->expectException(InvalidConfigurationException::class);
        (new Duration(1))->subtract(new Duration(5));
    }

    public function testSharedMicrosecondQuantizationRoundsUp(): void
    {
        self::assertSame(1, (new Duration(999))->ceilMicroseconds());
        self::assertSame(0, Duration::zero()->ceilMicroseconds());
        self::assertSame(1, (new Duration(1_000))->ceilMicroseconds());
        self::assertSame(2, (new Duration(1_001))->ceilMicroseconds());
        self::assertSame(9_007_199_254_740_991, (new Duration(9_007_199_254_740_991_000))->ceilMicroseconds());
    }

    public function testMinMaxCompare(): void
    {
        $a = new Duration(1);
        $b = new Duration(2);
        self::assertSame($a, $a->min($b));
        self::assertSame($b, $a->max($b));
        self::assertSame(-1, $a->compare($b));
        self::assertTrue($a->equals(new Duration(1)));
    }
}
