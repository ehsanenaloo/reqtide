<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Support;

use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use PHPUnit\Framework\Assert;

/**
 * A running fault proxy (fault-proxy.php) in front of the Redis server under test. Its mode changes at any time:
 * `pass`, `drop-request`, `drop-reply` or `stall`, optionally `@N` for the N-th command once (see the script). Options built here connect through the proxy.
 */
final class FaultProxy
{
    private ?string $modeFile;

    /**
     * @param resource $process
     */
    private function __construct(private $process, string $modeFile, public readonly int $port)
    {
        $this->modeFile = $modeFile;
    }

    public static function start(RedisEndpoint $upstream): self
    {
        $modeFile = tempnam(sys_get_temp_dir(), 'rt_proxy_');
        Assert::assertIsString($modeFile);
        file_put_contents($modeFile, 'pass');
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fault-proxy.php', '--upstream=' . $upstream->host . ':' . $upstream->port, '--mode-file=' . $modeFile],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        Assert::assertIsResource($process, 'the fault proxy did not start');
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
        if (preg_match('~^LISTENING ([0-9]+)$~', trim($line), $m) !== 1) {
            proc_terminate($process);
            Assert::fail('the fault proxy did not report a port: ' . $line);
        }

        return new self($process, $modeFile, (int) $m[1]);
    }

    public function mode(string $mode): void
    {
        Assert::assertMatchesRegularExpression('~^(pass|(drop-request|drop-reply|stall)(@[1-9][0-9]*)?)\z~', $mode);
        file_put_contents($this->modeFile ?? '', $mode);
    }

    /** Connection options that go through the proxy. */
    public function options(float $readWriteTimeout = 1.0): RedisConnectionOptions
    {
        return new RedisConnectionOptions('127.0.0.1', 2.0, $readWriteTimeout, $this->port);
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        if ($this->modeFile !== null) {
            @unlink($this->modeFile);
            $this->modeFile = null;
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
