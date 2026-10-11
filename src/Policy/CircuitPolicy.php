<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Policy;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Internal\Horizon;
use Enaxon\Reqtide\Internal\NumericLimits;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PolicyIdentity;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\Ratio;

final readonly class CircuitPolicy
{
    public function __construct(
        public PolicyIdentity $identity,
        public CircuitWindowOptions $window,
        public PositiveInt $minimumSamples,
        public Ratio $failureThreshold,
        public Duration $openDuration,
        public PositiveInt $probeCapacity,
        public PositiveInt $probeSuccessThreshold,
        public Duration $permitLease,
    ) {
        if ($probeSuccessThreshold->value > $probeCapacity->value) {
            throw new InvalidConfigurationException('probeSuccessThreshold must not exceed probeCapacity.');
        }
        if ($window instanceof CountCircuitWindow && $minimumSamples->value > $window->completedSamples->value) {
            throw new InvalidConfigurationException('minimumSamples must not exceed the count window.');
        }
        if ($minimumSamples->value > NumericLimits::MAX_AGGREGATE_SAMPLES) {
            throw new InvalidConfigurationException('minimumSamples must not exceed 9007199254739968.');
        }
        if (!$openDuration->isPositive() || !$permitLease->isPositive()) {
            throw new InvalidConfigurationException('openDuration and permitLease must be positive.');
        }
        Horizon::assert($openDuration, 'openDuration');
        Horizon::assert($permitLease, 'permitLease');
    }
}
