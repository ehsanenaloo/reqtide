<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\OperationMetadata;
use Enaxon\Reqtide\Outcome\Acceptability;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Outcome\FallbackEligibility;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Outcome\OutcomeClassification;
use Enaxon\Reqtide\Outcome\OutcomeClassifier;
use Enaxon\Reqtide\Outcome\OutcomeKind;
use Enaxon\Reqtide\Outcome\RetryEligibility;
use Enaxon\Reqtide\Time\Clock;
use Enaxon\Reqtide\Time\SystemClock;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use Psr\Http\Message\ResponseInterface;

/**
 * Classifies a Guzzle outcome: a response, or an exception thrown by the transfer.
 *
 * Nothing is inferred from the status class: what is retried, what counts against a circuit and what may fall back are
 * explicit tables. {@see starter()} is a documented starting set, not a recommendation for every API; {@see empty()}
 * retries and falls back on nothing. Every method returns a new instance.
 *
 * Starter set
 * -----------
 * | Outcome                                   | retry     | circuit | fallback   | acceptability | reason code                |
 * |-------------------------------------------|-----------|---------|------------|---------------|----------------------------|
 * | status below 400                          | ineligible| success | ineligible | acceptable    | `http_status_<code>`       |
 * | 408, 502, 503, 504                        | eligible  | failure | ineligible | unacceptable  | `http_status_<code>`       |
 * | 429                                       | eligible  | neutral | ineligible | unacceptable  | `http_status_429`          |
 * | 500                                       | ineligible| failure | ineligible | unacceptable  | `http_status_500`          |
 * | any other 4xx or 5xx                      | ineligible| success | ineligible | unacceptable  | `http_status_<code>`       |
 * | transport error 6, 7, 28, 52, 55, 56      | eligible  | failure | ineligible | unacceptable  | `http_transport_errno_<n>` |
 * | TLS or certificate error                  | ineligible| failure | ineligible | unacceptable  | `http_transport_errno_<n>` |
 * | malformed or unsupported request          | ineligible| neutral | ineligible | unacceptable  | `http_transport_errno_<n>` |
 * | any other transport error                 | ineligible| failure | ineligible | unacceptable  | `http_transport_errno_<n>` |
 * | too many redirects                        | ineligible| failure | ineligible | unacceptable  | `http_too_many_redirects`  |
 * | any other exception                       | ineligible| failure | ineligible | unacceptable  | `http_exception`           |
 *
 * A 4xx other than 408 and 429 proves the server is alive and judged the request, so it closes a half-open circuit and
 * is never retried. A 429 is a throttle signal, handled through `Retry-After` and the cooldown rather than the circuit.
 * A 500 is not retried by default because it often means a deterministic server defect; add it with
 * {@see withRetryableStatuses()} if your API documents otherwise.
 *
 * `http_errors`: an exception that carries a response (ClientException, ServerException) is classified exactly like the
 * response itself, so the Guzzle option cannot change the meaning. A valid non-success response is thereby
 * distinguishable from a transport failure by its reason code.
 *
 * `Retry-After` is read for statuses in {@see withRetryAfterStatuses()} (starter: 429 and 503), strictly, by
 * {@see RetryAfter}. A line that cannot be read creates no lower bound. The classifier has no channel for a
 * diagnostic on a malformed value; the reading is available from {@see RetryAfter::read()}.
 *
 * Reason codes that can be retry-eligible are listed by {@see retryableOutcomeCodes()}; pass them to
 * `HttpOperation::read()` and `HttpOperation::idempotentMutation()`.
 */
