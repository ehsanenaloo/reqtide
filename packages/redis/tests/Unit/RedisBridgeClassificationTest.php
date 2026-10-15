<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Unit;

use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Redis\Client\BridgeCapabilities;
use Enaxon\Reqtide\Redis\Client\CommandEffect;
use Enaxon\Reqtide\Redis\Client\PortReply;
use Enaxon\Reqtide\Redis\Client\RedisBridge;
use Enaxon\Reqtide\Redis\Script\Script;
use Enaxon\Reqtide\Redis\Script\ScriptLibrary;
use Enaxon\Reqtide\Redis\Tests\Support\ScriptedPort;
use Enaxon\Reqtide\State\StateScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Connection\ConnectionException;
use Predis\Connection\Resource\Exception\StreamInitException;

/**
 * The bridge failure table at class level, with a scripted port: what is sent, how often, and the
 * typed result. The same classes are exercised against a real server in the integration suite.
 */
final class RedisBridgeClassificationTest extends TestCase
{
    private const SCRIPT_TEXT = "return redis.call('INCR', KEYS[1])";

    private function bridge(ScriptedPort $port): RedisBridge
    {
        return new RedisBridge($port, new BridgeCapabilities(0.5));
    }

    private function execute(ScriptedPort $port, CommandEffect $effect = CommandEffect::Mutating): mixed
    {
        return $this->bridge($port)->execute(new Script(self::SCRIPT_TEXT), ['rt:k1', 'rt:k2'], ['a1'], $effect);
    }

    public function testSuccessSendsOneEvalshaWithKeysAndArgumentsOutsideTheScriptText(): void
    {
        $port = new ScriptedPort([new PortReply(false, 7)]);
        self::assertSame(7, $this->execute($port));

        $script = new Script(self::SCRIPT_TEXT);
        self::assertSame([['EVALSHA', $script->digest, '2', 'rt:k1', 'rt:k2', 'a1']], $port->sent);
        self::assertSame(1, $port->connects);
        self::assertStringNotContainsString('rt:k1', $script->text, 'user data must travel in KEYS and ARGV, never in the script text');
    }

