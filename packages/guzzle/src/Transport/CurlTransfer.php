<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Transport;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Runtime\AttemptControls;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * One synchronous Guzzle transfer per attempt, with the Reqtide rules applied to the options:
 *
 *  - the transfer timeout is the smaller of the attempt timeout and the time left before the deadline (read from the runtime's AttemptControls), given as both
 *    `timeout` and `connect_timeout`; never `read_timeout`, which the cURL handlers ignore, and never zero,
 *    which would mean "wait forever";
 *  - `http_errors` is off and redirects are not followed, so a response is a value for the classifier
 *    and the client's own defaults cannot change what an outcome means;
 *  - a request with a known body size gets an empty `Expect` header, so cURL does not wait for `100 Continue`;
 *  - only cURL handlers are supported. A handler cannot be read from a client, so it is
 *    verified from the transfer statistics: the stream handler reports none, and a custom handler that never reports
 *    statistics is treated the same. {@see curl()} builds the client with the
 *    cURL handler and cannot go wrong; {@see forClient()} accepts any client and rejects an unsupported one after its
 *    first transfer, which has then already been sent, and before every later one.
 *
 * Timing of the first transfer is recorded because Guzzle's client initialisation sits outside the configured timeout
 *. One instance may be shared by operations of one process.
 *
 * @internal
 */
final class CurlTransfer
{
    private bool $unsupportedHandler = false;

    private int $transfers = 0;

    private ?float $firstTransferSeconds = null;

    private function __construct(private readonly ClientInterface $client) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['transfers' => $this->transfers];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('A transfer holds a client with its credentials and is not serializable.');
    }

    /**
     * A transfer on a client built here with the cURL easy handler.
     *
     * @param array<string, mixed> $clientDefaults Guzzle client options such as `headers`, `proxy` or `verify`; `handler` is not accepted
     */
    public static function curl(#[\SensitiveParameter] array $clientDefaults = []): self
    {
        if (array_key_exists('handler', $clientDefaults)) {
            throw new InvalidConfigurationException('The handler is fixed to the cURL easy handler; remove "handler" from the client defaults.');
        }

        return new self(new Client(['handler' => HandlerStack::create(new CurlHandler())] + $clientDefaults));
    }

    /** A transfer on a client you built. Its handler is verified from the transfer statistics, after the first transfer. */
    public static function forClient(ClientInterface $client): self
    {
        return new self($client);
    }

    /** Seconds the first transfer of this instance took, or null before it. */
    public function firstTransferSeconds(): ?float
    {
        return $this->firstTransferSeconds;
    }

    /** @return \Closure(RequestInterface, AttemptContext, ?AttemptControls, bool=): ResponseInterface */
    public function sender(): \Closure
    {
        return $this->send(...);
    }

    /**
     * @throws InvalidConfigurationException when the client's handler is not a cURL handler
     */
    public function send(RequestInterface $request, AttemptContext $context, ?AttemptControls $controls = null, bool $mayReuseConnection = false): ResponseInterface
    {
        if ($this->unsupportedHandler) {
            throw self::unsupported();
        }
        [$request, $options] = TransferOptions::forAttempt($request, $context, $controls, $mayReuseConnection);

        $started = hrtime(true);
        $withoutStats = false;
        $statsSeen = false;
        try {
            $response = $this->client->send($request, $options + [
                'on_stats' => static function (TransferStats $stats) use (&$withoutStats, &$statsSeen): void {
                    // The stream handler reports no statistics; the cURL handlers report dozens.
                    $statsSeen = true;
                    if ($stats->getHandlerStats() === []) {
                        $withoutStats = true;
                    }
                },
            ]);
            // A handler that returns a response without ever reporting statistics is not a cURL handler either.
            $withoutStats = $withoutStats || !$statsSeen;
        } finally {
            if ($this->transfers++ === 0) {
                $this->firstTransferSeconds = (hrtime(true) - $started) / 1e9;
            }
            if ($withoutStats) {
                $this->unsupportedHandler = true;
            }
        }
        if ($withoutStats) {
            throw self::unsupported();
        }

        return $response;
    }

    private static function unsupported(): InvalidConfigurationException
    {
        return new InvalidConfigurationException('The Guzzle client does not use a cURL handler; only CurlHandler and CurlMultiHandler are supported.');
    }
}
