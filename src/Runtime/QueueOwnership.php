<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Runtime;

enum QueueOwnership: string
{
    case None = 'none';
    case AdapterOwned = 'adapter_owned';
}
