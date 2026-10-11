<?php

declare(strict_types=1);

namespace Comparison;

enum Status: string
{
    /** Observed behaviour satisfies the scenario's expected behaviour. */
    case Pass = 'PASS';
    /** Observed behaviour violates the expected behaviour although the scenario applies to the library. */
    case Fail = 'FAIL';
    /** The scenario's semantics do not match what the library is (different scope or algorithm). */
    case NotApplicable = 'NOT_APPLICABLE';
    /** The scenario applies, but the library's public API gives no way to configure or observe it. */
    case NotExpressible = 'NOT_EXPRESSIBLE';
    /** Measurement rows (dependency count, PHP floor): a value is recorded, no verdict. */
    case Info = 'INFO';
    /** Reserved for the Reqtide slot until its adapter exists. */
    case NotRun = 'NOT_RUN';
}
