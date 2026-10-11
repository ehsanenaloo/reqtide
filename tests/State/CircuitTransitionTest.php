<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\State\CircuitAdmission;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\CircuitTransition;
use Enaxon\Reqtide\State\FinalizationResult;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;

final class CircuitTransitionTest extends TestCase
{
    public function testHoldsBoundedCodes(): void
    {
        $transition = new CircuitTransition(CircuitTransition::CLOSED, CircuitTransition::OPEN, 'circuit.threshold_exceeded');

        self::assertSame('closed', $transition->fromState);
        self::assertSame('open', $transition->toState);
        self::assertSame('circuit.threshold_exceeded', $transition->reasonCode);
        self::assertSame('half_open', CircuitTransition::HALF_OPEN);
    }

    public function testRejectsInvalidCodesAndNoOpTransitions(): void
    {
        foreach ([
            ['closed', 'open', ''],
            ['closed', 'open', 'has space'],
            ['closed', 'open', str_repeat('a', 65)],
            ['', 'open', 'x'],
            ['closed', 'op en', 'x'],
            ['open', 'open', 'x'],
        ] as [$from, $to, $reason]) {
            try {
                new CircuitTransition($from, $to, $reason);
                self::fail('Expected rejection of ' . $from . '/' . $to . '/' . $reason);
            } catch (InvalidConfigurationException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testResultsAcceptTheTransitionAsAnOptionalLastArgumentWithNullDefault(): void
    {
        $transition = new CircuitTransition('open', 'half_open', 'circuit.open_elapsed');

        self::assertNull((new FinalizationResult(FinalizationStatus::Applied))->transition);
        self::assertSame($transition, (new FinalizationResult(FinalizationStatus::Applied, $transition))->transition);
        self::assertNull((new CircuitAdmission(CircuitAdmissionStatus::Open, null, new Duration(5)))->transition);
        self::assertSame($transition, (new CircuitAdmission(CircuitAdmissionStatus::Open, null, new Duration(5), $transition))->transition);
    }
}
