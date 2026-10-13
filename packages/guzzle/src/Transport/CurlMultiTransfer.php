<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Transport;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Runtime\AttemptControls;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Psr\Http\Message\RequestInterface;

/**
 * Asynchronous Guzzle transfers on a cURL multi handler that this object owns and drives.
 *
 * Guzzle has no event loop: a promise from the multi handler does not make progress, and an unstarted one
 * starts no transfer, until somebody calls `tick()` and runs the promise task queue. {@see drive()} does exactly that,
 * once. The handler is built with a small `select_timeout` (the default blocks for up to one second per
 * tick), so one `drive()` call blocks for at most that long; this bounds how late a deadline or a cancellation request
 * can be noticed between ticks.
 *
 * The options of each transfer come from {@see TransferOptions}. Only the multi handler is used, so no handler check is
 * needed.
 *
 * @internal
 */
final class CurlMultiTransfer
{
    /** Seconds one tick may block. */
    public const SELECT_TIMEOUT = 0.01;

    private readonly CurlMultiHandler $handler;

    private readonly Client $client;

    /** @param array<string, mixed> $clientDefaults Guzzle client options such as `headers`, `proxy` or `verify`; `handler` is not accepted */
    public function __construct(#[\SensitiveParameter] array $clientDefaults = [])
    {
        if (array_key_exists('handler', $clientDefaults)) {
            throw new InvalidConfigurationException('The handler is fixed to an owned cURL multi handler; remove "handler" from the client defaults.');
        }
        $this->handler = new CurlMultiHandler(['select_timeout' => self::SELECT_TIMEOUT]);
        $this->client = new Client(['handler' => HandlerStack::create($this->handler)] + $clientDefaults);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('A transfer holds a client with its credentials and is not serializable.');
    }

    /**
     * Prepares one transfer and returns its promise. Nothing is sent until {@see drive()} is called.
     */
    public function start(RequestInterface $request, AttemptContext $context, ?AttemptControls $controls = null, bool $mayReuseConnection = false): PromiseInterface
    {
        [$request, $options] = TransferOptions::forAttempt($request, $context, $controls, $mayReuseConnection);

        return $this->client->sendAsync($request, $options);
    }

    /** @return \Closure(RequestInterface, AttemptContext, ?AttemptControls, bool=): PromiseInterface */
    public function starter(): \Closure
    {
        return $this->start(...);
    }

    /**
     * One step of progress: a tick of the multi handler (blocks at most {@see SELECT_TIMEOUT} seconds while transfers
     * are active, returns at once otherwise) followed by running the promise task queue.
     */
    public function drive(): void
    {
        $this->handler->tick();
        PromiseUtils::queue()->run();
    }
}
