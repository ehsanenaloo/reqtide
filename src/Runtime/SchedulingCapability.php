<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Runtime;

enum SchedulingCapability: string
{
    case None = 'none';
    case BoundedBlocking = 'bounded_blocking';
    case AdapterDrivenQueue = 'adapter_driven_queue';
}
