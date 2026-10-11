<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\Connection\Resource\Exception\StreamInitException;

/** A refused connection is a definite "request not sent" failure and ends in bounded time. */
final class ClosedPortFailsFastTest extends TestCase
{
    public function testConnectionRefusedIsAStreamInitFailureWithinTheTimeout(): void
    {
        // Reserve a port, then close it, so that nothing listens there.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertNotFalse($server, (string) $errorMessage);
        $name = stream_socket_get_name($server, false);
        self::assertIsString($name);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($server);

        $client = new Client(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => $port, 'timeout' => 2.0, 'read_write_timeout' => 2.0]);
        $started = hrtime(true);
        try {
            $client->ping();
            self::fail('Nothing listens on the port; the connection must fail.');
        } catch (StreamInitException) {
            // expected
        }
        $elapsedMilliseconds = intdiv(hrtime(true) - $started, 1_000_000);

        self::assertLessThan(2_000, $elapsedMilliseconds, 'a refused connection must not wait for the timeout');
    }
}
