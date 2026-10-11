<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Internal\Identity\IdentityGenerator;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\IncarnationId;
use Enaxon\Reqtide\Value\PermitOwnerId;

/** Deterministic identities for tests: every call returns the next value of one counter. */
final class SequenceIdentityGenerator implements IdentityGenerator
{
    private int $next = 0x1000;

    public function attempt(): AttemptId
    {
        return AttemptId::fromLowerHex(Fixtures::hex($this->next++));
    }

    public function command(): CommandId
    {
        return CommandId::fromLowerHex(Fixtures::hex($this->next++));
    }

    public function owner(): PermitOwnerId
    {
        return PermitOwnerId::fromLowerHex(Fixtures::hex($this->next++));
    }

    public function incarnation(): IncarnationId
    {
        return IncarnationId::fromLowerHex(Fixtures::hex($this->next++));
    }
}
