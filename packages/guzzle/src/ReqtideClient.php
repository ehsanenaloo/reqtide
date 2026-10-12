<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle;

use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Guzzle\Operation\OperationPromise;
use Enaxon\Reqtide\Guzzle\Runtime\GuzzlePromiseRuntimeAdapter;
use Enaxon\Reqtide\Guzzle\Runtime\GuzzleSyncRuntimeAdapter;
use Enaxon\Reqtide\Guzzle\Transport\CurlMultiTransfer;
use Enaxon\Reqtide\Guzzle\Transport\CurlTransfer;
use Enaxon\Reqtide\Pipeline\CompiledPipeline;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Pipeline\PipelineExplanation;
use Enaxon\Reqtide\Pipeline\RuntimeEnvironment;
use Enaxon\Reqtide\Runtime\AttemptOperation;
use Enaxon\Reqtide\State\CircuitBackend;
use Enaxon\Reqtide\State\ConcurrencyBackend;
use Enaxon\Reqtide\State\CooldownBackend;
use Enaxon\Reqtide\State\RateLimitBackend;
use Enaxon\Reqtide\State\RetryBudgetBackend;
use Enaxon\Reqtide\Telemetry\EventSink;
use Enaxon\Reqtide\Telemetry\NullEventSink;
use Enaxon\Reqtide\Time\BoundedBlockingScheduler;
use Enaxon\Reqtide\Time\Cancellation;
use Enaxon\Reqtide\Time\SecureRandomSource;
use Enaxon\Reqtide\Time\SystemClock;
use Enaxon\Reqtide\Value\Duration;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * The entry point for HTTP calls with Reqtide's policies. It owns its cURL handlers and runs each logical
 * operation, with all its attempts, through one compiled Core pipeline:
 *
 *     $client = ReqtideClient::inMemory(
 *         PipelineBuilder::profile('billing', 'v1')
 *             ->partition('shop', 'prod', 'billing-api')
 *             ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::TransportEnforced)
 *             ->retry(3, BackoffMode::Exponential, Duration::milliseconds(100), Duration::seconds(1), JitterMode::Full)
 *             ->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(12)),
 *         new GuzzleConfiguration(baseUri: 'https://api.example.com'),
 *     );
 *     $response = $client->send(HttpOperation::read($request, 'billing.read', Duration::seconds(30)), Duration::seconds(10));
 *
 * Rules:
 *  - Each call takes an {@see HttpOperation}, which states whether the request may be sent again. Nothing is guessed from
 *    the method or from a header.
 *  - The attempt timeout is enforced by the transport (the cURL timeouts), whichever requirement the pipeline names.
 *  - An operation built with `HttpOperation::single()` may only run in a pipeline whose retry allows one attempt; Core
 *    refuses an unsafe operation under a retry of more than one attempt.
 *  - `send()` returns the response or throws the failure of the operation. {@see sendDetailed()} returns the completion
 *    with the summary instead. {@see sendAsync()} returns a promise that settles only after the whole operation has ended.
 *  - The promise is lazy: Guzzle has no event loop, so the operation runs when the promise is waited for, one operation at
 *    a time. The synchronous and the promise runtime share the same policy state.
 *  - A 4xx or 5xx status is a response, never an exception. A transport failure is the original Guzzle exception when no
 *    policy ends the operation first.
 *  - Redirects are not followed. {@see inMemory()} keeps policy state in this process; {@see withBackends()} takes shared backends.
 */
final class ReqtideClient
{
    private function __construct(
        private readonly CompiledPipeline $sync,
        private readonly CompiledPipeline $async,
        private readonly CurlTransfer $transfer,
        private readonly CurlMultiTransfer $multi,
        private readonly GuzzleConfiguration $configuration,
    ) {}

    /**
     * A client with process-local policy state.
     *
     * @throws InvalidConfigurationException when the pipeline is incomplete or conflicting
     * @throws \Enaxon\Reqtide\Exception\CapabilityMismatchException when the pipeline needs something the cURL runtimes do not offer
     */
    public static function inMemory(PipelineBuilder $pipeline, ?GuzzleConfiguration $configuration = null, ?EventSink $events = null): self
    {
        $configuration ??= new GuzzleConfiguration();
        $base = GuzzleSyncRuntimeAdapter::inMemoryEnvironment($pipeline->definition(), $configuration->classifier, $events);

        return self::fromEnvironment($pipeline, $configuration, $base);
    }

    /**
     * A client whose policy state lives in the backends you give, for example the Redis backends of
     * `enaxon/reqtide-redis`, so that several processes obey one rate limit, one circuit and one cooldown. Pass a backend
     * for every policy the pipeline configures; the clock of the decisions is the backend's own. The synchronous and the
     * promise runtime share the backends.
     *
     * @throws InvalidConfigurationException when the pipeline is incomplete or conflicting
     * @throws \Enaxon\Reqtide\Exception\CapabilityMismatchException when the pipeline needs something the cURL runtimes do not offer
     */
    public static function withBackends(
        PipelineBuilder $pipeline,
        ?RateLimitBackend $rateLimit = null,
        ?CircuitBackend $circuit = null,
        ?ConcurrencyBackend $concurrency = null,
        ?RetryBudgetBackend $retryBudget = null,
        ?CooldownBackend $cooldown = null,
        ?GuzzleConfiguration $configuration = null,
        ?EventSink $events = null,
    ): self {
        $configuration ??= new GuzzleConfiguration();
        $clock = new SystemClock();
        $base = new RuntimeEnvironment(
            $clock,
            new BoundedBlockingScheduler($clock),
            new SecureRandomSource(),
            $configuration->classifier,
            new GuzzleSyncRuntimeAdapter(),
            $events ?? new NullEventSink(),
            $rateLimit,
            $circuit,
            $concurrency,
            $retryBudget,
            $cooldown,
        );

        return self::fromEnvironment($pipeline, $configuration, $base);
    }

