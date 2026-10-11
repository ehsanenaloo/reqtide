<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Clocked;

use Enaxon\Reqtide\Admission\AdmissionStatus;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\StateCapacityExceededException;
use Enaxon\Reqtide\Policy\FixedWindowAnchor;
use Enaxon\Reqtide\Policy\FixedWindowOptions;
use Enaxon\Reqtide\Policy\RateLimitPolicy;
use Enaxon\Reqtide\Policy\SlidingCounterOptions;
use Enaxon\Reqtide\Policy\TokenBucketOptions;
use Enaxon\Reqtide\Redis\RedisRateLimitBackend;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRegistryAdmin;
use Enaxon\Reqtide\Redis\Tests\Support\RedisBackendHarness;
use Enaxon\Reqtide\Tests\Contract\ContractIdentities;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\TestCase;
use Predis\Response\Status;

/**
 * What only a shared store can show for the rate limits: backends over one
 * registry share one state, inspection writes nothing at all, the partition counter follows allocation and reclamation,
 * a wait that does not fit the exact range is reported as such, and the settings stay bound in nanoseconds.
 */
final class RedisRateLimitStateTest extends TestCase
{
    private const SECOND = 1_000_000_000;

    private ?RedisBackendHarness $harness = null;

    private ?ContractIdentities $identities = null;

    protected function setUp(): void
    {
        $this->harness()->time()->moveTo(1_000 * self::SECOND);
    }

    protected function tearDown(): void
    {
        $this->harness?->dispose();
    }

    private function harness(): RedisBackendHarness
    {
        return $this->harness ??= RedisBackendHarness::create();
    }

    private function ids(): ContractIdentities
    {
        return $this->identities ??= new ContractIdentities();
    }

    private function backend(RedisRegistry $registry): RedisRateLimitBackend
    {
        return new RedisRateLimitBackend($this->harness()->options(), $registry);
    }

    private function bucket(int $capacity, int $refill, int $intervalNanoseconds, string $subject = 'subject'): RateLimitPolicy
    {
        return new RateLimitPolicy(
            ContractIdentities::identity($subject),
            new TokenBucketOptions(new PositiveInt($capacity), new PositiveInt($refill), new Duration($intervalNanoseconds)),
        );
    }

    /** @return array<string, mixed> every key of the registry with its complete content, for before/after comparison */
    private function snapshot(RedisRegistry $registry): array
    {
        $client = $this->harness()->client();
        /** @var list<string> $keys */
        $keys = $client->keys($registry->keyPrefix . ':*');
        sort($keys);
        $content = [];
        foreach ($keys as $key) {
            $reply = $client->type($key);
            $type = $reply instanceof Status ? $reply->getPayload() : (is_string($reply) ? $reply : '');
            $content[$key] = match ($type) {
                'hash' => $client->hgetall($key),
                'zset' => $client->zrange($key, 0, -1, ['withscores' => true]),
                'list' => $client->lrange($key, 0, -1),
                default => $type,
            };
        }

        return $content;
    }

    public function testTwoInstancesOverOneRegistryShareOneBucket(): void
    {
        $registry = $this->harness()->registry();
        $first = $this->backend($registry);
        $second = $this->backend($registry);
        $policy = $this->bucket(3, 1, 60 * self::SECOND);

        self::assertSame(AdmissionStatus::Accepted, $first->consume($policy, new PositiveInt(2), $this->ids()->command())->status);
        $rejected = $second->consume($policy, new PositiveInt(2), $this->ids()->command());
        self::assertSame(AdmissionStatus::Rejected, $rejected->status);
        self::assertSame(1, $rejected->remaining?->value);
        self::assertSame(1, $second->inspect($policy, new PositiveInt(1))->available->value);
    }

