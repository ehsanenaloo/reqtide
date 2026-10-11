<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Unit;

use Enaxon\Reqtide\Admission\AdmissionStatus;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Policy\FixedWindowAnchor;
use Enaxon\Reqtide\Policy\FixedWindowOptions;
use Enaxon\Reqtide\Policy\RateLimitPolicy;
use Enaxon\Reqtide\Policy\SlidingCounterOptions;
use Enaxon\Reqtide\Policy\TokenBucketOptions;
use Enaxon\Reqtide\Redis\Client\BridgeCapabilities;
use Enaxon\Reqtide\Redis\Client\PortReply;
use Enaxon\Reqtide\Redis\Client\RedisBridge;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisRateLimitBackend;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\Tests\Support\ScriptedPort;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Tests\Contract\ContractIdentities;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The Redis rate-limit backend against a scripted port: argument encoding, reply parsing, fault mapping. No server. */
final class RedisRateLimitReplyTest extends TestCase
{
    private ?ContractIdentities $identities = null;

    private function ids(): ContractIdentities
    {
        return $this->identities ??= new ContractIdentities();
    }

    private function backend(ScriptedPort $port): RedisRateLimitBackend
    {
        return new RedisRateLimitBackend(
            new RedisConnectionOptions('127.0.0.1', 1.0, 1.0),
            new RedisRegistry('unit', 'unit-identity', 'a-host-managed-secret-of-32-bytes!!'),
            new RedisBridge($port, new BridgeCapabilities(1.0)),
        );
    }

    private function bucket(int $capacity = 5, int $refill = 2, int $interval = 60_000_000_000): RateLimitPolicy
    {
        return new RateLimitPolicy(ContractIdentities::identity(), new TokenBucketOptions(new PositiveInt($capacity), new PositiveInt($refill), new Duration($interval)));
    }

    public function testCapabilitiesDeclareASharedAuthorityAFiniteTimeoutAndMicrosecondResolution(): void
    {
        $capabilities = $this->backend(new ScriptedPort([]))->capabilities();
        self::assertSame(StateScope::SharedAuthority, $capabilities->scope);
        self::assertSame(1_000_000_000, $capabilities->ioTimeout?->nanoseconds);
        self::assertSame(1_000, $capabilities->clockResolution->nanoseconds);
    }

    public function testAcceptedConsumeReportsWhatRemainsAfterTheCost(): void
    {
        $decision = $this->backend(new ScriptedPort([new PortReply(false, ['1', '5'])]))->consume($this->bucket(), new PositiveInt(2), $this->ids()->command());
        self::assertSame(AdmissionStatus::Accepted, $decision->status);
        self::assertSame(ReasonCode::RateAccepted->value, $decision->reasonCode);
        self::assertSame(3, $decision->remaining?->value);
        self::assertNull($decision->retryAfter);
    }

    public function testRejectedConsumeConvertsTheMicrosecondWaitToNanoseconds(): void
    {
        $decision = $this->backend(new ScriptedPort([new PortReply(false, ['2', '1', '600000', '0'])]))->consume($this->bucket(), new PositiveInt(2), $this->ids()->command());
        self::assertSame(AdmissionStatus::Rejected, $decision->status);
        self::assertSame(ReasonCode::RateExhausted->value, $decision->reasonCode);
        self::assertSame(1, $decision->remaining?->value);
        self::assertSame(600_000_000, $decision->retryAfter?->nanoseconds);
    }

    public function testAWaitThatDoesNotFitHasNoDurationAndItsOwnReason(): void
    {
        $decision = $this->backend(new ScriptedPort([new PortReply(false, ['2', '0', '9007199254740991', '1'])]))->consume($this->bucket(), new PositiveInt(2), $this->ids()->command());
        self::assertSame(ReasonCode::RateWaitUnrepresentable->value, $decision->reasonCode);
        self::assertNull($decision->retryAfter);
    }

    public function testACostAboveTheCapacityIsImpossibleWithoutAnyCommand(): void
    {
        $port = new ScriptedPort([]);
        $decision = $this->backend($port)->consume($this->bucket(5), new PositiveInt(PHP_INT_MAX), $this->ids()->command());
        self::assertSame(AdmissionStatus::Impossible, $decision->status);
        self::assertSame(ReasonCode::RateCostExceedsCapacity->value, $decision->reasonCode);
        self::assertNull($decision->remaining);
        self::assertSame([], $port->sent);
    }

