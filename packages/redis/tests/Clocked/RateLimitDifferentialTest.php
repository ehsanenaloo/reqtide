<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Clocked;

use Enaxon\Reqtide\Admission\AdmissionDecision;
use Enaxon\Reqtide\Admission\AdmissionStatus;
use Enaxon\Reqtide\Admission\RateLimitInspection;
use Enaxon\Reqtide\Policy\FixedWindowAnchor;
use Enaxon\Reqtide\Policy\FixedWindowOptions;
use Enaxon\Reqtide\Policy\RateLimitPolicy;
use Enaxon\Reqtide\Policy\SlidingCounterOptions;
use Enaxon\Reqtide\Policy\TokenBucketOptions;
use Enaxon\Reqtide\Redis\Tests\Support\RedisBackendHarness;
use Enaxon\Reqtide\Tests\Contract\ContractIdentities;
use Enaxon\Reqtide\Tests\State\Memory\MemoryBackendHarness;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\TestCase;

/**
 * The differential run: the memory backend (nanosecond ticks, rational arithmetic in PHP) and the Redis backend
 * (microsecond ticks, exact limb arithmetic in Lua, server clock) are driven through the same seeded histories of cost,
 * time steps and clock regressions, and must agree on every decision and remaining amount, and on every wait up to the
 * declared resolution (the Redis wait is the nanosecond wait rounded up to a whole microsecond, never early). Intervals are whole
 * microseconds, where the declared difference of resolution does not show.
 */
final class RateLimitDifferentialTest extends TestCase
{
    private const MICROSECOND = 1_000;

    /** Window lengths in microseconds that divide 86,400 seconds. */
    private const DAY_DIVISORS_MICROSECONDS = [1_000, 2_000, 5_000, 8_000, 25_000, 40_000, 100_000, 1_000_000, 6_000_000, 30_000_000, 60_000_000, 3_600_000_000];

    /** @var list<RedisBackendHarness> */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $harness) {
            $harness->dispose();
        }
    }

    public function testRedisAndMemoryAgreeOnSeededHistories(): void
    {
        mt_srand(20_261_010);
        $redis = RedisBackendHarness::create();
        $this->created[] = $redis;
        $memory = new MemoryBackendHarness();
        $ids = new ContractIdentities();
        $compared = 0;
        $time = 0;

        for ($case = 0; $case < 120; ++$case) {
            $capacity = mt_rand(1, 12);
            // Aligned windows sit on the Unix epoch axis; the memory backend's axis starts at epoch zero and the Redis lane's at a
            // day boundary, so a window that is aligned (fixed aligned, sliding) must divide a day to be placed identically.
            $interval = ($case % 3 === 0 ? mt_rand(1, 300) * 1_000_000 : mt_rand(1, 5_000)) * self::MICROSECOND;
            if ($case % 4 === 1 || $case % 4 === 3) {
                $interval = self::DAY_DIVISORS_MICROSECONDS[array_rand(self::DAY_DIVISORS_MICROSECONDS)] * self::MICROSECOND;
            }
            $options = match ($case % 4) {
                0 => new TokenBucketOptions(new PositiveInt($capacity), new PositiveInt(mt_rand(1, 12)), new Duration($interval)),
                1 => new FixedWindowOptions(new PositiveInt($capacity), new Duration($interval), FixedWindowAnchor::Aligned),
                2 => new FixedWindowOptions(new PositiveInt($capacity), new Duration($interval), FixedWindowAnchor::FirstHit),
                default => new SlidingCounterOptions(new PositiveInt($capacity), new Duration($interval)),
            };
            $policy = new RateLimitPolicy(ContractIdentities::identity('case' . $case), $options);
            $this->moveBoth($redis, $memory, $time);
            $redisBackend = $redis->rateLimit();
            $memoryBackend = $memory->rateLimit();
            $trace = [];

            for ($step = 0; $step < 30; ++$step) {
                $roll = mt_rand(0, 99);
                if ($roll < 8 && $time > 2 * $interval) {
                    $time -= mt_rand(1, 2 * intdiv($interval, self::MICROSECOND)) * self::MICROSECOND; // a clock regression
                } else {
                    $time += mt_rand(0, 2 * intdiv($interval, self::MICROSECOND)) * self::MICROSECOND;
                }
                $this->moveBoth($redis, $memory, $time);
                $cost = new PositiveInt(mt_rand(1, $capacity + ($step % 9 === 0 ? 1 : 0)));

                if ($roll % 3 === 0) {
                    self::assertEquals(
                        self::inspectionAtMicrosecondResolution($memoryBackend->inspect($policy, $cost)),
                        $redisBackend->inspect($policy, $cost),
                        "inspect case $case step $step",
                    );
                    ++$compared;
                }
                $expected = $memoryBackend->consume($policy, $cost, $ids->command());
                $actual = $redisBackend->consume($policy, $cost, $ids->command());
                self::assertEquals(self::atMicrosecondResolution($expected), $actual, "consume case $case step $step (" . $options::class . ', cost ' . $cost->value . ', time ' . $time . ', interval ' . $interval . ', roll ' . $roll . ', history ' . implode(' ', $trace) . ')');
                $trace[] = ($expected->status === AdmissionStatus::Accepted ? 'A' : 'R') . $cost->value . '@' . $time;
                ++$compared;
            }
        }

        self::assertGreaterThan(3_000, $compared);
    }

    private function moveBoth(RedisBackendHarness $redis, MemoryBackendHarness $memory, int $time): void
    {
        $redis->time()->moveTo($time);
        $memory->time()->moveTo($time);
    }

    /** The wait of the nanosecond backend rounded up to the microsecond resolution of the shared one: never early. */
    private static function roundedUp(?Duration $wait): ?Duration
    {
        return $wait === null ? null : Duration::nanoseconds(intdiv($wait->nanoseconds + self::MICROSECOND - 1, self::MICROSECOND) * self::MICROSECOND);
    }

    private static function atMicrosecondResolution(AdmissionDecision $decision): AdmissionDecision
    {
        return new AdmissionDecision($decision->status, $decision->reasonCode, $decision->remaining, self::roundedUp($decision->retryAfter));
    }

    private static function inspectionAtMicrosecondResolution(RateLimitInspection $inspection): RateLimitInspection
    {
        return new RateLimitInspection($inspection->available, $inspection->requestedCostWouldBeAccepted, self::roundedUp($inspection->retryAfter));
    }
}
