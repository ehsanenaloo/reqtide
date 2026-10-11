<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Error;

use Enaxon\Reqtide\Error\Severity;
use PHPUnit\Framework\TestCase;

/** The advisory severity scale: stable PSR-3 names, strictly ascending ranks and an inclusive threshold. */
final class SeverityRankTest extends TestCase
{
    public function testRanksAscendFromDebugToCritical(): void
    {
        self::assertSame(
            ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5],
            array_combine(
                array_map(static fn(Severity $s): string => $s->psr3Level(), Severity::cases()),
                array_map(static fn(Severity $s): int => $s->rank(), Severity::cases()),
            ),
        );
    }

    public function testThePsr3NameIsTheCaseValue(): void
    {
        foreach (Severity::cases() as $severity) {
            self::assertSame($severity->value, $severity->psr3Level());
        }
    }

    public function testIsAtLeastIsInclusiveAndOrdered(): void
    {
        foreach (Severity::cases() as $subject) {
            foreach (Severity::cases() as $threshold) {
                self::assertSame($subject->rank() >= $threshold->rank(), $subject->isAtLeast($threshold), $subject->value . ' vs ' . $threshold->value);
            }
        }
        self::assertTrue(Severity::Warning->isAtLeast(Severity::Warning));
        self::assertTrue(Severity::Error->isAtLeast(Severity::Warning));
        self::assertFalse(Severity::Notice->isAtLeast(Severity::Warning));
    }
}