    public function testInspectionRepliesBecomeInspections(): void
    {
        $accepted = $this->backend(new ScriptedPort([new PortReply(false, ['1', '4'])]))->inspect($this->bucket(), new PositiveInt(1));
        self::assertTrue($accepted->requestedCostWouldBeAccepted);
        self::assertSame(4, $accepted->available->value);
        self::assertNull($accepted->retryAfter);

        $rejected = $this->backend(new ScriptedPort([new PortReply(false, ['2', '0', '1500', '0'])]))->inspect($this->bucket(), new PositiveInt(1));
        self::assertFalse($rejected->requestedCostWouldBeAccepted);
        self::assertSame(1_500_000, $rejected->retryAfter?->nanoseconds);

        $impossible = $this->backend(new ScriptedPort([new PortReply(false, ['0', '5'])]))->inspect($this->bucket(5), new PositiveInt(6));
        self::assertFalse($impossible->requestedCostWouldBeAccepted);
        self::assertSame(5, $impossible->available->value);
        self::assertNull($impossible->retryAfter);
    }

    public function testInspectionOfAHugeCostAsksAboutTheNextCostAboveTheCapacityOnly(): void
    {
        $port = new ScriptedPort([new PortReply(false, ['0', '5'])]);
        $this->backend($port)->inspect($this->bucket(5), new PositiveInt(PHP_INT_MAX));
        // ARGV after the 4 keys: identity, schema, algorithm, fingerprint, capacity, a, b, cost.
        self::assertSame('6', $port->sent[0][3 + 4 + 7]);
    }

    public function testArgumentsEncodeTheAlgorithmWithDurationsRoundedUpToMicroseconds(): void
    {
        $cases = [
            'tb' => [$this->bucket(5, 2, 1_500), ['tb', 'token_bucket:5:2:1500', '5', '2', '2']],
            'fw' => [new RateLimitPolicy(ContractIdentities::identity(), new FixedWindowOptions(new PositiveInt(4), new Duration(10_000_000_001), FixedWindowAnchor::Aligned)), ['fw', 'fixed_window:4:10000000001:aligned', '4', '10000001', '0']],
            'fh' => [new RateLimitPolicy(ContractIdentities::identity(), new FixedWindowOptions(new PositiveInt(4), new Duration(10_000_000_000), FixedWindowAnchor::FirstHit)), ['fh', 'fixed_window:4:10000000000:first_hit', '4', '10000000', '0']],
            'sc' => [new RateLimitPolicy(ContractIdentities::identity(), new SlidingCounterOptions(new PositiveInt(7), new Duration(1_000))), ['sc', 'sliding_counter:7:1000', '7', '1', '0']],
        ];
        foreach ($cases as $name => [$policy, $expected]) {
            $port = new ScriptedPort([new PortReply(false, ['1', '9'])]);
            $this->backend($port)->consume($policy, new PositiveInt(1), $this->ids()->command());
            // EVALSHA digest 4 keys..., then ARGV: identity, schema, then the six values below.
            self::assertSame(['unit-identity', '1', ...$expected, '1'], array_slice($port->sent[0], 3 + 4), $name);
        }
    }

    public function testATransportFailureAfterTheSendIsIndeterminateForAConsumeAndUnavailableForAnInspection(): void
    {
        try {
            $this->backend(new ScriptedPort([new \RuntimeException('reset')]))->consume($this->bucket(), new PositiveInt(1), $this->ids()->command());
            self::fail('Expected an indeterminate outcome.');
        } catch (IndeterminateStateException $outcome) {
            self::assertInstanceOf(\RuntimeException::class, $outcome->getPrevious());
        }

        $this->expectException(StateUnavailableException::class);
        $this->backend(new ScriptedPort([new \RuntimeException('reset')]))->inspect($this->bucket(), new PositiveInt(1));
    }

    public function testACeilingRejectionIsACapacityException(): void
    {
        $this->expectException(StateCapacityExceededException::class);
        $this->backend(new ScriptedPort([new PortReply(true, 'RT_CAPACITY the state registry is at capacity')]))->consume($this->bucket(), new PositiveInt(1), $this->ids()->command());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedReplies(): iterable
    {
        yield 'not a list' => ['1'];
        yield 'empty' => [[]];
        yield 'unknown status' => [['9', '1']];
        yield 'accepted with a wait' => [['1', '1', '5', '0']];
        yield 'rejected without a wait' => [['2', '1']];
        yield 'rejected with a zero wait' => [['2', '1', '0', '0']];
        yield 'rejected with an unknown flag' => [['2', '1', '5', '7']];
        yield 'a number that is not text' => [['2', 1, '5', '0']];
        yield 'leading zero' => [['1', '05']];
        yield 'accepted with less available than the cost' => [['1', '0']];
        yield 'too long' => [['2', '1', '5', '0', '0']];
    }

    #[DataProvider('malformedReplies')]
    public function testAMalformedConsumeReplyIsCorruptState(mixed $reply): void
    {
        $this->expectException(CorruptStateException::class);
        $this->backend(new ScriptedPort([new PortReply(false, $reply)]))->consume($this->bucket(), new PositiveInt(1), $this->ids()->command());
    }

    public function testAMalformedInspectionReplyIsCorruptState(): void
    {
        $this->expectException(CorruptStateException::class);
        $this->backend(new ScriptedPort([new PortReply(false, ['3', '1'])]))->inspect($this->bucket(), new PositiveInt(1));
    }
}
