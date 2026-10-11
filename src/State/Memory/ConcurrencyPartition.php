<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State\Memory;

use Enaxon\Reqtide\Value\Generation;
use Enaxon\Reqtide\Value\IncarnationId;

/**
 * Mutable per-policy concurrency state. `owners` and `tombstones` hold nondecreasing deadlines, oldest first.
 *
 * @internal
 */
final class ConcurrencyPartition
{
    /** @var array<string, int> owner hex => lease expiry (monotonic ns) */
    public array $owners = [];

    /** @var array<string, int> finalized owner hex => tombstone retention deadline (monotonic ns) */
    public array $tombstones = [];

    public function __construct(
        public readonly string $fingerprint,
        public readonly IncarnationId $incarnation,
        public readonly Generation $generation,
    ) {}

    public function isEmpty(): bool
    {
        return $this->owners === [] && $this->tombstones === [];
    }
}
