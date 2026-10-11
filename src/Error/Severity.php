<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Error;

/**
 * Advisory importance of a reason code or event, for log and metric sinks.
 *
 * The case names map one to one onto the lowercase PSR-3 level names. Core imports no PSR package: a bridge
 * passes `psr3Level()` to its logger. Severity is advisory and may be tuned in a minor release.
 */
enum Severity: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';

    /** The PSR-3 level name as a plain string (`debug`, `info`, `notice`, `warning`, `error`, `critical`). */
    public function psr3Level(): string
    {
        return match ($this) {
            self::Debug => 'debug',
            self::Info => 'info',
            self::Notice => 'notice',
            self::Warning => 'warning',
            self::Error => 'error',
            self::Critical => 'critical',
        };
    }

    /** Ascending order: Debug is 0, Critical is 5. */
    public function rank(): int
    {
        return match ($this) {
            self::Debug => 0,
            self::Info => 1,
            self::Notice => 2,
            self::Warning => 3,
            self::Error => 4,
            self::Critical => 5,
        };
    }

    /** True iff this severity is at least as important as $threshold. */
    public function isAtLeast(self $threshold): bool
    {
        return $this->rank() >= $threshold->rank();
    }
}
