<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Support;

use Enaxon\Reqtide\Guzzle\HttpOutcomeClassifier;
use Enaxon\Reqtide\Time\Clock;

/** A replaced configuration used by the classifier tests: only 500 is retried and may fall back. */
final class CustomClassifier
{
    public static function custom(Clock $clock): HttpOutcomeClassifier
    {
        return HttpOutcomeClassifier::starter($clock)
            ->withRetryableStatuses(500)
            ->withFallbackStatuses(500)
            ->withRetryableTransportErrnos(7);
    }
}
