<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline\Support;

use Enaxon\Reqtide\Exception\PreparationFailedException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Runtime\AttemptOperation;
use Enaxon\Reqtide\Runtime\PreparedAttempt;

/**
 * Typed attempt operation whose preparation, final replay verification, invocation and outcome disposal are all
 * observable and can fail on demand.
 *
 * @implements AttemptOperation<mixed>
 * @implements PreparedAttempt<mixed>
 */
final class SpyOperation implements AttemptOperation, PreparedAttempt
{
    public int $prepared = 0;

    public int $verified = 0;

    public int $invoked = 0;

    /** @var list<Outcome<mixed>> */
    public array $discarded = [];

    public ?\Throwable $prepareFailure = null;

    public ?\Throwable $verifyFailure = null;

    /** @var (\Closure(): string)|null */
    public ?\Closure $body = null;

    /** @var (\Closure(): void)|null runs inside discardOutcome() */
    public ?\Closure $onDiscard = null;

    public function __construct(private readonly ?CallLog $log = null) {}

    public function prepare(AttemptContext $context): PreparedAttempt
    {
        ++$this->prepared;
        $this->log?->add('prepare');
        if ($this->prepareFailure !== null) {
            throw new PreparationFailedException($this->prepareFailure);
        }

        return $this;
    }

    public function verifyReplay(AttemptContext $context): void
    {
        ++$this->verified;
        $this->log?->add('verify');
        if ($this->verifyFailure !== null) {
            throw new PreparationFailedException($this->verifyFailure);
        }
    }

    public function invoke(AttemptContext $context): mixed
    {
        ++$this->invoked;
        $this->log?->add('dispatch');

        return $this->body === null ? 'value' : ($this->body)();
    }

    public function discardOutcome(Outcome $outcome): void
    {
        $this->log?->add('discard');
        $this->discarded[] = $outcome;
        if ($this->onDiscard !== null) {
            ($this->onDiscard)();
        }
    }
}
