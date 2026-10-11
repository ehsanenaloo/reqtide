<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\State;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;

final readonly class ConcurrencyAdmission
{
    /** Permit iff Accepted. */
    public function __construct(public ConcurrencyAdmissionStatus $status, public ?ConcurrencyPermit $permit)
    {
        if (($status === ConcurrencyAdmissionStatus::Accepted) !== ($permit !== null)) {
            throw new InvalidConfigurationException('A concurrency permit is present if and only if the admission is Accepted.');
        }
    }
}
