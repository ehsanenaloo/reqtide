<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Runtime;

enum CancellationCapability: string
{
    case CheckpointOnly = 'checkpoint_only';
    case TransportRequest = 'transport_request';
}
