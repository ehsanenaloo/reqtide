<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Contract;

/**
 * The controllable clock of the authority a backend reads, as seen by a contract suite.
 *
 * Values are nanoseconds on a harness-absolute axis whose zero sits on an epoch day boundary of the authority's wall
 * clock, so a suite can state aligned-window expectations without knowing the backend. A process-local backend maps
 * the axis onto its injected monotonic clock. A shared backend maps it onto the server clock (for Redis, a clock under
 * test control; no time parameter is ever added to the code under test).
 *
 * Moving backwards is a clock regression, not an error.
 */
interface ControllableTime
{
    /** Current position on the axis, in nanoseconds. */
    public function now(): int;

    /** Move to an absolute position, forwards or backwards. */
    public function moveTo(int $nanoseconds): void;

    /** Move forwards by a non-negative number of nanoseconds (zero is allowed and changes nothing). */
    public function advance(int $nanoseconds): void;

    /**
     * The smallest step the authority can tell apart, in nanoseconds: 1 for a nanosecond monotonic clock, 1,000 for a
     * microsecond server clock. Suites use it for "one tick before the boundary" expectations.
     */
    public function step(): int;
}
