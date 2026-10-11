<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Testing;

use Enaxon\Reqtide\Internal\Identity\IdentityGenerator;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\IncarnationId;
use Enaxon\Reqtide\Value\PermitOwnerId;

/**
 * Counter-based identities (zero-padded 32-hex of 1, 2, 3, ...) shared across all identity kinds,
 * so stale-token and ownership paths are reproducible. Never use outside tests.
 *
 * @api-for-tests
 *
 * @internal Fits only the internal identity seam; no public path injects it into a pipeline.
 */
final class DeterministicIdentityGenerator implements IdentityGenerator
{
    private int $next;
    private int $issued = 0;

    public function __construct(int $start = 1)
    {
        if ($start < 0) {
            throw new \InvalidArgumentException('The start counter must not be negative.');
        }
        $this->next = $start;
    }

    public function attempt(): AttemptId
    {
        return AttemptId::fromLowerHex($this->hex());
    }

    public function command(): CommandId
    {
        return CommandId::fromLowerHex($this->hex());
    }

    public function owner(): PermitOwnerId
    {
        return PermitOwnerId::fromLowerHex($this->hex());
    }

    public function incarnation(): IncarnationId
    {
        return IncarnationId::fromLowerHex($this->hex());
    }

    /** Number of identities issued so far. */
    public function issuedCount(): int
    {
        return $this->issued;
    }

    private function hex(): string
    {
        ++$this->issued;

        return str_pad(dechex($this->next++), 32, '0', STR_PAD_LEFT);
    }
}
