<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Contract;

use Enaxon\Reqtide\State\StateScope;

/**
 * What a contract suite needs from a backend family besides the backend itself.
 *
 * A harness owns the authority (clock and storage) of every backend it creates; backends created by one harness share
 * that clock. Each backend returned by a factory method is independent state (fresh registry or fresh process-local
 * store), so a test can build a second backend with other ceilings.
 */
interface BackendHarness
{
    public function time(): ControllableTime;

    /** The scope the backend must declare in its capabilities. */
    public function expectedScope(): StateScope;

    /** Whether the backend must declare a finite I/O timeout (true for a shared authority, false for process-local). */
    public function expectsFiniteIoTimeout(): bool;

    /**
     * Let the backends created by this harness reclaim expired nonprotective state by whatever mechanism they use
     * (bounded maintenance for process-local state, key expiry or no-op for a shared authority). Reclamation must be
     * complete when this returns; the call changes no protective state.
     */
    public function reclaimExpired(): void;
}
