<?php

declare(strict_types=1);

namespace Comparison;

/**
 * One adapter per library under test. run() must EXECUTE the scenario against the real library and
 * return what was observed; it must never return a verdict that was not produced by running code,
 * except NOT_APPLICABLE / NOT_EXPRESSIBLE which must carry the reason.
 */
interface Adapter
{
    /** Stable short id, e.g. 'symfony-rate-limiter'. */
    public function id(): string;

    /** @return array{package:string, version:string, role:string, not_enabled:string} */
    public function describe(): array;

    /** @param array<string,mixed> $scenario one entry of Scenarios::all() */
    public function run(array $scenario): Result;
}
