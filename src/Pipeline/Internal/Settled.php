<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Pipeline\Internal;

use Enaxon\Reqtide\Completion\AttemptRecord;
use Enaxon\Reqtide\Completion\SettlementOverride;
use Enaxon\Reqtide\Exception\ClassificationFailedException;
use Enaxon\Reqtide\Outcome\OutcomeClassification;
use Enaxon\Reqtide\Runtime\PreparedAttempt;

/**
 * A finalized, dispatched attempt.
 *
 * @internal
 * @template T
 */
final readonly class Settled
{
    /**
     * @param AttemptRecord<T> $record
     * @param PreparedAttempt<T> $prepared
     * @param ClassificationFailedException<T>|null $classifierFailure
     */
    public function __construct(
        public AttemptRecord $record,
        public PreparedAttempt $prepared,
        public ?OutcomeClassification $classification,
        public ?ClassificationFailedException $classifierFailure,
        public SettlementOverride $override,
    ) {}
}
