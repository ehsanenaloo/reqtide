<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Outcome;

use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\OperationMetadata;
use Enaxon\Reqtide\Value\Duration;

/**
 * Ready-made {@see OutcomeClassifier} driven by lists of exception classes.
 *
 * Build it once with {@see create()} and the fluent methods below, then hand it to the runtime environment. Every
 * method returns a new instance, so a base classifier can be reused safely.
 *
 *     $classifier = ExceptionClassifier::create()
 *         ->retryOn(TimeoutException::class, ConnectionException::class)
 *         ->ignoreForCircuit(ValidationException::class)
 *         ->fallbackOn(ConnectionException::class)
 *         ->retryAfterFrom(static fn(\Throwable $e): ?Duration => $e instanceof ThrottledException ? $e->wait() : null);
 *
 * Decision table
 * --------------
 * | Outcome                                  | retry     | circuit | fallback  | acceptability | reason code           |
 * |------------------------------------------|-----------|---------|-----------|---------------|-----------------------|
 * | value, no predicate or predicate false   | ineligible| success | ineligible| acceptable    | `value_accepted`      |
 * | value, predicate true                    | ineligible| failure | ineligible| unacceptable  | `value_unacceptable`  |
 * | exception matching retryOn()             | eligible  | failure*| per list  | unacceptable  | `exception_retryable` |
 * | exception matching ignoreForCircuit()    | per retry | neutral | per list  | unacceptable  | `exception_ignored`** |
 * | any other exception                      | ineligible| failure*| per list  | unacceptable  | `exception_failure`   |
 * | other exception, circuit list given      | ineligible| neutral | per list  | unacceptable  | `exception_ignored`   |
 *
 * `**` `exception_retryable` instead when the same class is also listed in retryOn().
 *
 * The last row is an exception that is not in the retryOn() list, not in the countAsCircuitFailure() list and not
 * ignored, once countAsCircuitFailure() has been called. It is circuit-neutral and reports `exception_ignored`.
 *
 * `*` With no explicit {@see countAsCircuitFailure()} list every exception that is not ignored is a circuit
 * failure. Once that list is given, only listed classes (and retryable ones) are failures; every other
 * exception is circuit-neutral, which neither opens nor heals the circuit.
 *
 * Conservative defaults, deliberately
 * -----------------------------------
 * - An exception nobody listed is NOT retryable and NOT fallback-eligible: repeating or masking an
 *   unexpected failure is a decision the caller must make explicitly. Polly (`Handle<T>()`) and resilience4j
 *   (`retryExceptions`) also act only on exceptions the caller names.
 * - An unlisted exception IS counted as a circuit failure, matching the resilience4j circuit-breaker default of
 *   recording every exception. A circuit that ignores unknown failures would stay closed against a dependency
 *   that fails in a way nobody anticipated. Use {@see ignoreForCircuit()} for caller mistakes such as
 *   validation errors, which say nothing about the dependency's health.
 * - A returned value is acceptable unless a predicate says otherwise. A value is never retried or replaced by a
 *   fallback by this classifier; write your own OutcomeClassifier when results must be retried.
 *
 * Matching is `instanceof`, so a listed parent class or interface covers its subclasses. A class that appears in
 * both ignoreForCircuit() and the failure list is ignored (ignore wins).
 *
 * The reason codes are registry members, so they can be listed in
 * {@see \Enaxon\Reqtide\Execution\ReplaySafetyContract::$retryableOutcomeCodes}; only `exception_retryable` is
 * ever retry-eligible.
 */
