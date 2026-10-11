<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Internal\Codes;

/**
 * Fact that one backend call moved a circuit between states. State names and the reason are bounded stable
 * codes; the value carries no behavior and is for the caller to turn into telemetry.
 */
final readonly class CircuitTransition
{
    public const CLOSED = 'closed';

    public const OPEN = 'open';

    public const HALF_OPEN = 'half_open';

    public function __construct(public string $fromState, public string $toState, public string $reasonCode)
    {
        Codes::code($fromState, 'fromState');
        Codes::code($toState, 'toState');
        Codes::code($reasonCode, 'reasonCode');
        if ($fromState === $toState) {
            throw new InvalidConfigurationException('A circuit transition must change state.');
        }
    }
}
