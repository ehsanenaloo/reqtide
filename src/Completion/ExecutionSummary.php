<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Completion;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\ProfileVersion;

final readonly class ExecutionSummary
{
    public function __construct(
        public OperationId $operationId,
        public ProfileVersion $profileVersion,
        public CompletionDisposition $disposition,
        public int $dispatched,
        public int $admittedNotDispatched,
        public int $retries,
        public int $successfulAttempts,
        public int $failedAttempts,
        public int $neutralAttempts,
        public int $actualValues,
        public int $actualExceptions,
        public int $fallbackExecutions,
        public bool $accountingComplete,
        public int $omittedDiagnostics,
        public ?Duration $elapsed,
        /** Indeterminate rate debits: the true admitted-not-dispatched count lies in [admittedNotDispatched, admittedNotDispatched + admissionUncertain]. */
        public int $admissionUncertain = 0,
    ) {
        $counts = [
            'dispatched' => $dispatched,
            'admittedNotDispatched' => $admittedNotDispatched,
            'retries' => $retries,
            'successfulAttempts' => $successfulAttempts,
            'failedAttempts' => $failedAttempts,
            'neutralAttempts' => $neutralAttempts,
            'actualValues' => $actualValues,
            'actualExceptions' => $actualExceptions,
            'fallbackExecutions' => $fallbackExecutions,
            'omittedDiagnostics' => $omittedDiagnostics,
            'admissionUncertain' => $admissionUncertain,
        ];
        foreach ($counts as $name => $count) {
            if ($count < 0) {
                throw new InvalidConfigurationException($name . ' must not be negative.');
            }
        }
        if ($dispatched > 64) {
            throw new InvalidConfigurationException('At most 64 attempts can be dispatched.');
        }
        foreach (['successfulAttempts', 'failedAttempts', 'neutralAttempts', 'actualValues', 'actualExceptions'] as $name) {
            if ($counts[$name] > $dispatched) {
                throw new InvalidConfigurationException($name . ' must not exceed dispatched.');
            }
        }
        if ($successfulAttempts + $failedAttempts + $neutralAttempts !== $dispatched) {
            throw new InvalidConfigurationException('Attempt disposition counts must sum to dispatched.');
        }
        if ($actualValues + $actualExceptions !== $dispatched) {
            throw new InvalidConfigurationException('Outcome kind counts must sum to dispatched.');
        }
        if ($admissionUncertain > 64) {
            throw new InvalidConfigurationException('At most 64 admissions can be uncertain.');
        }
        if ($admissionUncertain > 0 && $accountingComplete) {
            throw new InvalidConfigurationException('An uncertain admission requires incomplete accounting.');
        }
        if ($elapsed === null && $accountingComplete) {
            throw new InvalidConfigurationException('Missing elapsed time requires incomplete accounting.');
        }
    }
}
