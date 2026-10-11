<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Execution;

final readonly class OperationSafety
{
    private function __construct(public OperationSafetyKind $kind, public ?ReplaySafetyContract $contract) {}

    public static function unsafe(): self
    {
        return new self(OperationSafetyKind::Unsafe, null);
    }

    public static function replayableRead(ReplaySafetyContract $contract): self
    {
        return new self(OperationSafetyKind::ReplayableRead, $contract);
    }

    public static function providerIdempotentMutation(ReplaySafetyContract $contract): self
    {
        return new self(OperationSafetyKind::ProviderIdempotentMutation, $contract);
    }
}
