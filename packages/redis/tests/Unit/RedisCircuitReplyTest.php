<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Unit;

use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\Policy\TimeCircuitWindow;
use Enaxon\Reqtide\Redis\Client\BridgeCapabilities;
use Enaxon\Reqtide\Redis\Client\PortReply;
use Enaxon\Reqtide\Redis\Client\RedisBridge;
use Enaxon\Reqtide\Redis\RedisCircuitBackend;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\Tests\Support\ScriptedPort;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\CircuitPermit;
use Enaxon\Reqtide\State\CircuitPermitKind;
use Enaxon\Reqtide\State\CircuitTransition;
use Enaxon\Reqtide\State\FinalizationStatus;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Tests\Contract\ContractCircuitRig;
use Enaxon\Reqtide\Tests\Contract\ContractIdentities;
use Enaxon\Reqtide\Value\BackendClockKind;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\Ratio;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The Redis circuit backend against a scripted port: argument encoding, reply parsing, fault mapping. No server. */
final class RedisCircuitReplyTest extends TestCase
{
    private const INCARNATION = '0123456789abcdef0123456789abcdef';

    private ?ContractIdentities $identities = null;

    private function ids(): ContractIdentities
    {
        return $this->identities ??= new ContractIdentities();
    }

    private function backend(ScriptedPort $port): RedisCircuitBackend
    {
        return new RedisCircuitBackend(
            new RedisConnectionOptions('127.0.0.1', 1.0, 1.0),
            new RedisRegistry('unit', 'unit-identity', 'a-host-managed-secret-of-32-bytes!!'),
            new RedisBridge($port, new BridgeCapabilities(1.0)),
        );
    }

    private function policy(): CircuitPolicy
    {
        return ContractCircuitRig::countPolicy(window: 50, minimum: 5, probeCapacity: 2, threshold: 2);
    }

    /** @return list<string> */
    private static function accepted(string $kind = 'c', string $from = '', string $to = '', string $reason = ''): array
    {
        return ['1', self::INCARNATION, '3', '1893456110', '250000', $kind, $from, $to, $reason];
    }

