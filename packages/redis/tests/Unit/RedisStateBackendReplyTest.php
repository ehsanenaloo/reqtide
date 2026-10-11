<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Unit;

use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\Redis\Client\BridgeCapabilities;
use Enaxon\Reqtide\Redis\Client\PortReply;
use Enaxon\Reqtide\Redis\Client\RedisBridge;
use Enaxon\Reqtide\Redis\RedisConcurrencyBackend;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisCooldownBackend;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\Tests\Support\ScriptedPort;
use Enaxon\Reqtide\State\ConcurrencyAdmissionStatus;
use Enaxon\Reqtide\State\CooldownStatus;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Tests\Contract\ContractIdentities;
use Enaxon\Reqtide\Value\BackendClockKind;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\TestCase;

/**
 * The Redis cooldown and concurrency backends against a scripted port: reply parsing, the mapping of faults, what is sent
 * and what is never sent. No server is involved.
 */
final class RedisStateBackendReplyTest extends TestCase
{
    private const INCARNATION = '0123456789abcdef0123456789abcdef';

    private ?ContractIdentities $identities = null;

    private function ids(): ContractIdentities
    {
        return $this->identities ??= new ContractIdentities();
    }

    private function registry(): RedisRegistry
    {
        return new RedisRegistry('unit', 'unit-identity', 'a-host-managed-secret-of-32-bytes!!');
    }

    private function options(): RedisConnectionOptions
    {
        return new RedisConnectionOptions('127.0.0.1', 1.0, 1.0);
    }

    private function bridge(ScriptedPort $port): RedisBridge
    {
        return new RedisBridge($port, new BridgeCapabilities(1.0));
    }

    private function concurrency(ScriptedPort $port): RedisConcurrencyBackend
    {
        return new RedisConcurrencyBackend($this->options(), $this->registry(), $this->bridge($port));
    }

    private function cooldown(ScriptedPort $port): RedisCooldownBackend
    {
        return new RedisCooldownBackend($this->options(), $this->registry(), $this->bridge($port));
    }

    private function lease(): ConcurrencyPolicy
    {
        return new ConcurrencyPolicy(ContractIdentities::identity(), new PositiveInt(2), Duration::seconds(10));
    }

    private function cool(): CooldownPolicy
    {
        return new CooldownPolicy(ContractIdentities::identity(), Duration::seconds(60));
    }

    public function testCapabilitiesDeclareASharedAuthorityAFiniteTimeoutAndMicrosecondResolution(): void
    {
        foreach ([$this->concurrency(new ScriptedPort([]))->capabilities(), $this->cooldown(new ScriptedPort([]))->capabilities()] as $capabilities) {
            self::assertSame(StateScope::SharedAuthority, $capabilities->scope);
            self::assertSame(1_000_000_000, $capabilities->ioTimeout?->nanoseconds);
            self::assertSame(1_000, $capabilities->clockResolution->nanoseconds);
        }
    }

    public function testAcceptedAcquireBuildsASharedPermitFromTheReply(): void
    {
        $port = new ScriptedPort([new PortReply(false, ['1', self::INCARNATION, '1', '1893456110', '250000'])]);
        $admission = $this->concurrency($port)->acquire($this->lease(), $this->ids()->owner(), $this->ids()->command());

        self::assertSame(ConcurrencyAdmissionStatus::Accepted, $admission->status);
        $permit = $admission->permit;
        self::assertNotNull($permit);
        self::assertSame(self::INCARNATION, $permit->incarnation->lowerHex);
        self::assertSame(1, $permit->generation->value);
        self::assertSame(BackendClockKind::SharedAuthority, $permit->expiresAt->clockKind);
        self::assertSame([1893456110, 250000], [$permit->expiresAt->sharedInstant?->seconds, $permit->expiresAt->sharedInstant?->microseconds]);
        self::assertSame(1, count($port->sent), 'one command, never repeated');
        self::assertSame('EVALSHA', $port->sent[0][0]);
    }

