<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline\Support;

use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\State\CooldownBackend;
use Enaxon\Reqtide\State\CooldownDecision;
use Enaxon\Reqtide\State\CooldownStatus;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Duration;

final class FakeCooldownBackend extends FakeBackend implements CooldownBackend
{
    public ?Duration $active = null;

    public ?\Throwable $inspectFailure = null;

    public ?\Throwable $extendFailure = null;

    /** @var list<Duration> */
    public array $extensions = [];

    public function inspect(CooldownPolicy $policy): CooldownDecision
    {
        $this->enter('cooldown.inspect');
        if ($this->inspectFailure !== null) {
            throw $this->inspectFailure;
        }

        return $this->active === null
            ? new CooldownDecision(CooldownStatus::Clear, Duration::zero())
            : new CooldownDecision(CooldownStatus::Active, $this->active);
    }

    public function extend(CooldownPolicy $policy, Duration $extension, CommandId $command): CooldownDecision
    {
        $this->enter('cooldown.extend');
        if ($this->extendFailure !== null) {
            throw $this->extendFailure;
        }
        $this->extensions[] = $extension;

        return new CooldownDecision(CooldownStatus::Active, $extension);
    }
}
