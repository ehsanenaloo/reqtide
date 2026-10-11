<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline\Support;

use Enaxon\Reqtide\State\StateBackend;
use Enaxon\Reqtide\State\StateBackendCapabilities;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\IncarnationId;

/** Shared plumbing of the hand-written fake state backends. */
abstract class FakeBackend implements StateBackend
{
    public ?Duration $ioTimeout = null;

    public StateScope $scope = StateScope::ProcessLocal;

    /** @var (\Closure(string): void)|null runs at the start of every call; receives "<kind>.<method>" */
    public ?\Closure $onCall = null;

    public function __construct(protected readonly CallLog $log) {}

    public function capabilities(): StateBackendCapabilities
    {
        return new StateBackendCapabilities($this->scope, $this->ioTimeout, Duration::microseconds(1));
    }

    protected function enter(string $name): void
    {
        $this->log->add($name);
        if ($this->onCall !== null) {
            ($this->onCall)($name);
        }
    }

    protected static function incarnation(): IncarnationId
    {
        return IncarnationId::fromLowerHex(Fixtures::hex(41));
    }
}