    public function testAConnectFailureIsUnavailableAndNothingIsSent(): void
    {
        $port = new ScriptedPort([], new StreamInitException('secret-host:6379 refused'));
        try {
            $this->execute($port);
            self::fail('expected an exception');
        } catch (StateUnavailableException $e) {
            self::assertSame([], $port->sent);
            self::assertInstanceOf(StreamInitException::class, $e->getPrevious());
            self::assertStringNotContainsString('secret-host', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function failures(): iterable
    {
        yield 'predis connection exception' => [(new \ReflectionClass(ConnectionException::class))->newInstanceWithoutConstructor()];
        yield 'timeout-like runtime exception' => [new \RuntimeException('timed out reading from secret-host')];
        yield 'logic exception' => [new \LogicException('boom secret-host')];
        yield 'error' => [new \TypeError('type secret-host')];
    }

    #[DataProvider('failures')]
    public function testATransportFailureOfAMutatingCommandIsIndeterminateAndIsNeverResent(\Throwable $failure): void
    {
        $port = new ScriptedPort([$failure]);
        try {
            $this->execute($port);
            self::fail('expected an exception');
        } catch (IndeterminateStateException $e) {
            self::assertCount(1, $port->sent, 'a mutation is never sent twice');
            self::assertSame($failure, $e->getPrevious());
            self::assertStringNotContainsString('secret-host', $e->getMessage());
        }
    }

    #[DataProvider('failures')]
    public function testATransportFailureOfAReadOnlyCommandIsUnavailable(\Throwable $failure): void
    {
        $port = new ScriptedPort([$failure]);
        $this->expectException(StateUnavailableException::class);
        try {
            $this->execute($port, CommandEffect::ReadOnly);
        } finally {
            self::assertCount(1, $port->sent);
        }
    }

    public function testNoscriptLoadsOnceAndRetriesOnce(): void
    {
        $script = new Script(self::SCRIPT_TEXT);
        $port = new ScriptedPort([
            new PortReply(true, 'NOSCRIPT No matching script. Please use EVAL.'),
            new PortReply(false, $script->digest),
            new PortReply(false, 1),
        ]);
        self::assertSame(1, $this->execute($port));
        self::assertSame(['EVALSHA', 'SCRIPT LOAD', 'EVALSHA'], $port->names());
        self::assertSame(['SCRIPT', 'LOAD', self::SCRIPT_TEXT], $port->sent[1]);
    }

    public function testASecondNoscriptIsUnavailableAfterExactlyThreeCommands(): void
    {
        $script = new Script(self::SCRIPT_TEXT);
        $noscript = new PortReply(true, 'NOSCRIPT No matching script. Please use EVAL.');
        $port = new ScriptedPort([$noscript, new PortReply(false, $script->digest), $noscript]);
        try {
            $this->execute($port);
            self::fail('expected an exception');
        } catch (StateUnavailableException) {
            self::assertCount(3, $port->sent);
        }
    }

    public function testAFailedLoadIsUnavailableNeverIndeterminate(): void
    {
        $port = new ScriptedPort([new PortReply(true, 'NOSCRIPT x'), new \RuntimeException('reset during load')]);
        try {
            $this->execute($port);
            self::fail('expected an exception');
        } catch (StateUnavailableException) {
            self::assertSame(['EVALSHA', 'SCRIPT LOAD'], $port->names());
        }
    }

    public function testALoadAnswerWithTheWrongDigestIsUnavailable(): void
    {
        $port = new ScriptedPort([new PortReply(true, 'NOSCRIPT x'), new PortReply(false, 'deadbeef')]);
        $this->expectException(StateUnavailableException::class);
        $this->execute($port);
    }

    public function testATransportFailureAfterTheRetryIsIndeterminateWithNoFourthCommand(): void
    {
        $script = new Script(self::SCRIPT_TEXT);
        $port = new ScriptedPort([new PortReply(true, 'NOSCRIPT x'), new PortReply(false, $script->digest), new \RuntimeException('reset')]);
        try {
            $this->execute($port);
            self::fail('expected an exception');
        } catch (IndeterminateStateException) {
            self::assertCount(3, $port->sent);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function definiteRejections(): iterable
    {
        foreach (['OOM command not allowed when used memory > maxmemory', 'BUSY Redis is busy running a script', 'LOADING Redis is loading the dataset in memory', 'READONLY You can\'t write against a read only replica', 'NOPERM User x has no permissions to run the \'evalsha\' command', 'MISCONF Redis is configured to save RDB snapshots', 'NOAUTH Authentication required', 'WRONGPASS invalid username-password pair', 'ERR ACL failure in script: User x has no permissions to run the \'time\' command', 'MOVED 3999 127.0.0.1:6381', 'ASK 3999 127.0.0.1:6381', 'CLUSTERDOWN The cluster is down'] as $text) {
            yield strtok($text, ' ') ?: $text => [$text];
        }
    }

    #[DataProvider('definiteRejections')]
    public function testAServerRejectionBeforeExecutionIsUnavailableForBothEffects(string $text): void
    {
        foreach ([CommandEffect::Mutating, CommandEffect::ReadOnly] as $effect) {
            $port = new ScriptedPort([new PortReply(true, $text)]);
            try {
                $this->execute($port, $effect);
                self::fail('expected an exception');
            } catch (StateUnavailableException) {
                self::assertCount(1, $port->sent, 'a rejected command is not re-sent');
            }
        }
    }

    /**
     * @return iterable<string, array{string, class-string<\Throwable>}>
     */
    public static function scriptCodes(): iterable
    {
        yield 'corrupt' => ['RT_CORRUPT bad schema version', CorruptStateException::class];
        yield 'capacity' => ['RT_CAPACITY partition ceiling', StateCapacityExceededException::class];
        yield 'config' => ['RT_CONFIG limit above the exact range', InvalidConfigurationException::class];
        yield 'registry exists' => ['RT_EXISTS the registry exists', InvalidConfigurationException::class];
        yield 'leftovers' => ['RT_LEFTOVERS keys of a retired registry exist under this prefix', InvalidConfigurationException::class];
        yield 'version' => ['RT_VERSION these scripts need Redis 7.0 or newer', InvalidConfigurationException::class];
        yield 'wrong type' => ['WRONGTYPE Operation against a key holding the wrong kind of value script: abc, on @user_script:5.', CorruptStateException::class];
    }

    /** @param class-string<\Throwable> $class */
    #[DataProvider('scriptCodes')]
    public function testARegisteredScriptErrorCodeMapsToItsTypedException(string $text, string $class): void
    {
        $this->expectException($class);
        $this->execute(new ScriptedPort([new PortReply(true, $text)]));
    }

    public function testAWrongTypeIsCorruptStateForBothEffectsAndNeverIndeterminate(): void
    {
        foreach ([CommandEffect::Mutating, CommandEffect::ReadOnly] as $effect) {
            $port = new ScriptedPort([new PortReply(true, 'WRONGTYPE Operation against a key holding the wrong kind of value')]);
            try {
                $this->execute($port, $effect);
                self::fail('expected an exception');
            } catch (CorruptStateException) {
                self::assertCount(1, $port->sent);
            }
        }
    }

    public function testARefusedConnectionBecauseOfTheClientLimitIsAFailureBeforeAnyCommand(): void
    {
        foreach ([CommandEffect::Mutating, CommandEffect::ReadOnly] as $effect) {
            $port = new ScriptedPort([new PortReply(true, 'ERR max number of clients reached')]);
            try {
                $this->execute($port, $effect);
                self::fail('expected an exception');
            } catch (StateUnavailableException) {
                self::assertCount(1, $port->sent);
            }
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function serverVersions(): iterable
    {
        yield '6.2.14' => ["# Server\r\nredis_version:6.2.14\r\nredis_mode:standalone\r\n", true];
        yield '5.0.7' => ["# Server\r\nredis_version:5.0.7\r\n", true];
        yield '7.0.0' => ["# Server\r\nredis_version:7.0.0\r\n", false];
        yield '7.4.11' => ["# Server\r\nredis_version:7.4.11\r\n", false];
        yield '8.2.10' => ["# Server\r\nredis_version:8.2.10\r\n", false];
        yield 'no version line' => ["# Server\r\nos:Linux\r\n", false];
    }

    #[DataProvider('serverVersions')]
    public function testTheServerVersionIsCheckedAgainstTheMinimumOfSevenPointZero(string $info, bool $refused): void
    {
        $port = new ScriptedPort([new PortReply(false, $info)]);
        if ($refused) {
            try {
                $this->bridge($port)->requireSupportedServer();
                self::fail('expected an exception');
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('7.0', $e->getMessage());
                self::assertStringNotContainsString('6.2', $e->getMessage());
            }
        } else {
            $this->bridge($port)->requireSupportedServer();
        }
        self::assertSame([['INFO', 'server']], $port->sent);
    }

    public function testAServerInClusterModeIsRefusedWithTheReason(): void
    {
        $port = new ScriptedPort([new PortReply(false, "# Server\r\nredis_version:7.4.11\r\nredis_mode:cluster\r\n")]);
        try {
            $this->bridge($port)->requireSupportedServer();
            self::fail('expected an exception');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('Cluster', $e->getMessage());
        }
        self::assertSame([['INFO', 'server']], $port->sent);
    }

    public function testAServerInStandaloneModeIsNotRefusedAsACluster(): void
    {
        $port = new ScriptedPort([new PortReply(false, "# Server\r\nredis_version:8.2.10\r\nredis_mode:standalone\r\n")]);
        $this->bridge($port)->requireSupportedServer();
        self::assertCount(1, $port->sent);
    }

    public function testAServerThatRefusesInfoIsNotRefusedHere(): void
    {
        $port = new ScriptedPort([new PortReply(true, 'NOPERM User x has no permissions to run the \'info\' command')]);
        $this->bridge($port)->requireSupportedServer();
        self::assertCount(1, $port->sent);
    }

    public function testAFailedVersionReadIsUnavailable(): void
    {
        $this->expectException(StateUnavailableException::class);
        $this->bridge(new ScriptedPort([new \RuntimeException('reset')]))->requireSupportedServer();
    }

    public function testAPurgeStepRemovesOnlyTheRegistryKeysOfThePageInOneScriptThatNamesTheMetadataFirst(): void
    {
        $script = ScriptLibrary::registryPurge();
        $port = new ScriptedPort([
            new PortReply(false, ['17', ['rt:i', 'rt:t', 'rt:note', 'rt:p:cc:x', 'other:p:y', 'rt:m', 'rt:p:cc:x']]),
            new PortReply(false, 3),
        ]);

        self::assertSame(['17', 3], $this->bridge($port)->purgeStep('rt:m', 'rt', '0'));
        self::assertSame(['SCAN', '0', 'MATCH', 'rt:*', 'COUNT', '500'], $port->sent[0]);
        self::assertSame(['EVALSHA', $script->digest, '4', 'rt:m', 'rt:i', 'rt:t', 'rt:p:cc:x'], $port->sent[1]);
    }

    public function testAPurgeStepOfAnEmptyPageStillRunsTheScriptSoAnExistingRegistryIsRefused(): void
    {
        $port = new ScriptedPort([new PortReply(false, ['0', []]), new PortReply(true, 'RT_EXISTS the registry exists')]);
        try {
            $this->bridge($port)->purgeStep('rt:m', 'rt', '0');
            self::fail('expected an exception');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('retire it', $e->getMessage());
        }
        self::assertSame(['SCAN', 'EVALSHA'], $port->names());
    }

    public function testAPurgeStepRejectsAMalformedCursorBeforeAnyScan(): void
    {
        $port = new ScriptedPort([]);
        try {
            $this->bridge($port)->purgeStep('rt:m', 'rt', '1; FLUSHALL');
            self::fail('expected an exception');
        } catch (InvalidConfigurationException) {
            self::assertSame([], $port->sent);
        }
    }

    public function testAnUnknownServerErrorIsConservative(): void
    {
        try {
            $this->execute(new ScriptedPort([new PortReply(true, 'ERR user_script:1: boom')]));
            self::fail('expected an exception');
        } catch (IndeterminateStateException) {
        }
        $this->expectException(StateUnavailableException::class);
        $this->execute(new ScriptedPort([new PortReply(true, 'ERR user_script:1: boom')]), CommandEffect::ReadOnly);
    }

    public function testCapabilitiesDeclareSharedAuthorityAFiniteTimeoutAndMicrosecondClock(): void
    {
        $capabilities = new BridgeCapabilities(0.5);
        self::assertSame(StateScope::SharedAuthority, $capabilities->scope);
        self::assertSame(0.5, $capabilities->ioTimeoutSeconds);
        self::assertSame(1, $capabilities->clockResolutionMicroseconds());
    }

    public function testAnEmptyScriptIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new Script('');
    }
}
