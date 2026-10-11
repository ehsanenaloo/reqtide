<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Contract;

use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\ApplicationId;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\EnvironmentId;
use Enaxon\Reqtide\Value\PartitionIdentity;
use Enaxon\Reqtide\Value\PermitOwnerId;
use Enaxon\Reqtide\Value\PolicyId;
use Enaxon\Reqtide\Value\PolicyIdentity;
use Enaxon\Reqtide\Value\ProfileVersion;
use Enaxon\Reqtide\Value\SubjectId;

/** Deterministic caller-side identities (attempt, command, owner) and policy identities for contract suites. */
final class ContractIdentities
{
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

    public static function identity(string $subject = 'subject'): PolicyIdentity
    {
        return new PolicyIdentity(
            new PartitionIdentity(new ApplicationId('app'), new EnvironmentId('test'), null, new SubjectId($subject)),
            new ProfileVersion('v1'),
            new PolicyId('policy'),
        );
    }
}
