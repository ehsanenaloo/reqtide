<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Pipeline\Internal;

use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Error\Stage;
use Enaxon\Reqtide\Internal\NumericLimits;

/**
 * Per-operation bounded diagnostic buffer: at most 32 entries, further entries are only counted.
 *
 * @internal
 */
final class DiagnosticLog
{
    /** @var list<Diagnostic> */
    private array $entries = [];

    private int $omitted = 0;

    public function add(Stage $stage, ReasonCode $reason): void
    {
        $this->addDiagnostic(Diagnostic::of($stage, $reason));
    }

    public function addDiagnostic(Diagnostic $diagnostic): void
    {
        if (count($this->entries) >= NumericLimits::MAX_DIAGNOSTICS) {
            ++$this->omitted;

            return;
        }
        $this->entries[] = $diagnostic;
    }

    /** @return list<Diagnostic> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function omitted(): int
    {
        return $this->omitted;
    }
}
