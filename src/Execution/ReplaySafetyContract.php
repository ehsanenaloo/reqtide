<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Execution;

use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Internal\Codes;
use Enaxon\Reqtide\Time\Clock;
use Enaxon\Reqtide\Time\SystemClock;
use Enaxon\Reqtide\Value\Duration;

/**
 * Explicit host/provider assertion that an operation may be repeated. Core never verifies it.
 * The replay-horizon-covers-total-operation check belongs to pipeline preflight, which has the deadline.
 */
final readonly class ReplaySafetyContract
{
    /**
     * Contract for a generic callable that the CALLER asserts is a repeatable read: running it again has no
     * observable side effect and returns an equivalent result. Reqtide never verifies or infers this.
     * Calling this method IS the assertion; do not call it for a callable that creates, updates, deletes, sends
     * or charges anything. For a mutation use a provider idempotency key and `providerIdempotentMutation()`.
     *
     * - `$operationName` is a short bounded label for logs (printable ASCII, at most 128 bytes). It is not an
     *   idempotency key. Never put a URL with credentials, a token or personal data in it.
     * - `$validFor` is how long the assertion holds, counted from now. It becomes both the contract validity and
     *   the retention, so it must be at least as long as the deadline of any operation using the contract;
     *   otherwise the pipeline records `replay_horizon_inadequate` and does not retry. Create the contract per
     *   operation, next to the context.
     * - `$clock` must be the pipeline's own Clock (the contract is checked against its wall time). Omit it for
     *   `SystemClock`, which is what `InMemoryEnvironment` uses; pass the `VirtualClock` in tests, otherwise the
     *   pipeline sees a contract from the future or the past and does not retry (`replay_contract_not_valid`).
     * - `$retryableOutcomeCodes` are the classifier reason codes that may be retried. The default is the single
     *   code `ExceptionClassifier` reports for retryable exceptions, so the two work together without
     *   copying strings. A custom classifier needs its own codes here.
     *
     * @param list<string>|null $retryableOutcomeCodes
     * @throws InvalidConfigurationException for an unbounded label, a zero `$validFor` or invalid codes
     */
    public static function forReadOnlyCallable(
        string $operationName,
        Duration $validFor,
        ?Clock $clock = null,
        ?array $retryableOutcomeCodes = null,
    ): self {
        Codes::printable($operationName, 128, 'operationName');
        if (!$validFor->isPositive()) {
            throw new InvalidConfigurationException('forReadOnlyCallable(): validFor must be positive; it must cover the operation deadline.');
        }
        $now = ($clock ?? new SystemClock())->wallNow()->setTimezone(new \DateTimeZone('UTC'));
        $until = $now->modify('+' . $validFor->ceilMicroseconds() . ' microseconds');

        return new self(
            'caller-asserted-read-only-callable',
            $operationName,
            'caller-asserted',
            'caller asserts the callable is a repeatable read without side effects',
            $now,
            $until,
            $validFor,
            ReplayPreparation::CallableAssertion,
            $retryableOutcomeCodes ?? [ReasonCode::ExceptionRetryable->value],
        );
    }

    /** @param list<string> $retryableOutcomeCodes */
    public function __construct(
        public string $contractReference,
        public string $identityValue,
        public string $identityScope,
        public string $duplicateHandlingContract,
        public \DateTimeImmutable $establishedAt,
        public \DateTimeImmutable $validUntil,
        public Duration $retention,
        public ReplayPreparation $preparation,
        public array $retryableOutcomeCodes,
    ) {
        Codes::printable($contractReference, 256, 'contractReference');
        Codes::printable($identityValue, 128, 'identityValue');
        Codes::printable($identityScope, 256, 'identityScope');
        Codes::printable($duplicateHandlingContract, 256, 'duplicateHandlingContract');

        if ($establishedAt->getOffset() !== 0 || $validUntil->getOffset() !== 0) {
            throw new InvalidConfigurationException('Replay contract dates must be UTC.');
        }
        if ($validUntil <= $establishedAt) {
            throw new InvalidConfigurationException('validUntil must be later than establishedAt.');
        }
        if (!$retention->isPositive()) {
            throw new InvalidConfigurationException('Replay retention must be positive.');
        }
        if (!array_is_list($retryableOutcomeCodes) || count($retryableOutcomeCodes) > 32) {
            throw new InvalidConfigurationException('retryableOutcomeCodes must be a list of at most 32 codes.');
        }
        $seen = [];
        foreach ($retryableOutcomeCodes as $code) {
            Codes::code($code, 'retryable outcome code');
            if (isset($seen[$code])) {
                throw new InvalidConfigurationException('retryableOutcomeCodes must be distinct.');
            }
            $seen[$code] = true;
        }
    }
}