    public function testInspectionWritesNothingForAnAbsentOrAnExistingPartition(): void
    {
        $registry = $this->harness()->registry();
        $backend = $this->backend($registry);
        $policies = [
            $this->bucket(5, 5, 10 * self::SECOND, 'bucket'),
            new RateLimitPolicy(ContractIdentities::identity('fixed'), new FixedWindowOptions(new PositiveInt(5), new Duration(10 * self::SECOND), FixedWindowAnchor::Aligned)),
            new RateLimitPolicy(ContractIdentities::identity('first'), new FixedWindowOptions(new PositiveInt(5), new Duration(10 * self::SECOND), FixedWindowAnchor::FirstHit)),
            new RateLimitPolicy(ContractIdentities::identity('sliding'), new SlidingCounterOptions(new PositiveInt(5), new Duration(10 * self::SECOND))),
        ];

        $empty = $this->snapshot($registry);
        foreach ($policies as $policy) {
            $backend->inspect($policy, new PositiveInt(1));
            $backend->inspect($policy, new PositiveInt(6));
        }
        self::assertSame($empty, $this->snapshot($registry), 'inspecting absent partitions creates no key and changes no field');

        foreach ($policies as $policy) {
            $backend->consume($policy, new PositiveInt(5), $this->ids()->command());
        }
        $this->harness()->time()->advance(3 * self::SECOND);
        $before = $this->snapshot($registry);
        foreach ($policies as $policy) {
            $backend->inspect($policy, new PositiveInt(1));
            $backend->inspect($policy, new PositiveInt(5));
            $backend->inspect($policy, new PositiveInt(6));
        }
        self::assertSame($before, $this->snapshot($registry), 'inspecting existing partitions changes nothing, not even the watermark');
    }

    public function testThePartitionCounterFollowsAllocationRejectionAndReclamation(): void
    {
        $registry = $this->harness()->registry(2);
        $backend = $this->backend($registry);
        $admin = new RedisRegistryAdmin($this->harness()->options(), $registry);
        $policy = static fn(string $subject): RateLimitPolicy => new RateLimitPolicy(
            ContractIdentities::identity($subject),
            new TokenBucketOptions(new PositiveInt(1), new PositiveInt(1), new Duration(60 * self::SECOND)),
        );

        $backend->consume($policy('a'), new PositiveInt(1), $this->ids()->command());
        $backend->consume($policy('b'), new PositiveInt(1), $this->ids()->command());
        self::assertSame(2, $admin->verify()->partitions);

        try {
            $backend->consume($policy('c'), new PositiveInt(1), $this->ids()->command());
            self::fail('Expected a capacity rejection.');
        } catch (StateCapacityExceededException) {
        }
        self::assertSame(2, $admin->verify()->partitions, 'a rejected allocation changes nothing');
        self::assertSame(AdmissionStatus::Rejected, $backend->consume($policy('a'), new PositiveInt(1), $this->ids()->command())->status);

        // Both buckets are full again after a minute but a fresh-equivalent partition is reclaimed only after 120 seconds idle.
        $this->harness()->time()->advance(90 * self::SECOND);
        try {
            $backend->consume($policy('c'), new PositiveInt(1), $this->ids()->command());
            self::fail('Expected a capacity rejection: the partitions are not yet idle for 120 seconds.');
        } catch (StateCapacityExceededException) {
        }

        $this->harness()->time()->advance(300 * self::SECOND);
        self::assertSame(AdmissionStatus::Accepted, $backend->consume($policy('c'), new PositiveInt(1), $this->ids()->command())->status);
        self::assertSame(1, $admin->verify()->partitions, 'both idle partitions were reclaimed, the new one allocated');
        self::assertCount(1, $this->harness()->client()->keys($registry->keyPrefix . ':p:rl:*'), 'the reclaimed keys are gone');
    }

    public function testRetentionIsExactToTheMicrosecondNotToTheSecond(): void
    {
        $registry = $this->harness()->registry(1);
        $backend = $this->backend($registry);
        $time = $this->harness()->time();
        $policy = $this->bucket(1, 1, 60 * self::SECOND, 'a');
        $other = $this->bucket(1, 1, 60 * self::SECOND, 'b');

        $time->moveTo(1_000 * self::SECOND + 500_000_000);
        $backend->consume($policy, new PositiveInt(1), $this->ids()->command());

        // The partition is full again (a minute passed) and its examination second has come, but it was idle for 119.7 s.
        $time->moveTo(1_120 * self::SECOND + 200_000_000);
        try {
            $backend->consume($other, new PositiveInt(1), $this->ids()->command());
            self::fail('Expected a capacity rejection: the partition has not been idle for 120 seconds yet.');
        } catch (StateCapacityExceededException $rejection) {
            self::assertStringContainsString('rt_capacity', $rejection->getMessage());
        }

        $time->moveTo(1_120 * self::SECOND + 500_000_000); // exactly 120 s after the last write
        self::assertSame(AdmissionStatus::Accepted, $backend->consume($other, new PositiveInt(1), $this->ids()->command())->status);
    }

