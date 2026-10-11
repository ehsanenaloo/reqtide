<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Support;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/** A Guzzle client double that records the request and options of every send and answers 200, reporting statistics like a cURL handler. */
final class RecordingClient implements ClientInterface
{
    /** @var list<array{request: RequestInterface, options: array<string, mixed>}> */
    public array $sends = [];

    /** @param array<string, mixed> $options */
    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        $this->sends[] = ['request' => $request, 'options' => $options];
        $response = new Response(200);
        $onStats = $options['on_stats'] ?? null;
        if (is_callable($onStats)) {
            // Like a cURL handler: report statistics with handler data.
            $onStats(new TransferStats($request, $response, 0.001, null, ['total_time' => 0.001]));
        }

        return $response;
    }

    /** @param array<string, mixed> $options */
    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        throw new \LogicException('not used');
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, $uri, array $options = []): ResponseInterface
    {
        throw new \LogicException('not used');
    }

    /**
     * @param string|UriInterface $uri
     * @param array<string, mixed> $options
     */
    public function requestAsync(string $method, $uri, array $options = []): PromiseInterface
    {
        throw new \LogicException('not used');
    }

    public function getConfig(?string $option = null)
    {
        return null;
    }
}
