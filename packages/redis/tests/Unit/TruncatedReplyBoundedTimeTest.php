<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Unit;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use Predis\Connection\ConnectionException;

/**
 * Regression test for the Predis 3.6.1 defect that raised the floor to 3.7.0.
 *
 * When the peer closes the connection in the middle of a bulk reply, Predis 3.6.1 spun in a loop that ignored
 * read_write_timeout (a worker would be lost, burning CPU). Predis 3.7.0 raises a connection exception instead. The
 * client runs in a child process with a hard deadline so that a regression fails this test instead of hanging the suite.
 */
final class TruncatedReplyBoundedTimeTest extends TestCase
{
    private const DEADLINE_SECONDS = 10;

    public function testAReplyCutInTheMiddleOfABulkStringEndsInAConnectionExceptionInBoundedTime(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertNotFalse($server, (string) $errorMessage);
        $name = stream_socket_get_name($server, false);
        self::assertIsString($name);
        $port = substr($name, (int) strrpos($name, ':') + 1);

        $classLoaderFile = (new \ReflectionClass(ClassLoader::class))->getFileName();
        self::assertIsString($classLoaderFile);
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../Support/truncated-reply-client.php', dirname($classLoaderFile, 2) . '/autoload.php', $port],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);

        $output = '';
        try {
            $peer = stream_socket_accept($server, 5.0);
            self::assertNotFalse($peer, 'the client never connected');
            stream_set_timeout($peer, 2);
            fread($peer, 4096); // the first command of the connection
            // Promise a 100-byte bulk string, deliver 3 bytes, close.
            fwrite($peer, "\$100\r\nabc");
            fclose($peer);

            stream_set_blocking($pipes[1], false);
            $deadline = hrtime(true) + self::DEADLINE_SECONDS * 1_000_000_000;
            do {
                $status = proc_get_status($process);
                $chunk = fread($pipes[1], 8192);
                $output .= is_string($chunk) ? $chunk : '';
                if (!$status['running']) {
                    break;
                }
                usleep(20_000);
            } while (hrtime(true) < $deadline);

            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
                self::fail('The client did not return within ' . self::DEADLINE_SECONDS . ' s after the peer closed mid reply: the Predis busy-loop defect is back.');
            }
            $chunk = stream_get_contents($pipes[1]);
            $output .= is_string($chunk) ? $chunk : '';
        } finally {
            fclose($server);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
        }

        $fields = explode("\t", trim($output));
        self::assertSame(ConnectionException::class, $fields[0], 'unexpected client output: ' . $output);
        self::assertArrayHasKey(1, $fields);
        self::assertLessThan(3_000, (int) $fields[1], 'the failure must be prompt, not a timeout');
    }
}
