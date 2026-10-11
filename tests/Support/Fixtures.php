<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Support;

use Enaxon\Reqtide\Admission\AdmissionDecision;
use Enaxon\Reqtide\Admission\AdmissionStatus;
use Enaxon\Reqtide\Completion\ActualAttemptDisposition;
use Enaxon\Reqtide\Completion\AttemptRecord;
use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Completion\ExecutionSummary;
use Enaxon\Reqtide\Completion\SettlementOverride;
use Enaxon\Reqtide\Exception\ClassificationFailedException;
use Enaxon\Reqtide\Exception\ReqtideException;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Outcome\Acceptability;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Outcome\FallbackEligibility;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Outcome\OutcomeClassification;
use Enaxon\Reqtide\Outcome\RetryEligibility;
use Enaxon\Reqtide\Value\ApplicationId;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\EnvironmentId;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\PartitionIdentity;
use Enaxon\Reqtide\Value\PolicyId;
use Enaxon\Reqtide\Value\PolicyIdentity;
use Enaxon\Reqtide\Value\ProfileVersion;
use Enaxon\Reqtide\Value\SubjectId;

final class Fixtures
{
    public static function hex(int $n): string
    {
        return str_pad(dechex($n), 32, '0', STR_PAD_LEFT);
    }

    public static function partition(): PartitionIdentity
    {
        return new PartitionIdentity(new ApplicationId('app'), new EnvironmentId('test'), null, new SubjectId('subject'));
    }

    public static function policyIdentity(): PolicyIdentity
    {
        return new PolicyIdentity(self::partition(), new ProfileVersion('v1'), new PolicyId('policy'));
    }

    public static function decision(AdmissionStatus $status = AdmissionStatus::Accepted): AdmissionDecision
    {
        return new AdmissionDecision(
            $status,
            'reason',
            null,
            $status === AdmissionStatus::Deferred ? Duration::seconds(1) : null,
        );
    }

    /** @return AttemptRecord<string> */
    public static function record(
        int $number,
        bool $value,
        CircuitVerdict $circuit,
        Acceptability $acceptability = Acceptability::Acceptable,
        SettlementOverride $override = SettlementOverride::None,
    ): AttemptRecord {
        $outcome = $value
            ? Outcome::fromValue('v', BoundedMetadata::empty())
            : Outcome::fromException(new \RuntimeException('x'), BoundedMetadata::empty());
        $classification = new OutcomeClassification(
            RetryEligibility::Ineligible,
            $circuit,
            FallbackEligibility::Ineligible,
            $acceptability,
            'test',
            null,
        );
        $disposition = match ($circuit) {
            CircuitVerdict::Success => ActualAttemptDisposition::Succeeded,
            CircuitVerdict::Failure => ActualAttemptDisposition::Failed,
            CircuitVerdict::Neutral => ActualAttemptDisposition::Neutral,
        };

        return new AttemptRecord(
            AttemptId::fromLowerHex(self::hex($number)),
            $number,
            $disposition,
            $outcome,
            Duration::milliseconds(1),
            $override,
            $classification,
            $override === SettlementOverride::None ? $circuit : CircuitVerdict::Neutral,
        );
    }

    /** @param list<AttemptRecord<string>> $records */
    public static function summary(CompletionDisposition $disposition, array $records, int $fallbackExecutions = 0): ExecutionSummary
    {
        $succeeded = $failed = $neutral = $values = $exceptions = 0;
        foreach ($records as $record) {
            match ($record->disposition) {
                ActualAttemptDisposition::Succeeded => ++$succeeded,
                ActualAttemptDisposition::Failed => ++$failed,
                ActualAttemptDisposition::Neutral => ++$neutral,
            };
            $record->outcome->kind->value === 'value' ? ++$values : ++$exceptions;
        }

        return new ExecutionSummary(
            new OperationId('op'),
            new ProfileVersion('v1'),
            $disposition,
            count($records),
            0,
            max(0, count($records) - 1),
            $succeeded,
            $failed,
            $neutral,
            $values,
            $exceptions,
            $fallbackExecutions,
            true,
            0,
            Duration::milliseconds(5),
        );
    }

    /**
     * @param list<AttemptRecord<string>> $records
     * @param Outcome<string>|null $fallback
     * @param ClassificationFailedException<string>|null $classifier
     * @return ExecutionCompletion<string>
     */
    public static function completion(
        CompletionDisposition $disposition,
        array $records = [],
        ?Outcome $fallback = null,
        ?ReqtideException $policy = null,
        ?ClassificationFailedException $classifier = null,
        ?CompletionDisposition $summaryDisposition = null,
    ): ExecutionCompletion {
        $terminal = $records === [] ? null : $records[count($records) - 1]->outcome;

        return new ExecutionCompletion(
            $disposition,
            $terminal,
            $fallback,
            $policy,
            $classifier,
            $records,
            [],
            self::summary($summaryDisposition ?? $disposition, $records, $fallback === null ? 0 : 1),
        );
    }
}