    public function testAcquireSendsTheOwnerAndAFreshRandomIncarnationAsArguments(): void
    {
        $owner = $this->ids()->owner();
        $first = new ScriptedPort([new PortReply(false, ['0'])]);
        $second = new ScriptedPort([new PortReply(false, ['0'])]);
        $this->concurrency($first)->acquire($this->lease(), $owner, $this->ids()->command());
        $this->concurrency($second)->acquire($this->lease(), $owner, $this->ids()->command());

        // EVALSHA digest 5 key-count keys... then ARGV: identity, schema, capacity, lease (micros), owner, incarnation.
        $argumentsA = array_slice($first->sent[0], 2 + 5 + 1);
        $argumentsB = array_slice($second->sent[0], 2 + 5 + 1);
        self::assertSame(['unit-identity', '1', '2', '10000000', $owner->lowerHex], array_slice($argumentsA, 0, 5));
        self::assertMatchesRegularExpression('~^[0-9a-f]{32}\z~', $argumentsA[5]);
        self::assertNotSame($argumentsA[5], $argumentsB[5], 'each call carries its own random incarnation candidate');
    }

    public function testExhaustedAcquireYieldsNoPermit(): void
    {
        $admission = $this->concurrency(new ScriptedPort([new PortReply(false, ['0'])]))->acquire($this->lease(), $this->ids()->owner(), $this->ids()->command());
        self::assertSame(ConcurrencyAdmissionStatus::CapacityExhausted, $admission->status);
        self::assertNull($admission->permit);
    }

    public function testATransportFailureAfterTheSendIsIndeterminateForAnAcquire(): void
    {
        $this->expectException(IndeterminateStateException::class);
        $this->concurrency(new ScriptedPort([new \RuntimeException('connection reset')]))->acquire($this->lease(), $this->ids()->owner(), $this->ids()->command());
    }

    public function testATransportFailureAfterTheSendIsIndeterminateForAnExtension(): void
    {
        $this->expectException(IndeterminateStateException::class);
        $this->cooldown(new ScriptedPort([new \RuntimeException('connection reset')]))->extend($this->cool(), Duration::seconds(1), $this->ids()->command());
    }

    public function testATransportFailureDuringAReadIsUnavailableNotIndeterminate(): void
    {
        $this->expectException(StateUnavailableException::class);
        $this->cooldown(new ScriptedPort([new \RuntimeException('connection reset')]))->inspect($this->cool());
    }

