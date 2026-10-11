<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Duration;

interface CooldownBackend extends StateBackend
{
    /** @throws StateUnavailableException|CorruptStateException */
    public function inspect(CooldownPolicy $policy): CooldownDecision;

    /**
     * Atomic max(existing, new); never shortens a cooldown.
     *
     * @throws StateUnavailableException|CorruptStateException|IndeterminateStateException
     */
    public function extend(CooldownPolicy $policy, Duration $extension, CommandId $command): CooldownDecision;
}
