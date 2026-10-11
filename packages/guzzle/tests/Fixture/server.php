<?php

declare(strict_types=1);

/**
 * Controlled HTTP/1.1 fixture server for the Guzzle package tests.
 *
 *   php packages/guzzle/tests/Fixture/server.php [--host=127.0.0.1] [--port=0] [--tls=DIR]
 *
 * One process, one event loop, no dependencies. It prints "LISTENING host:port" on the first line of standard output once
 * it accepts connections. Every request is recorded at the server, so a test can count real dispatches independently of
 * what the client reports (a real dispatch spy). Every response closes the connection, except those of /keepalive.
 *
 * Control endpoints (not recorded): GET /__log returns the recorded requests as JSON, POST /__reset clears them.
 *
 * Behaviors, selected by path:
 *   /status/{code}?ra=...&ra=...&body=...  status with the given reason-less body; each ra is one raw Retry-After line
 *   /echo                                  200 with JSON {length, sha1} of the received body
 *   /no-continue                           like /echo, but never sends "100 Continue" for Expect: 100-continue
 *   /slow?ms=N                             200 after N milliseconds
 *   /drip?chunks=C&size=B&interval_ms=I    headers at once, then C chunks of B bytes, I ms apart
 *   /hang-after-headers                    headers promising 100 body bytes, then silence
 *   /never-answer                          reads the request and never answers
 *   /keepalive                             200 that keeps the connection open for the next request (HTTP/1.1 keep-alive)
 *   /close-no-reply                        closes the connection without any reply
 *   /reset-connection                      aborts the connection with a TCP reset (plain connections only, needs ext-sockets)
 *   /redirect?to=/echo&code=302            redirect response
 *
 * With --tls=DIR the server speaks TLS using DIR/server.crt and DIR/server.key (written by tools/docker/make-test-ca.php).
 * A client that fails the handshake never reaches the request log. Keep-alive is offered only by /keepalive, on plain connections.
 */

final class FixtureConnection
{
    public string $buffer = '';

    public ?int $headerEnd = null;

    /** @var array{method: string, target: string, headers: array<string, list<string>>}|null */
    public ?array $head = null;

    public bool $continueSent = false;

    public bool $answered = false;

    /**
     * @param resource $stream
     */
    public function __construct(public $stream, public int $id) {}
}

final class FixtureServer
{
    /** @var array<int, FixtureConnection> */
    private array $connections = [];

    /** @var list<array{due: float, run: \Closure}> */
    private array $timers = [];

    /** @var list<array<string, mixed>> */
    private array $log = [];

    private int $sequence = 0;

    private int $nextConnection = 1;

    private float $startedAt;

    /**
     * @param resource $server
     */
    public function __construct(private $server, private readonly bool $tls = false)
    {
        $this->startedAt = microtime(true);
    }

    public function run(): never
    {
        while (true) {
            $read = [$this->server];
            foreach ($this->connections as $connection) {
                $read[] = $connection->stream;
            }
            $write = null;
            $except = null;
            $timeout = $this->nextTimeout();
            $ready = @stream_select($read, $write, $except, (int) floor($timeout), (int) (($timeout - floor($timeout)) * 1_000_000));
            $this->fireTimers();
            if ($ready === false || $ready === 0) {
                continue;
            }
            foreach ($read as $stream) {
                if ($stream === $this->server) {
                    $this->accept();
                    continue;
                }
                $this->readFrom($stream);
            }
        }
    }

    private function nextTimeout(): float
    {
        $now = microtime(true);
        $timeout = 1.0;
        foreach ($this->timers as $timer) {
            $timeout = min($timeout, max(0.0, $timer['due'] - $now));
        }

        return $timeout;
    }

    private function fireTimers(): void
    {
        $now = microtime(true);
        $due = [];
        $rest = [];
        foreach ($this->timers as $timer) {
            if ($timer['due'] <= $now) {
                $due[] = $timer;
            } else {
                $rest[] = $timer;
            }
        }
        $this->timers = $rest;
        foreach ($due as $timer) {
            ($timer['run'])();
        }
    }

    private function after(float $seconds, \Closure $run): void
    {
        $this->timers[] = ['due' => microtime(true) + $seconds, 'run' => $run];
    }

    private function accept(): void
    {
        $stream = @stream_socket_accept($this->server, $this->tls ? 2 : 0);
        if ($stream === false) {
            return;
        }
        stream_set_blocking($stream, false);
        $connection = new FixtureConnection($stream, $this->nextConnection++);
        $this->connections[(int) $stream] = $connection;
    }

    /**
     * @param resource $stream
     */
    private function readFrom($stream): void
    {
        $connection = $this->connections[(int) $stream] ?? null;
        if ($connection === null) {
            return;
        }
        do {
            $chunk = fread($stream, 65536);
            if ($chunk === false || ($chunk === '' && feof($stream))) {
                $this->close($connection);

                return;
            }
            if ($connection->answered) {
                return;
            }
            $connection->buffer .= $chunk;
            $this->advance($connection);
        } while ($chunk !== '' && isset($this->connections[(int) $stream]) && !$connection->answered);
    }