    public function testACeilingRejectionIsACapacityException(): void
    {
        $this->expectException(StateCapacityExceededException::class);
        $this->concurrency(new ScriptedPort([new PortReply(true, 'RT_CAPACITY the state registry is at capacity')]))
            ->acquire($this->lease(), $this->ids()->owner(), $this->ids()->command());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedReplies(): iterable
    {
        yield 'not a list' => ['1'];
        yield 'empty list' => [[]];
        yield 'unknown status' => [['9', 'x', '1', '1', '1']];
        yield 'accepted with a short list' => [['1', self::INCARNATION]];
        yield 'bad incarnation' => [['1', 'NOT-HEX', '1', '1', '1']];
        yield 'zero generation' => [['1', self::INCARNATION, '0', '1', '1']];
        yield 'microseconds out of range' => [['1', self::INCARNATION, '1', '1', '1000000']];
        yield 'a number that is not text' => [['1', self::INCARNATION, 1, '1', '1']];
        yield 'leading zero' => [['1', self::INCARNATION, '01', '1', '1']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedReplies')]
    public function testAMalformedAcquireReplyIsCorruptState(mixed $reply): void
    {
        $this->expectException(CorruptStateException::class);
        $this->concurrency(new ScriptedPort([new PortReply(false, $reply)]))->acquire($this->lease(), $this->ids()->owner(), $this->ids()->command());
    }

    public function testReleaseMapsTheReplyCodes(): void
    {
        $permit = $this->concurrency(new ScriptedPort([new PortReply(false, ['1', self::INCARNATION, '1', '1893456110', '0'])]))
            ->acquire($this->lease(), $this->ids()->owner(), $this->ids()->command())->permit;
        self::assertNotNull($permit);

        foreach (['1' => FinalizationStatus::Applied, '2' => FinalizationStatus::AlreadyFinalized, '3' => FinalizationStatus::StaleOwnership] as $code => $expected) {
            $port = new ScriptedPort([new PortReply(false, [(string) $code])]);
            self::assertSame($expected, $this->concurrency($port)->release($this->lease(), $permit, $this->ids()->command())->status);
            // ARGV after the 5 keys: identity, schema, capacity, lease, owner, incarnation, generation, expiry seconds, expiry microseconds.
            self::assertSame([self::INCARNATION, '1', '1893456110', '0'], array_slice($port->sent[0], 2 + 5 + 1 + 5, 4));
        }

        $this->expectException(CorruptStateException::class);
        $this->concurrency(new ScriptedPort([new PortReply(false, ['7'])]))->release($this->lease(), $permit, $this->ids()->command());
    }

    public function testReleaseOfAPermitForAnotherPolicyIsStaleWithoutAnyCommand(): void
    {
        $permit = $this->concurrency(new ScriptedPort([new PortReply(false, ['1', self::INCARNATION, '1', '1893456110', '0'])]))
            ->acquire($this->lease(), $this->ids()->owner(), $this->ids()->command())->permit;
        self::assertNotNull($permit);

        $other = new ConcurrencyPolicy(ContractIdentities::identity('another'), new PositiveInt(2), Duration::seconds(10));
        $port = new ScriptedPort([]);
        self::assertSame(FinalizationStatus::StaleOwnership, $this->concurrency($port)->release($other, $permit, $this->ids()->command())->status);
        self::assertSame([], $port->sent);
    }

    public function testCooldownRepliesBecomeDecisions(): void
    {
        $clear = $this->cooldown(new ScriptedPort([new PortReply(false, ['0', '0'])]))->inspect($this->cool());
        self::assertSame(CooldownStatus::Clear, $clear->status);

        $active = $this->cooldown(new ScriptedPort([new PortReply(false, ['1', '2500000'])]))->extend($this->cool(), Duration::seconds(3), $this->ids()->command());
        self::assertSame(CooldownStatus::Active, $active->status);
        self::assertSame(2_500_000_000, $active->remaining->nanoseconds);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedCooldownReplies(): iterable
    {
        yield 'active without remaining time' => [['1', '0']];
        yield 'clear with remaining time' => [['0', '5']];
        yield 'one element' => [['1']];
        yield 'unknown status' => [['2', '5']];
        yield 'not text' => [['1', 5]];
        yield 'above the exact range' => [['1', '99999999999999999']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedCooldownReplies')]
    public function testAMalformedCooldownReplyIsCorruptState(mixed $reply): void
    {
        $this->expectException(CorruptStateException::class);
        $this->cooldown(new ScriptedPort([new PortReply(false, $reply)]))->inspect($this->cool());
    }

    public function testExtensionAndGraceAreRoundedUpToMicrosecondsAndSentOnce(): void
    {
        $port = new ScriptedPort([new PortReply(false, ['1', '2'])]);
        $policy = new CooldownPolicy(ContractIdentities::identity(), Duration::nanoseconds(60_000_000_001));
        $this->cooldown($port)->extend($policy, Duration::nanoseconds(1_500), $this->ids()->command());

        // EVALSHA digest 4 meta partition index queue, then ARGV: identity, schema, extension (micros), grace (micros).
        self::assertSame(['unit-identity', '1', '2', '60000001'], array_slice($port->sent[0], 2 + 4 + 1));
        self::assertCount(1, $port->sent);
    }

    public function testAnExtensionBeyondTheExactRangeIsRejectedBeforeAnyCommand(): void
    {
        $port = new ScriptedPort([]);
        $this->expectException(\Enaxon\Reqtide\Exception\InvalidConfigurationException::class);
        try {
            $this->cooldown($port)->extend($this->cool(), Duration::seconds(9_007_199_255), $this->ids()->command());
        } finally {
            self::assertSame([], $port->sent);
        }
    }
}
