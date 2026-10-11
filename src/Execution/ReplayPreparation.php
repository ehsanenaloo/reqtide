<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Execution;

enum ReplayPreparation: string
{
    case CallableAssertion = 'callable_assertion';
    case VerifiedRewind = 'verified_rewind';
    case BodyFactory = 'body_factory';
}