    private function advance(FixtureConnection $connection): void
    {
        if ($connection->head === null) {
            $end = strpos($connection->buffer, "\r\n\r\n");
            if ($end === false) {
                return;
            }
            $connection->headerEnd = $end + 4;
            $connection->head = $this->parseHead(substr($connection->buffer, 0, $end));
        }
        $head = $connection->head;
        $expect = strtolower(implode(',', $head['headers']['expect'] ?? []));
        if (!$connection->continueSent && str_contains($expect, '100-continue') && !str_starts_with($head['target'], '/no-continue')) {
            $connection->continueSent = true;
            $this->write($connection, "HTTP/1.1 100 Continue\r\n\r\n");
        }

        $body = substr($connection->buffer, (int) $connection->headerEnd);
        $transfer = strtolower(implode(',', $head['headers']['transfer-encoding'] ?? []));
        if (str_contains($transfer, 'chunked')) {
            $decoded = $this->decodeChunked($body);
            if ($decoded === null) {
                return;
            }
            $body = $decoded;
        } else {
            $length = (int) ($head['headers']['content-length'][0] ?? 0);
            if (strlen($body) < $length) {
                return;
            }
            $body = substr($body, 0, $length);
        }

        $connection->answered = true;
        $this->dispatch($connection, $head, $body, str_contains($transfer, 'chunked'));
    }

    /**
     * @return array{method: string, target: string, headers: array<string, list<string>>}
     */
    private function parseHead(string $text): array
    {
        $lines = explode("\r\n", $text);
        $parts = explode(' ', array_shift($lines), 3);
        $headers = [];
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $headers[strtolower(substr($line, 0, $colon))][] = trim(substr($line, $colon + 1));
        }