final readonly class HttpOutcomeClassifier implements OutcomeClassifier
{
    private const TLS_ERRNOS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    private const MALFORMED_ERRNOS = [1, 2, 3, 4, 5, 43, 45, 49, 61, 62, 63, 65, 67];

    /**
     * @param list<int> $retryableStatuses
     * @param list<int> $circuitFailureStatuses
     * @param list<int> $circuitNeutralStatuses
     * @param list<int> $fallbackStatuses
     * @param list<int> $retryAfterStatuses
     * @param list<int> $retryableErrnos
     */
    private function __construct(
        private Clock $clock,
        private array $retryableStatuses,
        private array $circuitFailureStatuses,
        private array $circuitNeutralStatuses,
        private array $fallbackStatuses,
        private array $retryAfterStatuses,
        private array $retryableErrnos,
    ) {}

    /** The documented starter tables. `$clock` supplies the wall time for `Retry-After` dates (default: the system clock). */
    public static function starter(?Clock $clock = null): self
    {
        return new self($clock ?? new SystemClock(), [408, 429, 502, 503, 504], [408, 500, 502, 503, 504], [429], [], [429, 503], [6, 7, 28, 52, 55, 56]);
    }

    /** No retry, no fallback, no `Retry-After`: every response below 400 is a success and every error status a plain failure. */
    public static function empty(?Clock $clock = null): self
    {
        return new self($clock ?? new SystemClock(), [], [], [], [], [], []);
    }

    /** Replaces the statuses that are retry-eligible. */
    public function withRetryableStatuses(int ...$statuses): self
    {
        return new self($this->clock, self::statuses($statuses), $this->circuitFailureStatuses, $this->circuitNeutralStatuses, $this->fallbackStatuses, $this->retryAfterStatuses, $this->retryableErrnos);
    }

    /** Replaces the statuses that count as circuit failures (a status in neither circuit list is a success). */
    public function withCircuitFailureStatuses(int ...$statuses): self
    {
        return new self($this->clock, $this->retryableStatuses, self::statuses($statuses), $this->circuitNeutralStatuses, $this->fallbackStatuses, $this->retryAfterStatuses, $this->retryableErrnos);
    }

    /** Replaces the statuses that are circuit-neutral (neither success nor failure). A status in both lists is a failure. */
    public function withCircuitNeutralStatuses(int ...$statuses): self
    {
        return new self($this->clock, $this->retryableStatuses, $this->circuitFailureStatuses, self::statuses($statuses), $this->fallbackStatuses, $this->retryAfterStatuses, $this->retryableErrnos);
    }

    /** Replaces the statuses for which a fallback may replace the response. */
    public function withFallbackStatuses(int ...$statuses): self
    {
        return new self($this->clock, $this->retryableStatuses, $this->circuitFailureStatuses, $this->circuitNeutralStatuses, self::statuses($statuses), $this->retryAfterStatuses, $this->retryableErrnos);
    }

    /** Replaces the statuses whose `Retry-After` header is read. */
    public function withRetryAfterStatuses(int ...$statuses): self
    {
        return new self($this->clock, $this->retryableStatuses, $this->circuitFailureStatuses, $this->circuitNeutralStatuses, $this->fallbackStatuses, self::statuses($statuses), $this->retryableErrnos);
    }

    /** Replaces the cURL error numbers that are retry-eligible. TLS and malformed-request errors can never be added. */
    public function withRetryableTransportErrnos(int ...$errnos): self
    {
        foreach ($errnos as $errno) {
            if ($errno < 1 || $errno > 200) {
                throw new InvalidConfigurationException('cURL error numbers must be between 1 and 200.');
            }
            if (in_array($errno, self::TLS_ERRNOS, true) || in_array($errno, self::MALFORMED_ERRNOS, true)) {
                throw new InvalidConfigurationException('Certificate, TLS and malformed-request errors are terminal and cannot be made retryable.');
            }
        }

        return new self($this->clock, $this->retryableStatuses, $this->circuitFailureStatuses, $this->circuitNeutralStatuses, $this->fallbackStatuses, $this->retryAfterStatuses, array_values(array_unique($errnos)));
    }

    /**
     * The reason codes this classifier can mark retry-eligible, for the replay statement of an operation.
     *
     * @return list<string>
     */
    public function retryableOutcomeCodes(): array
    {
        return [
            ...array_map(static fn(int $status): string => 'http_status_' . $status, $this->retryableStatuses),
            ...array_map(static fn(int $errno): string => 'http_transport_errno_' . $errno, $this->retryableErrnos),
        ];
    }

    public function classify(Outcome $outcome, OperationMetadata $metadata): OutcomeClassification
    {
        if ($outcome->kind === OutcomeKind::Value) {
            $value = $outcome->value();
            if ($value instanceof ResponseInterface) {
                return $this->forResponse($value);
            }

            return $this->failure('http_exception');
        }

        $exception = $outcome->exception();
        if ($exception instanceof BadResponseException) {
            return $this->forResponse($exception->getResponse());
        }
        if ($exception instanceof TooManyRedirectsException) {
            return $this->failure('http_too_many_redirects');
        }
        if ($exception instanceof ConnectException || $exception instanceof RequestException) {
            $errno = $exception->getHandlerContext()['errno'] ?? null;
            if (is_int($errno)) {
                return $this->forErrno($errno);
            }
        }

        return $this->failure('http_exception');
    }

    private function forResponse(ResponseInterface $response): OutcomeClassification
    {
        $status = $response->getStatusCode();
        $code = 'http_status_' . $status;
        $isFailure = in_array($status, $this->circuitFailureStatuses, true);
        $circuit = match (true) {
            $isFailure => CircuitVerdict::Failure,
            in_array($status, $this->circuitNeutralStatuses, true) => CircuitVerdict::Neutral,
            default => CircuitVerdict::Success,
        };
        $retryAfter = null;
        if (in_array($status, $this->retryAfterStatuses, true)) {
            $retryAfter = RetryAfter::read($response->getHeader('Retry-After'), $this->clock->wallNow())->delay;
        }

        return new OutcomeClassification(
            in_array($status, $this->retryableStatuses, true) ? RetryEligibility::Eligible : RetryEligibility::Ineligible,
            $circuit,
            in_array($status, $this->fallbackStatuses, true) ? FallbackEligibility::Eligible : FallbackEligibility::Ineligible,
            $status < 400 ? Acceptability::Acceptable : Acceptability::Unacceptable,
            $code,
            $retryAfter,
        );
    }

    private function forErrno(int $errno): OutcomeClassification
    {
        $malformed = in_array($errno, self::MALFORMED_ERRNOS, true);

        return new OutcomeClassification(
            in_array($errno, $this->retryableErrnos, true) ? RetryEligibility::Eligible : RetryEligibility::Ineligible,
            $malformed ? CircuitVerdict::Neutral : CircuitVerdict::Failure,
            FallbackEligibility::Ineligible,
            Acceptability::Unacceptable,
            'http_transport_errno_' . $errno,
            null,
        );
    }

    private function failure(string $code): OutcomeClassification
    {
        return new OutcomeClassification(RetryEligibility::Ineligible, CircuitVerdict::Failure, FallbackEligibility::Ineligible, Acceptability::Unacceptable, $code, null);
    }

    /**
     * @param array<int> $statuses
     * @return list<int>
     */
    private static function statuses(array $statuses): array
    {
        foreach ($statuses as $status) {
            if ($status < 100 || $status > 599) {
                throw new InvalidConfigurationException('HTTP statuses must be between 100 and 599.');
            }
        }

        return array_values(array_unique($statuses));
    }
}
