<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State\Memory;

use Enaxon\Reqtide\Time\Clock;

/**
 * Protected nondecreasing effective time for one backend instance.
 *
 * `Clock` promises a nondecreasing reading, but a regression must still never refill, expire or shorten
 * anything, so every mutation uses `max(watermark, observed)` and stores it.
 *
 * @internal
 */
final class MonotonicWatermark
{
    private int $watermark = 0;

    public function __construct(private readonly Clock $clock) {}

    /** Effective time for a mutation; advances the watermark. */
    public function advance(): int
    {
        $observed = $this->clock->monotonicNow();
        if ($observed > $this->watermark) {
            $this->watermark = $observed;
        }

        return $this->watermark;
    }

    /** Effective time for a read-only inspection; stores nothing. */
    public function peek(): int
    {
        return max($this->watermark, $this->clock->monotonicNow());
    }
}