    private function permit(string $kind = 'c'): CircuitPermit
    {
        $admission = $this->backend(new ScriptedPort([new PortReply(false, self::accepted($kind))]))
            ->acquire($this->policy(), $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
        self::assertNotNull($admission->permit);

        return $admission->permit;
    }

    public function testCapabilitiesDeclareASharedAuthorityAFiniteTimeoutAndMicrosecondResolution(): void
    {
        $capabilities = $this->backend(new ScriptedPort([]))->capabilities();
        self::assertSame(StateScope::SharedAuthority, $capabilities->scope);
        self::assertSame(1_000_000_000, $capabilities->ioTimeout?->nanoseconds);
        self::assertSame(1_000, $capabilities->clockResolution->nanoseconds);
    }

    public function testAnAcceptedReplyBuildsASharedPermitWithItsKindAndTransitionFact(): void
    {
        $admission = $this->backend(new ScriptedPort([new PortReply(false, self::accepted('p', 'o', 'h', 'E'))]))
            ->acquire($this->policy(), $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());

        self::assertSame(CircuitAdmissionStatus::Accepted, $admission->status);
        $permit = $admission->permit;
        self::assertNotNull($permit);
        self::assertSame(CircuitPermitKind::HalfOpenProbe, $permit->kind);
        self::assertSame(3, $permit->generation->value);
        self::assertSame(self::INCARNATION, $permit->incarnation->lowerHex);
        self::assertSame(BackendClockKind::SharedAuthority, $permit->expiresAt->clockKind);
        self::assertSame([1893456110, 250000], [$permit->expiresAt->sharedInstant?->seconds, $permit->expiresAt->sharedInstant?->microseconds]);
        self::assertEquals(new CircuitTransition('open', 'half_open', ReasonCode::CircuitOpenElapsed->value), $admission->transition);
    }

    public function testOpenAndProbeCapacityRepliesCarryTheirWaitAndNoPermit(): void
    {
        $open = $this->backend(new ScriptedPort([new PortReply(false, ['2', '1500', '', '', '', '', '', '', ''])]))
            ->acquire($this->policy(), $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
        self::assertSame(CircuitAdmissionStatus::Open, $open->status);
        self::assertNull($open->permit);
        self::assertSame(1_500_000, $open->retryAfter?->nanoseconds);

        $exhausted = $this->backend(new ScriptedPort([new PortReply(false, ['3', '', '', '', '', '', 'c', 'o', 'T'])]))
            ->acquire($this->policy(), $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
        self::assertSame(CircuitAdmissionStatus::ProbeCapacityExhausted, $exhausted->status);
        self::assertEquals(new CircuitTransition('closed', 'open', ReasonCode::CircuitThresholdExceeded->value), $exhausted->transition);
    }

    public function testAcquireSendsTheSettingsWithDurationsRoundedUpAndOneCommandOnly(): void
    {
        $port = new ScriptedPort([new PortReply(false, self::accepted())]);
        $attempt = $this->ids()->attempt();
        $owner = $this->ids()->owner();
        $policy = new CircuitPolicy(
            ContractIdentities::identity(),
            new TimeCircuitWindow(Duration::nanoseconds(60_000_000_001), Duration::nanoseconds(1_000_000_000)),
            new PositiveInt(3),
            Ratio::of(2, 3),
            Duration::nanoseconds(30_000_000_001),
            new PositiveInt(2),
            new PositiveInt(1),
            Duration::nanoseconds(10_000_000_001),
        );
        $this->backend($port)->acquire($policy, $attempt, $owner, $this->ids()->command());

        // EVALSHA digest 6 keys..., then ARGV: identity, schema, window kind, window us, granularity us, minimum, ratio n and d,
        // open us, probes, threshold, lease us, fingerprint, attempt, owner, incarnation.
        $arguments = array_slice($port->sent[0], 3 + 6);
        self::assertSame(
            ['unit-identity', '1', 't', '60000001', '1000000', '3', '2', '3', '30000001', '2', '1', '10000001', 't:60000000001:1000000000|3|2|3|30000000001|2|1|10000000001', $attempt->lowerHex, $owner->lowerHex],
            array_slice($arguments, 0, 15),
        );
        self::assertMatchesRegularExpression('~^[0-9a-f]{32}\z~', $arguments[15]);
        self::assertCount(1, $port->sent);
    }

    public function testFinalizationRepliesMapToStatusesAndFactsAndCarryThePermitExactly(): void
    {
        $permit = $this->permit('p');
        foreach (['1' => FinalizationStatus::Applied, '2' => FinalizationStatus::AlreadyFinalized, '3' => FinalizationStatus::StaleOwnership] as $code => $expected) {
            $port = new ScriptedPort([new PortReply(false, [(string) $code, 'h', 'c', 'S'])]);
            $result = $this->backend($port)->record($this->policy(), $permit, CircuitVerdict::Failure, $this->ids()->command());
            self::assertSame($expected, $result->status);
            self::assertEquals(new CircuitTransition('half_open', 'closed', ReasonCode::CircuitProbesSucceeded->value), $result->transition);

            // ARGV after the 6 keys: the 13 settings values, then mode, verdict, attempt, owner, incarnation, generation, expiry s, expiry us, kind.
            $arguments = array_slice($port->sent[0], 3 + 6 + 13);
            self::assertSame(['record', 'f'], array_slice($arguments, 0, 2));
            self::assertSame([self::INCARNATION, '3', '1893456110', '250000', 'p'], array_slice($arguments, 4, 5));
        }
    }

    public function testVerdictLettersAndAbandon(): void
    {
        $permit = $this->permit();
        foreach ([[CircuitVerdict::Success, 's'], [CircuitVerdict::Neutral, 'n']] as [$verdict, $letter]) {
            $port = new ScriptedPort([new PortReply(false, ['1', '', '', ''])]);
            $this->backend($port)->record($this->policy(), $permit, $verdict, $this->ids()->command());
            self::assertSame(['record', $letter], array_slice($port->sent[0], 3 + 6 + 13, 2));
        }

        $port = new ScriptedPort([new PortReply(false, ['1', '', '', ''])]);
        $result = $this->backend($port)->abandon($this->policy(), $permit, $this->ids()->command());
        self::assertSame(['abandon', 'n'], array_slice($port->sent[0], 3 + 6 + 13, 2));
        self::assertNull($result->transition);
    }

    public function testAPermitOfAnotherPolicyIsStaleWithoutAnyCommand(): void
    {
        $permit = $this->permit();
        $other = ContractCircuitRig::countPolicy(window: 50, minimum: 5, probeCapacity: 2, threshold: 2, identity: ContractIdentities::identity('another'));
        $port = new ScriptedPort([]);

        self::assertSame(FinalizationStatus::StaleOwnership, $this->backend($port)->record($other, $permit, CircuitVerdict::Success, $this->ids()->command())->status);
        self::assertSame(FinalizationStatus::StaleOwnership, $this->backend($port)->abandon($other, $permit, $this->ids()->command())->status);
        self::assertSame([], $port->sent);
    }

    public function testATransportFailureAfterTheSendIsIndeterminateForEveryOperation(): void
    {
        $reset = new \RuntimeException('reset');
        $permit = $this->permit();
        foreach ([
            fn() => $this->backend(new ScriptedPort([$reset]))->acquire($this->policy(), $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command()),
            fn() => $this->backend(new ScriptedPort([$reset]))->record($this->policy(), $permit, CircuitVerdict::Failure, $this->ids()->command()),
            fn() => $this->backend(new ScriptedPort([$reset]))->abandon($this->policy(), $permit, $this->ids()->command()),
        ] as $call) {
            try {
                $call();
                self::fail('Expected an indeterminate outcome.');
            } catch (IndeterminateStateException $outcome) {
                self::assertInstanceOf(\RuntimeException::class, $outcome->getPrevious());
            }
        }
    }

    public function testACeilingRejectionIsACapacityException(): void
    {
        $this->expectException(StateCapacityExceededException::class);
        $this->backend(new ScriptedPort([new PortReply(true, 'RT_CAPACITY the state registry is at capacity')]))
            ->acquire($this->policy(), $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedAcquireReplies(): iterable
    {
        yield 'not a list' => ['1'];
        yield 'too short' => [['1', self::INCARNATION]];
        yield 'unknown status' => [['9', '', '', '', '', '', '', '', '']];
        yield 'unknown kind' => [['1', self::INCARNATION, '3', '1', '1', 'x', '', '', '']];
        yield 'bad incarnation' => [['1', 'NOT-HEX', '3', '1', '1', 'c', '', '', '']];
        yield 'zero generation' => [['1', self::INCARNATION, '0', '1', '1', 'c', '', '', '']];
        yield 'microseconds out of range' => [['1', self::INCARNATION, '1', '1', '1000000', 'c', '', '', '']];
        yield 'unknown state in the fact' => [['1', self::INCARNATION, '1', '1', '1', 'c', 'x', 'o', 'T']];
        yield 'unknown reason in the fact' => [['1', self::INCARNATION, '1', '1', '1', 'c', 'c', 'o', 'Z']];
        yield 'a fact that changes nothing' => [['1', self::INCARNATION, '1', '1', '1', 'c', 'c', 'c', 'T']];
        yield 'a number that is not text' => [['2', 5, '', '', '', '', '', '', '']];
        yield 'open without a wait' => [['2', '0', '', '', '', '', '', '', '']];
    }

    #[DataProvider('malformedAcquireReplies')]
    public function testAMalformedAcquireReplyIsCorruptState(mixed $reply): void
    {
        $this->expectException(CorruptStateException::class);
        $this->backend(new ScriptedPort([new PortReply(false, $reply)]))->acquire($this->policy(), $this->ids()->attempt(), $this->ids()->owner(), $this->ids()->command());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedFinalizationReplies(): iterable
    {
        yield 'not a list' => ['1'];
        yield 'three elements' => [['1', '', '']];
        yield 'unknown status' => [['7', '', '', '']];
        yield 'half a fact' => [['1', 'c', '', '']];
        yield 'not text' => [['1', 1, '', '']];
    }

    #[DataProvider('malformedFinalizationReplies')]
    public function testAMalformedFinalizationReplyIsCorruptState(mixed $reply): void
    {
        $permit = $this->permit();
        $this->expectException(CorruptStateException::class);
        $this->backend(new ScriptedPort([new PortReply(false, $reply)]))->record($this->policy(), $permit, CircuitVerdict::Success, $this->ids()->command());
    }
}