    private static function fromEnvironment(PipelineBuilder $pipeline, GuzzleConfiguration $configuration, RuntimeEnvironment $base): self
    {
        $multi = new CurlMultiTransfer($configuration->clientDefaults);
        $asyncEnvironment = new RuntimeEnvironment(
            $base->clock,
            new Runtime\QueueDrivenDelayScheduler($base->clock, $multi->drive(...)),
            $base->random,
            $base->classifier,
            new GuzzlePromiseRuntimeAdapter($multi),
            $base->events,
            $base->rateLimit,
            $base->circuit,
            $base->concurrency,
            $base->retryBudget,
            $base->cooldown,
        );

        return new self(
            $pipeline->compile($base),
            $pipeline->compile($asyncEnvironment),
            CurlTransfer::curl($configuration->clientDefaults),
            $multi,
            $configuration,
        );
    }

    /**
     * Resolves a reference against the base URI of the configuration (RFC 3986). An absolute reference, or one that starts
     * with `//`, replaces the base host, and `orders` against `https://h/v1` gives `/orders`: the base URI is not a security
     * boundary. Build the request of an {@see HttpOperation} with the result, because the operation needs an absolute URI.
     *
     * @throws InvalidConfigurationException when the reference is malformed or the result is not an absolute http or https URI
     */
    public function uri(UriInterface|string $reference): UriInterface
    {
        try {
            $reference = is_string($reference) ? new \GuzzleHttp\Psr7\Uri($reference) : $reference;
            $base = $this->configuration->baseUri;
            $resolved = $base === null ? $reference : UriResolver::resolve($base, $reference);
        } catch (\InvalidArgumentException $malformed) {
            throw new InvalidConfigurationException('The request URI is malformed.', 0, $malformed);
        }
        if (!in_array($resolved->getScheme(), ['http', 'https'], true) || $resolved->getHost() === '') {
            throw new InvalidConfigurationException('The request URI must be absolute (http or https); configure a base URI or pass an absolute URI.');
        }

        return $resolved;
    }

    /**
     * Runs the operation and returns its response.
     *
     * @param Duration $timeout the whole operation: every attempt and every wait between attempts
     * @return ResponseInterface
     * @throws \Throwable the failure of the operation (a policy exception or the original transport exception)
     */
    public function send(HttpOperation $operation, Duration $timeout, ?Cancellation $cancellation = null): ResponseInterface
    {
        $response = $this->sync->executeOperation($this->syncOperation($operation), $this->context($this->sync, $operation, $timeout, $cancellation));
        \assert($response instanceof ResponseInterface);

        return $response;
    }

    /**
     * Runs the operation and returns the completion: the response or the failure, with the summary of the attempts and
     * policy decisions.
     *
     * @return ExecutionCompletion<ResponseInterface>
     */
    public function sendDetailed(HttpOperation $operation, Duration $timeout, ?Cancellation $cancellation = null): ExecutionCompletion
    {
        return $this->sync->executeOperationDetailed($this->syncOperation($operation), $this->context($this->sync, $operation, $timeout, $cancellation));
    }

    /**
     * A lazy promise of the whole operation. It is fulfilled with the response or rejected with the failure only after
     * the operation has ended, finalization included. Waiting for it runs the operation (`$promise->wait()`).
     */
    public function sendAsync(HttpOperation $operation, Duration $timeout, ?Cancellation $cancellation = null): PromiseInterface
    {
        return OperationPromise::lazy(function () use ($operation, $timeout, $cancellation): mixed {
            return $this->async->executeOperation(
                $operation->asyncAttemptOperation($this->multi->starter()),
                $this->context($this->async, $operation, $timeout, $cancellation),
            );
        });
    }

    /** Describes the compiled pipeline of the synchronous (or, with true, the promise) runtime. It performs no transfer and changes no state. */
    public function explain(bool $async = false): PipelineExplanation
    {
        return ($async ? $this->async : $this->sync)->explain();
    }

    /** @return AttemptOperation<ResponseInterface> */
    private function syncOperation(HttpOperation $operation): AttemptOperation
    {
        return $operation->attemptOperation($this->transfer->sender());
    }

    /**
     * @return \Enaxon\Reqtide\Execution\ExecutionContext<ResponseInterface>|\Enaxon\Reqtide\Execution\ExecutionContext<never>
     */
    private function context(CompiledPipeline $pipeline, HttpOperation $operation, Duration $timeout, ?Cancellation $cancellation): \Enaxon\Reqtide\Execution\ExecutionContext
    {
        $metadata = $operation->forClient($this->configuration)->metadata();

        return $pipeline->newContext($timeout, $metadata->safety, null, $cancellation, $metadata->attributes);
    }
}
