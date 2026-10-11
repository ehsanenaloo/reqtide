<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Runtime;

enum InvocationForm: string
{
    case Synchronous = 'synchronous';
    case ObservedPromise = 'observed_promise';
}
