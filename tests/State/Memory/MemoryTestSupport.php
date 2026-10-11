<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Internal\Identity\IdentityGenerator;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\ApplicationId;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\EnvironmentId;
use Enaxon\Reqtide\Value\IncarnationId;
use Enaxon\Reqtide\Value\PartitionIdentity;
use Enaxon\Reqtide\Value\PermitOwnerId;
use Enaxon\Reqtide\Value\PolicyId;
use Enaxon\Reqtide\Value\PolicyIdentity;
use Enaxon\Reqtide\Value\ProfileVersion;
use Enaxon\Reqtide\Value\SubjectId;

/** Deterministic identities and fixtures for the process-local backend tests. */
final class MemoryTestSupport implements IdentityGenerator
{
    private int $incarnations = 0;

    private int $owners = 0;

    private int $attempts = 0;

    private int $commands = 0;

    public function attempt(): AttemptId
    {
        return AttemptId::fromLowerHex(Fixtures::hex(0xA000 + ++$this->attempts));
    }

    public function command(): CommandId
    {
        return CommandId::fromLowerHex(Fixtures::hex(0xC000 + ++$this->commands));
    }

    public function owner(): PermitOwnerId
    {
        return PermitOwnerId::fromLowerHex(Fixtures::hex(0xB000 + ++$this->owners));
    }

    public function incarnation(): IncarnationId
    {
        return IncarnationId::fromLowerHex(Fixtures::hex(0x1000 + ++$this->incarnations));
    }

    public static function identity(string $subject = 'subject'): PolicyIdentity
    {
        return new PolicyIdentity(
            new PartitionIdentity(new ApplicationId('app'), new EnvironmentId('test'), null, new SubjectId($subject)),
            new ProfileVersion('v1'),
            new PolicyId('policy'),
        );
    }
}
