<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Execution;

enum OperationSafetyKind: string
{
    case Unsafe = 'unsafe';
    case ReplayableRead = 'replayable_read';
    case ProviderIdempotentMutation = 'provider_idempotent_mutation';
}