final readonly class ExceptionClassifier implements OutcomeClassifier
{
    /**
     * @param list<class-string<\Throwable>> $retryable
     * @param list<class-string<\Throwable>> $circuitFailures
     * @param list<class-string<\Throwable>> $circuitIgnored
     * @param list<class-string<\Throwable>> $fallbackEligible
     * @param (\Closure(\Throwable): ?Duration)|null $retryAfter
     * @param (\Closure(mixed): bool)|null $unacceptableValue
     */
    private function __construct(
        private array $retryable,
        private array $circuitFailures,
        private array $circuitIgnored,
        private array $fallbackEligible,
        private ?\Closure $retryAfter,
        private ?\Closure $unacceptableValue,
    ) {}

    /** No lists, no predicates: every value is accepted, every exception is a non-retryable circuit failure. */
    public static function create(): self
    {
        return new self([], [], [], [], null, null);
    }

    /**
     * Exceptions that may be retried (still subject to maxAttempts, the retry budget, the deadline and the
     * operation's replay-safety contract).
     *
     * @param class-string<\Throwable> ...$exceptionClasses
     * @throws InvalidConfigurationException when a name is not a Throwable class or interface
     */
    public function retryOn(string ...$exceptionClasses): self
    {
        return new self(
            self::merge($this->retryable, self::throwables('retryOn()', $exceptionClasses)),
            $this->circuitFailures,
            $this->circuitIgnored,
            $this->fallbackEligible,
            $this->retryAfter,
            $this->unacceptableValue,
        );
    }

    /**
     * Restricts circuit failures to these classes (plus retryable ones). Without this call every exception that is
     * not ignored is a failure. Other exceptions become circuit-neutral.
     *
     * @param class-string<\Throwable> ...$exceptionClasses
     * @throws InvalidConfigurationException when a name is not a Throwable class or interface
     */
    public function countAsCircuitFailure(string ...$exceptionClasses): self
    {
        return new self(
            $this->retryable,
            self::merge($this->circuitFailures, self::throwables('countAsCircuitFailure()', $exceptionClasses)),
            $this->circuitIgnored,
            $this->fallbackEligible,
            $this->retryAfter,
            $this->unacceptableValue,
        );
    }

    /**
     * Exceptions that say nothing about the dependency's health (for example a rejected argument). They are
     * circuit-neutral and win over every failure list.
     *
     * @param class-string<\Throwable> ...$exceptionClasses
     * @throws InvalidConfigurationException when a name is not a Throwable class or interface
     */
    public function ignoreForCircuit(string ...$exceptionClasses): self
    {
        return new self(
            $this->retryable,
            $this->circuitFailures,
            self::merge($this->circuitIgnored, self::throwables('ignoreForCircuit()', $exceptionClasses)),
            $this->fallbackEligible,
            $this->retryAfter,
            $this->unacceptableValue,
        );
    }

    /**
     * Exceptions for which the context's fallback (if any) may run. Pass `\Throwable::class` to allow every
     * exception; the default is none, because a fallback also hides programming errors.
     *
     * @param class-string<\Throwable> ...$exceptionClasses
     * @throws InvalidConfigurationException when a name is not a Throwable class or interface
     */
    public function fallbackOn(string ...$exceptionClasses): self
    {
        return new self(
            $this->retryable,
            $this->circuitFailures,
            $this->circuitIgnored,
            self::merge($this->fallbackEligible, self::throwables('fallbackOn()', $exceptionClasses)),
            $this->retryAfter,
            $this->unacceptableValue,
        );
    }

    /**
     * Reads a server-requested wait ("Retry-After") from an exception. Return null when there is none. The pipeline
     * treats the value as a lower bound for its own scheduling. The callable must be pure and quick; if it throws,
     * the operation ends with a classifier failure.
     *
     * @param callable(\Throwable): ?Duration $extractor
     */
    public function retryAfterFrom(callable $extractor): self
    {
        return new self(
            $this->retryable,
            $this->circuitFailures,
            $this->circuitIgnored,
            $this->fallbackEligible,
            $extractor(...),
            $this->unacceptableValue,
        );
    }

    /**
     * Marks returned values as failures when the predicate returns true (for example an error object that is
     * returned instead of thrown). Such a value counts against the circuit but is not retried and not replaced.
     *
     * @param callable(mixed): bool $predicate
     */
    public function unacceptableValuesWhen(callable $predicate): self
    {
        return new self(
            $this->retryable,
            $this->circuitFailures,
            $this->circuitIgnored,
            $this->fallbackEligible,
            $this->retryAfter,
            $predicate(...),
        );
    }

    public function classify(Outcome $outcome, OperationMetadata $metadata): OutcomeClassification
    {
        if ($outcome->kind === OutcomeKind::Value) {
            return $this->classifyValue($outcome->value());
        }

        $exception = $outcome->exception();
        $retryable = self::matches($exception, $this->retryable);
        $ignored = self::matches($exception, $this->circuitIgnored);
        $failure = !$ignored
            && ($this->circuitFailures === [] || $retryable || self::matches($exception, $this->circuitFailures));

        $code = match (true) {
            $retryable => ReasonCode::ExceptionRetryable,
            $failure => ReasonCode::ExceptionFailure,
            default => ReasonCode::ExceptionIgnored,
        };

        return new OutcomeClassification(
            $retryable ? RetryEligibility::Eligible : RetryEligibility::Ineligible,
            $failure ? CircuitVerdict::Failure : CircuitVerdict::Neutral,
            self::matches($exception, $this->fallbackEligible) ? FallbackEligibility::Eligible : FallbackEligibility::Ineligible,
            Acceptability::Unacceptable,
            $code->value,
            $this->retryAfter === null ? null : ($this->retryAfter)($exception),
        );
    }

    private function classifyValue(mixed $value): OutcomeClassification
    {
        $unacceptable = $this->unacceptableValue !== null && ($this->unacceptableValue)($value) === true;

        return new OutcomeClassification(
            RetryEligibility::Ineligible,
            $unacceptable ? CircuitVerdict::Failure : CircuitVerdict::Success,
            FallbackEligibility::Ineligible,
            $unacceptable ? Acceptability::Unacceptable : Acceptability::Acceptable,
            ($unacceptable ? ReasonCode::ValueUnacceptable : ReasonCode::ValueAccepted)->value,
            null,
        );
    }

    /** @param list<class-string<\Throwable>> $classes */
    private static function matches(\Throwable $exception, array $classes): bool
    {
        foreach ($classes as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, string> $names
     * @return list<class-string<\Throwable>>
     */
    private static function throwables(string $method, array $names): array
    {
        if ($names === []) {
            throw new InvalidConfigurationException($method . ' needs at least one exception class.');
        }
        $classes = [];
        foreach ($names as $name) {
            if ($name === '') {
                throw new InvalidConfigurationException($method . ' received an empty class name.');
            }
            $normalized = ltrim($name, '\\');
            if (!is_a($normalized, \Throwable::class, true)) {
                throw new InvalidConfigurationException(
                    $method . ' expects names of classes or interfaces that extend \\Throwable; "' . $normalized
                    . '" is not one (check the spelling and that the class is autoloadable).',
                );
            }
            /** @var class-string<\Throwable> $normalized */
            $classes[] = $normalized;
        }

        return $classes;
    }

    /**
     * @param list<class-string<\Throwable>> $existing
     * @param list<class-string<\Throwable>> $added
     * @return list<class-string<\Throwable>>
     */
    private static function merge(array $existing, array $added): array
    {
        return array_values(array_unique([...$existing, ...$added]));
    }
}
