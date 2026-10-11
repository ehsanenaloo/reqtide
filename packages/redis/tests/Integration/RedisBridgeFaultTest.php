<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Integration;

use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Redis\Client\CommandEffect;
use Enaxon\Reqtide\Redis\Client\RedisBridge;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\Script\Script;
use Enaxon\Reqtide\Redis\Tests\Support\RedisEndpoint;
use PHPUnit\Framework\TestCase;
use Predis\Client;

/**
 * The bridge against a real server: each fault class ends in bounded time with the classification of the failure table,
 * a mutation is never sent twice, and a lost script cache recovers once (bridge level).
 */
final class RedisBridgeFaultTest extends TestCase
{
    private const INCR = "return redis.call('INCR', KEYS[1])";

    /** Applies the increment, then holds the server for one second: the caller times out after the write. */
    private const INCR_THEN_STALL = "redis.call('INCR', KEYS[1]) local t = os.clock() while os.clock() - t < 1.0 do end return 1";

    private string $key = '';

    protected function setUp(): void
    {
        $this->key = 'rt:test:' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $endpoint = RedisEndpoint::fromEnvironment();
        if ($endpoint !== null) {
            (new Client($endpoint->parameters(10.0)))->del($this->key);
        }
    }

    private function admin(): Client
    {
        return new Client(RedisEndpoint::forTest()->parameters(10.0));
    }

    private function options(float $readWrite = 2.0, ?string $username = null, ?string $password = null, ?int $port = null): RedisConnectionOptions
    {
        $endpoint = RedisEndpoint::forTest();

        return new RedisConnectionOptions($endpoint->host, 2.0, $readWrite, $port ?? $endpoint->port, $username, $password);
    }

    public function testAScriptRoundTripPassesKeysAndArgumentsAndReturnsTheReply(): void
    {
        $bridge = RedisBridge::forOptions($this->options());
        $script = new Script("return {redis.call('INCRBY', KEYS[1], ARGV[1]), ARGV[2]}");

        self::assertSame([5, 'tag'], $bridge->execute($script, [$this->key], ['5', 'tag'], CommandEffect::Mutating));
        self::assertSame('5', $this->admin()->get($this->key));
    }

    public function testALostScriptCacheRecoversOnceAndTheMutationRunsExactlyOnce(): void
    {
        $bridge = RedisBridge::forOptions($this->options());
        $script = new Script(self::INCR);
        self::assertSame(1, $bridge->execute($script, [$this->key], [], CommandEffect::Mutating));

        $this->admin()->executeRaw(['SCRIPT', 'FLUSH']);
        self::assertSame(2, $bridge->execute($script, [$this->key], [], CommandEffect::Mutating), 'the positive NOSCRIPT is recovered by one load and one resend');
        self::assertSame('2', $this->admin()->get($this->key), 'applied once per call, never twice');
    }

    public function testARefusedConnectionIsUnavailableInBoundedTime(): void
    {
        $closed = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($closed);
        $name = stream_socket_get_name($closed, false);
        self::assertIsString($name);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($closed);

        $bridge = RedisBridge::forOptions(new RedisConnectionOptions('127.0.0.1', 1.0, 1.0, $port));
        $started = hrtime(true);
        try {
            $bridge->execute(new Script(self::INCR), [$this->key], [], CommandEffect::Mutating);
            self::fail('expected an exception');
        } catch (StateUnavailableException) {
            self::assertLessThan(3.0, (hrtime(true) - $started) / 1e9);
        }
    }

    public function testATimeoutAfterTheWriteIsIndeterminateForAMutationAndTheMutationIsNeverResent(): void
    {
        $bridge = RedisBridge::forOptions($this->options(0.3));
        $started = hrtime(true);
        try {
            $bridge->execute(new Script(self::INCR_THEN_STALL), [$this->key], [], CommandEffect::Mutating);
            self::fail('expected an exception');
        } catch (IndeterminateStateException $e) {
            self::assertLessThan(1.5, (hrtime(true) - $started) / 1e9, 'the call ended at its read timeout, not when the script ended');
            self::assertNotNull($e->getPrevious());
        }

        // GET waits for the busy script to end; the increment was applied by the first and only send.
        self::assertSame('1', $this->admin()->get($this->key));
        usleep(300_000);
        self::assertSame('1', $this->admin()->get($this->key), 'no second send after the failure');
    }

    public function testATimeoutOfAReadOnlyCommandIsUnavailable(): void
    {
        $bridge = RedisBridge::forOptions($this->options(0.3));
        $this->expectException(StateUnavailableException::class);
        try {
            $bridge->execute(new Script(self::INCR_THEN_STALL), [$this->key], [], CommandEffect::ReadOnly);
        } finally {
            $this->admin()->get($this->key); // let the server finish the stalled script before the next test
        }
    }

    public function testAScriptErrorCodeIsMappedAndAnUnknownScriptErrorIsConservative(): void
    {
        $bridge = RedisBridge::forOptions($this->options());
        try {
            $bridge->execute(new Script("return redis.error_reply('RT_CORRUPT schema')"), [$this->key], [], CommandEffect::Mutating);
            self::fail('expected an exception');
        } catch (CorruptStateException) {
        }
        $this->expectException(IndeterminateStateException::class);
        $bridge->execute(new Script("error('boom')"), [$this->key], [], CommandEffect::Mutating);
    }

