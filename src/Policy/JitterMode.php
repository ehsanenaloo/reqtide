<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Policy;

enum JitterMode: string
{
    case None = 'none';
    case Full = 'full';
}
