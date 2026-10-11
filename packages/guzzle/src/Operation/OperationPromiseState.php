<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Operation;

use GuzzleHttp\Promise\Promise;

/**
 * What the two callbacks of an {@see OperationPromise} share: the promise they settle and whether the operation started.
 *
 * @internal
 */
final class OperationPromiseState
{
    public ?Promise $promise = null;

    public bool $started = false;
}