    public function testDrainedPartitionsAtTheHeadOfTheIndexDoNotStarveAReclaimableOne(): void
    {
        // 129 drained buckets (still protective) are examined before the one that is only idle; a batch is 128 candidates.
        $registry = $this->harness()->registry(130);
        $backend = $this->backend($registry);
        $time = $this->harness()->time();
        $drained = fn(string $subject): RateLimitPolicy => $this->bucket(1, 1, 100_000 * self::SECOND, $subject);

        for ($i = 0; $i < 129; ++$i) {
            $backend->consume($drained('drained' . $i), new PositiveInt(1), $this->ids()->command());
        }
        $time->advance(1 * self::SECOND);
        $backend->consume($this->bucket(1, 1, 10 * self::SECOND, 'idle'), new PositiveInt(1), $this->ids()->command());

        $time->advance(300 * self::SECOND);
        $newcomer = $this->bucket(1, 1, 10 * self::SECOND, 'newcomer');
        // The first attempts examine drained partitions and move them back; within a few attempts the idle one is reached.
        $rejections = 0;
        $admitted = false;
        for ($attempt = 0; $attempt < 3 && !$admitted; ++$attempt) {
            try {
                $backend->consume($newcomer, new PositiveInt(1), $this->ids()->command());
                $admitted = true;
            } catch (StateCapacityExceededException) {
                ++$rejections;
            }
        }
        self::assertTrue($admitted, 'a reclaimable partition behind 129 protective ones is reached within three attempts');
        self::assertGreaterThan(0, $rejections, 'the first attempt only examined drained partitions');
        self::assertSame(AdmissionStatus::Rejected, $backend->consume($drained('drained0'), new PositiveInt(1), $this->ids()->command())->status, 'the protective partitions were never evicted');
    }

    public function testAWaitBeyondTheExactRangeIsReportedAsUnrepresentableNotTruncated(): void
    {
        $backend = $this->backend($this->harness()->registry());
        // A bucket that refills one token over about 285 years: two missing tokens take longer than the exact range.
        $policy = $this->bucket(9_007_199_254, 1, 9_007_199_254_740_991_000);
        self::assertSame(AdmissionStatus::Accepted, $backend->consume($policy, new PositiveInt(9_007_199_254), $this->ids()->command())->status);

        $decision = $backend->consume($policy, new PositiveInt(2), $this->ids()->command());
        self::assertSame(AdmissionStatus::Rejected, $decision->status);
        self::assertSame(ReasonCode::RateWaitUnrepresentable->value, $decision->reasonCode);
        self::assertNull($decision->retryAfter);
        self::assertSame(0, $decision->remaining?->value);
    }

    public function testSettingsStayBoundInNanosecondsEvenWhenTheyRoundToTheSameMicroseconds(): void
    {
        $backend = $this->backend($this->harness()->registry());
        $backend->consume($this->bucket(3, 1, 60 * self::SECOND), new PositiveInt(1), $this->ids()->command());

        $this->expectException(\Enaxon\Reqtide\Exception\InvalidConfigurationException::class);
        $backend->consume($this->bucket(3, 1, 60 * self::SECOND + 1), new PositiveInt(1), $this->ids()->command());
    }

    public function testAnIntervalRoundsUpToWholeMicrosecondsSoTheRefillIsNeverFaster(): void
    {
        $backend = $this->backend($this->harness()->registry());
        // One token per 1,500 ns is treated as one token per 2 us: after 1 us nothing, after 2 us one token.
        $policy = $this->bucket(1, 1, 1_500);
        self::assertSame(AdmissionStatus::Accepted, $backend->consume($policy, new PositiveInt(1), $this->ids()->command())->status);

        $this->harness()->time()->advance(1_000);
        $early = $backend->consume($policy, new PositiveInt(1), $this->ids()->command());
        self::assertSame(AdmissionStatus::Rejected, $early->status);
        self::assertSame(1_000, $early->retryAfter?->nanoseconds);

        $this->harness()->time()->advance(1_000);
        self::assertSame(AdmissionStatus::Accepted, $backend->consume($policy, new PositiveInt(1), $this->ids()->command())->status);
    }
}
