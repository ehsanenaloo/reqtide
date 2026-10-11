<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Value\Duration;

final readonly class StateBackendCapabilities
{
    /** A null `ioTimeout` makes no bounded-I/O claim. */
    public function __construct(public StateScope $scope, public ?Duration $ioTimeout, public Duration $clockResolution)
    {
        if ($ioTimeout !== null && !$ioTimeout->isPositive()) {
            throw new InvalidConfigurationException('A declared I/O timeout must be positive.');
        }
        if (!$clockResolution->isPositive()) {
            throw new InvalidConfigurationException('Clock resolution must be positive.');
        }
    }
}
