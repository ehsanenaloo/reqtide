<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Internal\Identity;

use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\IncarnationId;
use Enaxon\Reqtide\Value\PermitOwnerId;

/**
 * Generates 128-bit identities for attempts, commands, permit owners and state incarnations.
 *
 * @internal Production generation is cryptographically random and not an application extension point.
 *           The testing package may supply a deterministic implementation to reproduce stale-token paths.
 */
interface IdentityGenerator
{
    public function attempt(): AttemptId;

    public function command(): CommandId;

    public function owner(): PermitOwnerId;

    public function incarnation(): IncarnationId;
}