        return ['method' => $parts[0], 'target' => $parts[1] ?? '/', 'headers' => $headers];
    }

    /** The decoded body once the terminating chunk has arrived, or null while the body is incomplete. */
    private function decodeChunked(string $raw): ?string
    {
        $decoded = '';
        $offset = 0;
        while (true) {
            $lineEnd = strpos($raw, "\r\n", $offset);
            if ($lineEnd === false) {
                return null;
            }
            $size = (int) hexdec(trim(explode(';', substr($raw, $offset, $lineEnd - $offset))[0]));
            $offset = $lineEnd + 2;
            if ($size === 0) {
                return str_contains(substr($raw, $offset), "\r\n") ? $decoded : null;
            }
            if (strlen($raw) < $offset + $size + 2) {
                return null;
            }
            $decoded .= substr($raw, $offset, $size);
            $offset += $size + 2;
        }
    }

    /**
     * @param array{method: string, target: string, headers: array<string, list<string>>} $head
     */
    private function dispatch(FixtureConnection $connection, array $head, string $body, bool $chunked): void
    {
        $target = $head['target'];
        $path = (string) parse_url($target, PHP_URL_PATH);
        $query = $this->query((string) parse_url($target, PHP_URL_QUERY));

        if ($path === '/__log') {
            $this->respond($connection, 200, ['Content-Type: application/json'], json_encode($this->log, JSON_THROW_ON_ERROR));

            return;
        }
        if ($path === '/__reset') {
            $this->log = [];
            $this->sequence = 0;
            $this->respond($connection, 204, [], '');

            return;
        }

        $this->log[] = [
            'seq' => ++$this->sequence,
            'connection' => $connection->id,
            'method' => $head['method'],
            'target' => $target,
            'headers' => $head['headers'],
            'bodyLength' => strlen($body),
            'bodySha1' => sha1($body),
            'bodyPreview' => base64_encode(substr($body, 0, 256)),
            'chunked' => $chunked,
            'expectContinueSent' => $connection->continueSent,
            'atMs' => (int) round((microtime(true) - $this->startedAt) * 1000),
        ];

        if (preg_match('~^/status/([0-9]{3})(?:/[^?]*)?$~', $path, $m) === 1) {
            $headers = [];
            foreach ($query['ra'] ?? [] as $value) {
                $headers[] = 'Retry-After: ' . $value;
            }
            $this->respond($connection, (int) $m[1], $headers, $query['body'][0] ?? '');

            return;
        }

        switch ($path) {
            case '/echo':
            case '/no-continue':
                $this->respond($connection, 200, ['Content-Type: application/json'], json_encode(['length' => strlen($body), 'sha1' => sha1($body)], JSON_THROW_ON_ERROR));

                return;
            case '/keepalive':
                $this->respond($connection, 200, [], 'alive', true);

                return;
            case '/slow':
                $this->after(((int) ($query['ms'][0] ?? 0)) / 1000, fn() => $this->respond($connection, 200, [], 'slow'));

                return;
            case '/drip':
                $this->drip($connection, max(1, (int) ($query['chunks'][0] ?? 5)), max(1, (int) ($query['size'][0] ?? 1)), (int) ($query['interval_ms'][0] ?? 100));

                return;
            case '/hang-after-headers':
                $this->write($connection, "HTTP/1.1 200 OK\r\nContent-Length: 100\r\nConnection: close\r\n\r\n");

                return;
            case '/never-answer':
                return;
            case '/close-no-reply':
                $this->close($connection);

                return;
            case '/reset-connection':
                $this->reset($connection);

                return;
            case '/redirect':
                $this->respond($connection, (int) ($query['code'][0] ?? 302), ['Location: ' . ($query['to'][0] ?? '/echo')], '');

                return;
        }

        $this->respond($connection, 404, [], 'not found');
    }

    private function drip(FixtureConnection $connection, int $chunks, int $size, int $intervalMs): void
    {
        $this->write($connection, 'HTTP/1.1 200 OK' . "\r\n" . 'Content-Length: ' . ($chunks * $size) . "\r\nConnection: close\r\n\r\n");
        for ($i = 1; $i <= $chunks; ++$i) {
            $this->after($i * $intervalMs / 1000, function () use ($connection, $size, $i, $chunks): void {
                if (!isset($this->connections[(int) $connection->stream])) {
                    return;
                }
                $this->write($connection, str_repeat('d', $size));
                if ($i === $chunks) {
                    $this->close($connection);
                }
            });
        }
    }

    /**
     * @param list<string> $headers
     */
    private function respond(FixtureConnection $connection, int $status, array $headers, string $body, bool $keepAlive = false): void
    {
        if (!isset($this->connections[(int) $connection->stream])) {
            return;
        }
        $text = 'HTTP/1.1 ' . $status . ' ' . self::reason($status) . "\r\n";
        foreach ($headers as $header) {
            $text .= $header . "\r\n";
        }
        $text .= 'Content-Length: ' . strlen($body) . "\r\nConnection: " . ($keepAlive ? 'keep-alive' : 'close') . "\r\n\r\n" . $body;
        $this->write($connection, $text);
        if ($keepAlive) {
            $connection->buffer = '';
            $connection->head = null;
            $connection->headerEnd = null;
            $connection->continueSent = false;
            $connection->answered = false;

            return;
        }
        $this->close($connection);
    }

    private function write(FixtureConnection $connection, string $data): void
    {
        $stream = $connection->stream;
        stream_set_blocking($stream, true);
        stream_set_timeout($stream, 1);
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = @fwrite($stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                break;
            }
            $offset += $written;
        }
        stream_set_blocking($stream, false);
    }

    /** Closes with SO_LINGER 0 so the peer sees a TCP reset instead of an orderly end of stream. */
    private function reset(FixtureConnection $connection): void
    {
        if (!$this->tls && function_exists('socket_import_stream')) {
            $socket = @socket_import_stream($connection->stream);
            if ($socket !== false) {
                socket_set_option($socket, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
                $this->close($connection);
                unset($socket);

                return;
            }
        }
        $this->close($connection);
    }

    private function close(FixtureConnection $connection): void
    {
        unset($this->connections[(int) $connection->stream]);
        @fclose($connection->stream);
    }

    /**
     * Every value of every key, in order; a repeated key keeps all its values (several Retry-After lines).
     *
     * @return array<string, list<string>>
     */
    private function query(string $raw): array
    {
        $result = [];
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $result[urldecode($parts[0])][] = urldecode($parts[1] ?? '');
        }

        return $result;
    }

    private static function reason(int $status): string
    {
        return match ($status) {
            200 => 'OK', 204 => 'No Content', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 307 => 'Temporary Redirect',
            308 => 'Permanent Redirect', 400 => 'Bad Request', 404 => 'Not Found', 408 => 'Request Timeout', 409 => 'Conflict',
            410 => 'Gone', 429 => 'Too Many Requests', 500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
            504 => 'Gateway Timeout', default => 'Status',
        };
    }
}

$options = getopt('', ['host::', 'port::', 'tls::']);
$tlsDirectory = is_string($options['tls'] ?? null) ? $options['tls'] : null;
$host = is_string($options['host'] ?? null) ? $options['host'] : '127.0.0.1';
$port = is_string($options['port'] ?? null) ? $options['port'] : '0';

$context = stream_context_create($tlsDirectory === null ? [] : ['ssl' => [
    'local_cert' => $tlsDirectory . '/server.crt',
    'local_pk' => $tlsDirectory . '/server.key',
    'verify_peer' => false,
    'allow_self_signed' => true,
]]);
$server = @stream_socket_server(($tlsDirectory === null ? 'tcp' : 'ssl') . '://' . $host . ':' . $port, $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, 'fixture: cannot listen: ' . $errorMessage . "\n");
    exit(2);
}
stream_set_blocking($server, false);
$name = stream_socket_get_name($server, false);
fwrite(STDOUT, 'LISTENING ' . ($name === false ? $host . ':' . $port : $name) . "\n");
fflush(STDOUT);

(new FixtureServer($server, $tlsDirectory !== null))->run();
