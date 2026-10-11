<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\Assert;

/**
 * Starts tests/Fixture/server.php as a child process on a free loopback port and talks to its control endpoints.
 * Each instance owns one server; call stop() (or let the destructor do it) to end it.
 */
final class FixtureServer
{
    /** @var resource|null */
    private $process;

    /**
     * @param resource $process
     */
    private function __construct($process, public readonly string $baseUri, private readonly ?string $caFile = null)
    {
        $this->process = $process;
    }

    /**
     * Writes a throwaway CA, a server certificate for "localhost" and an unrelated CA into a new temporary directory.
     *
     * @return string the directory (ca.crt, server.crt, server.key, other-ca.crt)
     */
    public static function makeCertificates(): string
    {
        $directory = sys_get_temp_dir() . '/reqtide-tls-' . bin2hex(random_bytes(6));
        $script = dirname(__DIR__, 4) . '/tools/docker/make-test-ca.php';
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($directory) . ' 2>&1', $output, $status);
        Assert::assertSame(0, $status, 'certificate generation failed: ' . implode("
", $output));

        return $directory;
    }

    /** A server that speaks TLS with the certificates of makeCertificates(); its base URI uses the name "localhost". */
    public static function startTls(string $certificateDirectory): self
    {
        return self::start($certificateDirectory);
    }

    public static function start(?string $certificateDirectory = null): self
    {
        $arguments = [PHP_BINARY, dirname(__DIR__) . '/Fixture/server.php', '--host=127.0.0.1', '--port=0'];
        if ($certificateDirectory !== null) {
            $arguments[] = '--tls=' . $certificateDirectory;
        }
        $process = proc_open(
            $arguments,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        Assert::assertIsResource($process, 'the fixture server process did not start');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);

        $deadline = hrtime(true) + 5_000_000_000;
        $line = '';
        while (hrtime(true) < $deadline) {
            $chunk = fgets($pipes[1]);
            if (is_string($chunk)) {
                $line .= $chunk;
                if (str_ends_with($line, "\n")) {
                    break;
                }
            }
            usleep(10_000);
        }
        if (preg_match('~^LISTENING (127\.0\.0\.1:[0-9]+)$~', trim($line), $m) !== 1) {
            proc_terminate($process);
            Assert::fail('the fixture server did not report a listening address: ' . $line);
        }

        if ($certificateDirectory === null) {
            return new self($process, 'http://' . $m[1]);
        }
        $port = substr($m[1], strrpos($m[1], ':') + 1);

        return new self($process, 'https://localhost:' . $port, $certificateDirectory . '/ca.crt');
    }

    public function stop(): void
    {
        if ($this->process !== null) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    /** A Guzzle client on the cURL easy handler that returns error statuses and does not follow redirects. */
    public function client(float $timeout = 5.0, bool $followRedirects = false): Client
    {
        return new Client([
            'verify' => $this->caFile ?? true,
            'base_uri' => $this->baseUri,
            'handler' => HandlerStack::create(new CurlHandler()),
            'http_errors' => false,
            'allow_redirects' => $followRedirects,
            'timeout' => $timeout,
            'connect_timeout' => 2.0,
        ]);
    }

    /**
     * Every request the server has recorded since the last reset, in arrival order.
     *
     * @return list<array<string, mixed>>
     */
    public function log(): array
    {
        $body = (string) $this->client()->get('/__log')->getBody();
        /** @var list<array<string, mixed>> */
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    public function reset(): void
    {
        $this->client()->post('/__reset');
    }

    /** Number of recorded requests whose target starts with the given path. */
    public function count(string $pathPrefix): int
    {
        return count(array_filter($this->log(), static fn(array $entry): bool => is_string($entry['target']) && str_starts_with($entry['target'], $pathPrefix)));
    }
}
