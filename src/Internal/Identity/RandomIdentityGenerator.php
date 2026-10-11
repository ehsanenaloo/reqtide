<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Internal\Identity;

use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\IncarnationId;
use Enaxon\Reqtide\Value\PermitOwnerId;

/** @internal */
final class RandomIdentityGenerator implements IdentityGenerator
{
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

    private function hex(): string
    {
        return bin2hex(random_bytes(16));
    }
}
