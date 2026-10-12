<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\OperationMetadata;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\ReplayPreparation;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Guzzle\Operation\HttpAttemptOperation;
use Enaxon\Reqtide\Guzzle\Transport\GuzzleOperationDescriptor;
use Enaxon\Reqtide\Runtime\AttemptControls;
use Enaxon\Reqtide\Runtime\AttemptOperation;
use Enaxon\Reqtide\Time\Clock;
use Enaxon\Reqtide\Time\SystemClock;
use Enaxon\Reqtide\Value\Duration;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * One logical HTTP operation: an absolute request plus the explicit statement of whether and how it may be sent again.
 * Nothing is inferred: a header alone is never proof of safety.
 *
 *  - `read()` for GET and HEAD: the caller states that repeating the request has no side effect (RFC 9110 safe methods)
 *    for a stated time, and the request body, if any, must be replayable.
 *  - `idempotentMutation()` for a mutation the provider deduplicates by an identity (an idempotency key): the identity,
 *    its scope and the provider's duplicate handling are stated, and the identity is checked on every attempt.
 *  - `single()` for everything else: one attempt, never repeated.
 *
 * A replayable body is either a seekable stream that rewinds to position 0 (verified before every attempt) or a body
 * factory that returns a fresh stream of known size each time. A body that is neither is refused before dispatch.
 * With the default {@see BodyIntegrity::Verified} the content of that body is also compared by SHA-256 digest before every
 * attempt, so an identity never carries two payloads.
 */
