<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\State\RetryBudgetInspection;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\NonNegativeInt;
use PHPUnit\Framework\TestCase;

final class RetryBudgetInspectionTest extends TestCase
{
    public function testAGrantingInspectionNeedsATokenAndCarriesNoWait(): void
    {
        $granting = new RetryBudgetInspection(new NonNegativeInt(3), true, null);
        self::assertTrue($granting->wouldReserve);
        self::assertSame(3, $granting->availableTokens->value);

        $this->expectException(InvalidConfigurationException::class);
        new RetryBudgetInspection(new NonNegativeInt(0), true, null);
    }

    public function testAGrantingInspectionMayNotCarryAWait(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new RetryBudgetInspection(new NonNegativeInt(1), true, Duration::seconds(1));
    }

    public function testARefusingInspectionMayCarryAWaitOrNone(): void
    {
        self::assertNull((new RetryBudgetInspection(new NonNegativeInt(0), false, null))->retryAfter);
        self::assertSame(6, (new RetryBudgetInspection(new NonNegativeInt(0), false, Duration::seconds(6)))->retryAfter?->nanoseconds / 1_000_000_000);
    }
}
