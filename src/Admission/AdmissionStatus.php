<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Admission;

enum AdmissionStatus: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Deferred = 'deferred';
    case Impossible = 'impossible';
}