final readonly class HttpOperation
{
    private const MUTATION_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** The default ceiling for a body whose content is verified (16 MiB). */
    public const DEFAULT_MAX_VERIFIED_BODY_BYTES = 16_777_216;

    /** @var ?\Closure(): StreamInterface */
    public ?\Closure $bodyFactory;

    /**
     * @param ?\Closure(): StreamInterface $bodyFactory
     */
    private function __construct(
        public RequestInterface $request,
        public GuzzleOperationDescriptor $descriptor,
        ?\Closure $bodyFactory,
        public ?string $identityHeader,
        public BodyIntegrity $bodyIntegrity = BodyIntegrity::Verified,
        public int $maxVerifiedBodyBytes = self::DEFAULT_MAX_VERIFIED_BODY_BYTES,
        #[\SensitiveParameter]
        private ?string $credentialKey = null,
    ) {
        if ($maxVerifiedBodyBytes < 0) {
            throw new InvalidConfigurationException('maxVerifiedBodyBytes must not be negative.');
        }
        $this->bodyFactory = $bodyFactory;
    }

    /** The key of the credential fingerprint is never part of a dump of the operation. */
    public function __debugInfo(): array
    {
        return [
            'request' => $this->request,
            'descriptor' => $this->descriptor,
            'bodyFactory' => $this->bodyFactory,
            'identityHeader' => $this->identityHeader,
            'bodyIntegrity' => $this->bodyIntegrity,
            'maxVerifiedBodyBytes' => $this->maxVerifiedBodyBytes,
        ];
    }

    /**
     * The same operation described for the client that sends it: the credential fingerprint then covers what the client
     * defaults `headers`, `auth` and `proxy` add or replace, which is what the server receives. {@see ReqtideClient} does
     * this itself; call it when you run the operation through your own pipeline and read {@see metadata()}.
     *
     * @throws InvalidConfigurationException when a Basic `auth` default would replace the header that carries the identity
     */
    public function forClient(GuzzleConfiguration $configuration): self
    {
        $defaults = $configuration->clientDefaults;
        $relevant = array_intersect_key($defaults, ['headers' => true, 'auth' => true, 'proxy' => true, 'cert' => true, 'ssl_key' => true]);
        if ($relevant === [] && $configuration->credentialHeaders === []) {
            return $this;
        }
        $auth = $defaults['auth'] ?? null;
        $basic = is_array($auth) && $auth !== [] && (!isset($auth[2]) || (is_string($auth[2]) && strtolower($auth[2]) === 'basic'));
        if ($basic && $this->identityHeader !== null && strcasecmp($this->identityHeader, 'Authorization') === 0) {
            throw new InvalidConfigurationException('The Basic auth client default replaces the Authorization header, which carries the identity of this operation.');
        }

        return new self(
            $this->request,
            $this->descriptor->resolvedFor($this->request, $defaults, $this->credentialKey, $configuration->credentialHeaders),
            $this->bodyFactory,
            $this->identityHeader,
            $this->bodyIntegrity,
            $this->maxVerifiedBodyBytes,
            $this->credentialKey,
        );
    }

    /**
     * A safe read (GET or HEAD) that may be repeated.
     *
     * @param Duration $validFor how long the statement holds, counted from now; it must be longer than the timeout given to send(), because the statement starts before the deadline (add a margin, for example one second); otherwise a retrying client refuses the operation with InvalidConfigurationException
     * @param ?\Closure(): StreamInterface $bodyFactory fresh body per attempt, for a request with a body that cannot be rewound
     * @param BodyIntegrity $bodyIntegrity whether the content of a replayed body is verified (the default) or the caller asserts it stays the same
     * @param int $maxVerifiedBodyBytes the longest body whose content is verified; a longer body is refused unless the integrity is `CallerAsserted`
     * @param list<string> $retryableOutcomeCodes classifier reason codes that may be retried (the HTTP classifier names them, for example `HttpOutcomeClassifier::starter()->retryableOutcomeCodes()`). The default is empty: under a retrying pipeline no outcome is then retried and the pipeline only reports the reason `outcome_not_retryable`
     * @throws InvalidConfigurationException
     */
    public static function read(
        RequestInterface $request,
        string $operationGroup,
        Duration $validFor,
        array $retryableOutcomeCodes = [],
        ?\Closure $bodyFactory = null,
        ?Clock $clock = null,
        #[\SensitiveParameter]
        ?string $credentialKey = null,
        BodyIntegrity $bodyIntegrity = BodyIntegrity::Verified,
        int $maxVerifiedBodyBytes = self::DEFAULT_MAX_VERIFIED_BODY_BYTES,
    ): self {
        $method = strtoupper($request->getMethod());
        if ($method !== 'GET' && $method !== 'HEAD') {
            throw new InvalidConfigurationException('read() accepts GET and HEAD only; use idempotentMutation() or single() for other methods.');
        }
        if ($request->getMethod() !== $method) {
            $request = $request->withMethod($method);
        }
        [$from, $until] = self::window($validFor, $clock);
        $contract = new ReplaySafetyContract(
            'http-safe-method',
            'http-' . strtolower($method),
            'http-method-semantics',
            'RFC 9110 safe method: repeating the request has no intended side effect',
            $from,
            $until,
            $validFor,
            $bodyFactory === null ? ReplayPreparation::VerifiedRewind : ReplayPreparation::BodyFactory,
            $retryableOutcomeCodes,
        );

        return new self($request, GuzzleOperationDescriptor::fromRequest($request, $operationGroup, OperationSafety::replayableRead($contract), $credentialKey), $bodyFactory, null, $bodyIntegrity, $maxVerifiedBodyBytes, $credentialKey);
    }

    /**
     * A mutation (POST, PUT, PATCH, DELETE) that the provider deduplicates by `$identityValue`.
     *
     * @param string $identityValue the provider-enforced identity, for example the idempotency key; kept the same across attempts
     * @param string $identityScope what the identity is unique within (for example "account and endpoint")
     * @param string $duplicateHandling how the provider treats a repeat (for example "returns the stored response for 24 h")
     * @param Duration $validFor how long the statement holds, counted from now; it must be longer than the timeout given to send(), because the statement starts before the deadline (add a margin, for example one second); otherwise a retrying client refuses the operation with InvalidConfigurationException
     * @param ?Duration $retention how long the provider keeps the identity; defaults to `$validFor`
     * @param ?string $identityHeader header that carries the identity; checked on every attempt. Null when the identity travels elsewhere (then the caller guarantees it is the same)
     * @param ?\Closure(): StreamInterface $bodyFactory fresh body per attempt, for a body that cannot be rewound
     * @param BodyIntegrity $bodyIntegrity whether the content of a replayed body is verified (the default) or the caller asserts it stays the same
     * @param int $maxVerifiedBodyBytes the longest body whose content is verified; a longer body is refused unless the integrity is `CallerAsserted`
     * @param list<string> $retryableOutcomeCodes classifier reason codes that may be retried; the default is empty, so a retrying pipeline never retries (see {@see read()})
     * @throws InvalidConfigurationException
     */
    public static function idempotentMutation(
        RequestInterface $request,
        string $operationGroup,
        string $identityValue,
        string $identityScope,
        string $duplicateHandling,
        Duration $validFor,
        ?Duration $retention = null,
        ?string $identityHeader = 'Idempotency-Key',
        array $retryableOutcomeCodes = [],
        ?\Closure $bodyFactory = null,
        ?Clock $clock = null,
        #[\SensitiveParameter]
        ?string $credentialKey = null,
        BodyIntegrity $bodyIntegrity = BodyIntegrity::Verified,
        int $maxVerifiedBodyBytes = self::DEFAULT_MAX_VERIFIED_BODY_BYTES,
    ): self {
        $method = strtoupper($request->getMethod());
        if (!in_array($method, self::MUTATION_METHODS, true)) {
            throw new InvalidConfigurationException('idempotentMutation() accepts POST, PUT, PATCH and DELETE; use read() for GET and HEAD.');
        }
        if ($request->getMethod() !== $method) {
            $request = $request->withMethod($method);
        }
        if ($identityHeader !== null && $identityValue !== trim($identityValue, " \t")) {
            throw new InvalidConfigurationException('The identity value must not start or end with a space or a tab: Guzzle trims header values, so the stated identity could never match the header.');
        }
        if ($identityHeader !== null && preg_match('~^[A-Za-z0-9-]{1,64}\z~', $identityHeader) !== 1) {
            throw new InvalidConfigurationException('The identity header must be a header name of at most 64 letters, digits and hyphens.');
        }
        [$from, $until] = self::window($validFor, $clock);
        $contract = new ReplaySafetyContract(
            'provider-idempotent-mutation',
            $identityValue,
            $identityScope,
            $duplicateHandling,
            $from,
            $until,
            $retention ?? $validFor,
            $bodyFactory === null ? ReplayPreparation::VerifiedRewind : ReplayPreparation::BodyFactory,
            $retryableOutcomeCodes,
        );

        return new self($request, GuzzleOperationDescriptor::fromRequest($request, $operationGroup, OperationSafety::providerIdempotentMutation($contract), $credentialKey), $bodyFactory, $identityHeader, $bodyIntegrity, $maxVerifiedBodyBytes, $credentialKey);
    }

    /**
     * An operation that is sent once and never repeated. Its body may be a stream that cannot be rewound.
     *
     * @throws InvalidConfigurationException
     */
    public static function single(RequestInterface $request, string $operationGroup, #[\SensitiveParameter] ?string $credentialKey = null): self
    {
        return new self($request, GuzzleOperationDescriptor::fromRequest($request, $operationGroup, OperationSafety::unsafe(), $credentialKey), null, null, BodyIntegrity::CallerAsserted, 0, $credentialKey);
    }

    /** The bounded facts for the pipeline context: safety and the descriptor attributes. */
    public function metadata(): OperationMetadata
    {
        return $this->descriptor->toCoreMetadata();
    }

    /**
     * The attempt operation for the Core pipeline. `$send` performs one transfer of the request it is given for the attempt it is given (see CurlTransfer::sender()).
     *
     * @internal
     * @param \Closure(RequestInterface, AttemptContext, ?AttemptControls, bool): ResponseInterface $send
     * @return AttemptOperation<ResponseInterface>
     */
    public function attemptOperation(\Closure $send): AttemptOperation
    {
        return new HttpAttemptOperation($this, $send);
    }

    /**
     * The attempt operation for the promise runtime. `$start` begins one transfer and returns its pending promise (see
     * CurlMultiTransfer::starter()); the runtime drives it.
     *
     * @internal
     * @param \Closure(RequestInterface, AttemptContext, ?AttemptControls, bool): PromiseInterface $start
     * @return AttemptOperation<ResponseInterface>
     */
    public function asyncAttemptOperation(\Closure $start): AttemptOperation
    {
        $notSynchronous = static function (): never {
            throw new \LogicException('This operation was built for the promise runtime.');
        };

        return new HttpAttemptOperation($this, $notSynchronous, $start);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private static function window(Duration $validFor, ?Clock $clock): array
    {
        if (!$validFor->isPositive()) {
            throw new InvalidConfigurationException('validFor must be positive; it must cover the operation deadline.');
        }
        $now = ($clock ?? new SystemClock())->wallNow()->setTimezone(new \DateTimeZone('UTC'));

        return [$now, $now->modify('+' . $validFor->ceilMicroseconds() . ' microseconds')];
    }
}
