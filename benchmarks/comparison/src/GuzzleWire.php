<?php

declare(strict_types=1);

namespace Comparison;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Scripted, recording Guzzle transport built on GuzzleHttp\Handler\MockHandler.
 *
 * MockHandler honours the 'delay' request option with a real \usleep() (fully qualified, so it cannot be
 * shadowed). To keep time virtual the wrapper below consumes the option itself (advances VirtualTime)
 * and removes it before calling MockHandler, which then serves the scripted response.
 */
final class GuzzleWire
{
    /** @var list<array{t:float,method:string,status:int|string}> */
    public array $log = [];

    private int $i = 0;

    /** @param list<array{0:int|string,1?:array<string,string>}> $script last entry repeats; status 'err' = transport failure */
    public function __construct(private readonly array $script)
    {
    }

    public function handler(): callable
    {
        $items = [];
        $step = fn (): callable => function (RequestInterface $req, array $opts): mixed {
            $s = $this->script[min($this->i, \count($this->script) - 1)];
            $this->i++;
            $this->log[] = ['t' => VirtualTime::elapsed(), 'method' => $req->getMethod(), 'status' => $s[0]];
            if ($s[0] === 'err') {
                return Create::rejectionFor(new RequestException('connection reset after write', $req));
            }

            return Create::promiseFor(new Response((int) $s[0], $s[1] ?? []));
        };
        $closure = $step();
        for ($n = 0; $n < 5000; $n++) {
            $items[] = $closure;
        }
        $mock = new MockHandler($items);

        return static function (RequestInterface $req, array $opts) use ($mock): mixed {
            if (isset($opts['delay']) && is_numeric($opts['delay'])) {
                VirtualTime::advance($opts['delay'] / 1000);
                unset($opts['delay']);
            }

            return $mock($req, $opts);
        };
    }

    /** @return float[] */
    public function times(): array
    {
        return array_map(static fn (array $l): float => $l['t'], $this->log);
    }

    public function count(): int
    {
        return \count($this->log);
    }

    /** Execute one request through a composed handler; returns status or exception class. */
    public static function run(callable $composedHandler, string $method = 'GET', array $options = []): string
    {
        $client = new Client(['handler' => $composedHandler, 'http_errors' => false]);
        try {
            return (string) $client->request($method, 'https://upstream.test/x', $options)->getStatusCode();
        } catch (\Throwable $e) {
            return $e::class;
        }
    }
}
