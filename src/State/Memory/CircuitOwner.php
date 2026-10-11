<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State\Memory;

use Enaxon\Reqtide\State\CircuitPermitKind;

/**
 * Active ownership of one circuit permit slot.
 *
 * @internal
 */
final readonly class CircuitOwner
{
    public function __construct(
        public string $attemptHex,
        public CircuitPermitKind $kind,
        public int $generation,
        public int $expiresAt,
    ) {}
}
