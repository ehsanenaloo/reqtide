<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Policy;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Internal\Horizon;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PolicyIdentity;

final readonly class CooldownPolicy
{
    /** Longest pause one reported wait can start when no maximum is given: a hostile or faulty answer must not park a partition for good. */
    public const DEFAULT_MAXIMUM_EXTENSION_SECONDS = 3600;

    public Duration $maximumExtension;

    /**
     * @param ?Duration $maximumExtension the longest shared pause one reported wait may start; longer waits are capped
     *                                    to it. Null selects one hour.
     * @throws InvalidConfigurationException
     */
    public function __construct(public PolicyIdentity $identity, public Duration $cleanupGrace, ?Duration $maximumExtension = null)
    {
        Horizon::assert($cleanupGrace, 'cleanupGrace');
        $maximumExtension ??= Duration::seconds(self::DEFAULT_MAXIMUM_EXTENSION_SECONDS);
        if (!$maximumExtension->isPositive()) {
            throw new InvalidConfigurationException('The maximum cooldown extension must be greater than zero.');
        }
        Horizon::assert($maximumExtension, 'maximumExtension');
        $this->maximumExtension = $maximumExtension;
    }
}
