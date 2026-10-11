<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Completion;

use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Error\Stage;
use Enaxon\Reqtide\Internal\Codes;

/**
 * Bounded stable codes only: never an exception message, body, header or secret.
 *
 * Reqtide itself builds diagnostics with `Diagnostic::of(Stage, ReasonCode)`; the string constructor stays for
 * runtime adapters and tests. Read the typed codes with `stage()` and `reason()`, which are null for a code
 * that is not a registry member.
 */
final readonly class Diagnostic
{
    public function __construct(public string $stageCode, public string $reasonCode)
    {
        Codes::code($stageCode, 'stageCode');
        Codes::code($reasonCode, 'reasonCode');
    }

    public static function of(Stage $stage, ReasonCode $reason): self
    {
        return new self($stage->value, $reason->value);
    }

    public function stage(): ?Stage
    {
        return Stage::tryFrom($this->stageCode);
    }

    public function reason(): ?ReasonCode
    {
        return ReasonCode::tryFrom($this->reasonCode);
    }
}