    public function testAnAclDenialOfAScriptCommandIsADefiniteFailureWithNoPartialWrite(): void
    {
        $admin = $this->admin();
        $user = 'rt_acl_' . bin2hex(random_bytes(3));
        $password = 'pw-' . bin2hex(random_bytes(6));
        $handshake = ['+hello', '+ping', '+client|setname', '+client|setinfo', '+evalsha', '+script|load'];
        $admin->executeRaw(['ACL', 'SETUSER', $user, 'on', '>' . $password, 'resetkeys', 'resetchannels', '-@all', ...$handshake, '+incr', '+get', '~rt:*']);
        try {
            $bridge = RedisBridge::forOptions($this->options(2.0, $user, $password));
            // Validate first (TIME is not allowed for this user), write second: the denial happens before any write.
            $script = new Script("local t = redis.call('TIME') return redis.call('INCR', KEYS[1])");
            try {
                $bridge->execute($script, [$this->key], [], CommandEffect::Mutating);
                self::fail('expected an exception');
            } catch (StateUnavailableException $e) {
                self::assertStringNotContainsString($password, $e->getMessage());
            }
            self::assertNull($admin->get($this->key), 'no partial write');

            // The same user may run what it was granted.
            self::assertSame(1, $bridge->execute(new Script(self::INCR), [$this->key], [], CommandEffect::Mutating));
        } finally {
            $admin->executeRaw(['ACL', 'DELUSER', $user]);
        }
    }

    public function testAMissingEvalshaPermissionIsAnUnavailableRejection(): void
    {
        $admin = $this->admin();
        $user = 'rt_acl_' . bin2hex(random_bytes(3));
        $password = 'pw-' . bin2hex(random_bytes(6));
        $admin->executeRaw(['ACL', 'SETUSER', $user, 'on', '>' . $password, 'resetkeys', '-@all', '+hello', '+ping', '+client|setname', '+client|setinfo', '~rt:*']);
        try {
            $bridge = RedisBridge::forOptions($this->options(2.0, $user, $password));
            $this->expectException(StateUnavailableException::class);
            $bridge->execute(new Script(self::INCR), [$this->key], [], CommandEffect::Mutating);
        } finally {
            $admin->executeRaw(['ACL', 'DELUSER', $user]);
        }
    }

    /**
     * A failure during authentication, whatever its cause, must not carry the password anywhere in what the library throws,
     * including the Predis exceptions kept as `previous` and the trace arguments PHP records when
     * zend.exception_ignore_args is Off.
     */
    private function assertNoSecretInChain(\Throwable $failure, string $marker): void
    {
        $depth = 0;
        for ($link = $failure; $link !== null; $link = $link->getPrevious()) {
            ++$depth;
            foreach ([$link->getMessage(), $link->getTraceAsString(), (string) $link] as $text) {
                self::assertStringNotContainsString($marker, $text, 'link ' . $depth . ' of the exception chain (' . $link::class . ')');
            }
        }
    }

    public function testThePasswordIsNowhereInTheChainOfAServerRefusedAuthentication(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $password = 'RTSECRET' . bin2hex(random_bytes(8));
            $bridge = RedisBridge::forOptions($this->options(2.0, 'no_such_user', $password));
            try {
                $bridge->execute(new Script(self::INCR), [$this->key], [], CommandEffect::Mutating);
                self::fail('expected an exception');
            } catch (StateUnavailableException $failure) {
                self::assertNotNull($failure->getPrevious(), 'the Predis exception is kept as previous');
                $this->assertNoSecretInChain($failure, 'RTSECRET');
                self::assertStringNotContainsString('RTSECRET', print_r($failure->getPrevious()?->getTrace(), true), 'nor in the recorded trace arguments');
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    public function testThePasswordIsNowhereInTheChainWhenTheServerGoesSilentDuringAuthentication(): void
    {
        $server = proc_open(
            [PHP_BINARY, '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); $n = stream_socket_get_name($s, false); echo substr($n, strrpos($n, ":") + 1), "\n"; fflush(STDOUT); $c = @stream_socket_accept($s, 10); if ($c) { fread($c, 4096); sleep(3); }'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($server);
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $port = (int) trim((string) fgets($pipes[1]));
            self::assertGreaterThan(0, $port);
            $password = 'RTSECRET' . bin2hex(random_bytes(8));
            $options = new RedisConnectionOptions('127.0.0.1', 2.0, 0.3, $port, 'someone', $password);
            try {
                RedisBridge::forOptions($options)->execute(new Script(self::INCR), [$this->key], [], CommandEffect::Mutating);
                self::fail('expected an exception');
            } catch (StateUnavailableException $failure) {
                $this->assertNoSecretInChain($failure, 'RTSECRET');
                self::assertStringNotContainsString('RTSECRET', print_r($failure->getPrevious()?->getTrace(), true), 'nor in the recorded trace arguments');
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
            proc_terminate($server);
            proc_close($server);
        }
    }

    public function testAWrongPasswordIsUnavailableAndNeverEchoesTheCredential(): void
    {
        $password = 'wrong-' . bin2hex(random_bytes(6));
        $bridge = RedisBridge::forOptions($this->options(2.0, 'no_such_user', $password));
        try {
            $bridge->execute(new Script(self::INCR), [$this->key], [], CommandEffect::Mutating);
            self::fail('expected an exception');
        } catch (StateUnavailableException $e) {
            self::assertStringNotContainsString($password, $e->getMessage());
        }
    }
}
