<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Clocked;

use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\Policy\CountCircuitWindow;
use Enaxon\Reqtide\Policy\TimeCircuitWindow;
use Enaxon\Reqtide\Redis\Tests\Support\RedisBackendHarness;
use Enaxon\Reqtide\State\CircuitAdmission;
use Enaxon\Reqtide\State\CircuitPermit;
use Enaxon\Reqtide\Tests\Contract\ContractIdentities;
use Enaxon\Reqtide\Tests\State\Memory\MemoryBackendHarness;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\Ratio;
use PHPUnit\Framework\TestCase;

/**
 * The differential run: the memory backend and the Redis backend are driven through the same seeded histories of
 * acquisitions, verdicts, abandons, repeated and stale finalizations, time steps and clock regressions, and must agree on
 * every admission status, permit kind and generation, every finalization status and every transition fact, and on every wait
 * up to the declared resolution (the Redis wait is the nanosecond wait rounded up to a whole microsecond). Durations are
 * whole milliseconds, and time windows use one-second buckets, which the two time axes place identically.
 */
final class CircuitDifferentialTest extends TestCase
{
    private const MILLISECOND = 1_000_000;

    private const SECOND = 1_000_000_000;

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
        mt_srand(20_261_012);
        $redis = RedisBackendHarness::create();
        $this->created[] = $redis;
        $memory = new MemoryBackendHarness();
        $ids = new ContractIdentities();
        $compared = 0;
        $transitions = 0;
        $time = 200 * self::SECOND;

        for ($case = 0; $case < 110; ++$case) {
            $policy = $this->policy($case);
            $this->moveBoth($redis, $memory, $time);
            $redisBackend = $redis->circuit();
            $memoryBackend = $memory->circuit();
            /** @var list<array{0: CircuitPermit, 1: CircuitPermit}> $held */
            $held = [];

            for ($step = 0; $step < 40; ++$step) {
                $roll = mt_rand(0, 99);
                $time = $roll < 6 && $time > 100 * self::SECOND
                    ? $time - mt_rand(1, 5_000) * self::MILLISECOND
                    : $time + mt_rand(0, 4_000) * self::MILLISECOND * ($roll % 7 === 0 ? 5 : 1);
                $this->moveBoth($redis, $memory, $time);
                $label = "case $case step $step";

                if ($held !== [] && $roll >= 50) {
                    [$memoryPermit, $redisPermit] = $held[array_rand($held)];
                    if ($roll >= 90) {
                        $expected = $memoryBackend->abandon($policy, $memoryPermit, $ids->command());
                        $actual = $redisBackend->abandon($policy, $redisPermit, $ids->command());
                    } else {
                        $verdict = $roll % 20 < 11 ? CircuitVerdict::Failure : ($roll % 20 < 17 ? CircuitVerdict::Success : CircuitVerdict::Neutral);
                        $expected = $memoryBackend->record($policy, $memoryPermit, $verdict, $ids->command());
                        $actual = $redisBackend->record($policy, $redisPermit, $verdict, $ids->command());
                    }
                    self::assertEquals($expected, $actual, "finalize $label");
                    $transitions += $expected->transition === null ? 0 : 1;
                    ++$compared;
                    continue;
                }

                $attempt = $ids->attempt();
                $owner = $ids->owner();
                $expected = $memoryBackend->acquire($policy, $attempt, $owner, $ids->command());
                $actual = $redisBackend->acquire($policy, $attempt, $owner, $ids->command());
                self::assertEquals(self::projected($expected), self::projected($actual, false), "acquire $label");
                $transitions += $expected->transition === null ? 0 : 1;
                if ($expected->permit !== null && $actual->permit !== null) {
                    $held[] = [$expected->permit, $actual->permit];
                }
                ++$compared;
            }
        }

        self::assertGreaterThan(3_000, $compared);
        self::assertGreaterThan(60, $transitions, 'the histories must move the circuit through its states (' . $transitions . ')');
    }

    private function policy(int $case): CircuitPolicy
    {
        $countWindow = $case % 2 === 0;
        if ($countWindow) {
            $samples = mt_rand(2, 8);
            $window = new CountCircuitWindow(new PositiveInt($samples));
            $minimum = mt_rand(1, min(3, $samples));
        } else {
            $window = new TimeCircuitWindow(Duration::seconds(mt_rand(3, 20)), Duration::seconds(1));
            $minimum = mt_rand(1, 3);
        }
        $probes = mt_rand(1, 3);

        return new CircuitPolicy(
            ContractIdentities::identity('case' . $case),
            $window,
            new PositiveInt($minimum),
            [Ratio::of(1, 2), Ratio::of(1, 3), Ratio::of(2, 3), Ratio::of(1, 1)][mt_rand(0, 3)],
            Duration::nanoseconds(mt_rand(1, 8) * self::SECOND),
            new PositiveInt($probes),
            new PositiveInt(mt_rand(1, $probes)),
            Duration::nanoseconds(mt_rand(2, 10) * self::SECOND),
        );
    }

    /**
     * The comparable part of an admission: status, kind, generation, fact and the wait rounded up to a microsecond when
     * the nanosecond backend produced it. The permit identity and expiry differ by construction (local and shared).
     *
     * @return array<string, mixed>
     */
    private static function projected(CircuitAdmission $admission, bool $roundUp = true): array
    {
        $wait = $admission->retryAfter?->nanoseconds;
        if ($wait !== null && $roundUp) {
            $wait = intdiv($wait + 999, 1_000) * 1_000;
        }

        return [
            'status' => $admission->status,
            'kind' => $admission->permit?->kind,
            'generation' => $admission->permit?->generation->value,
            'wait' => $wait,
            'transition' => $admission->transition,
        ];
    }

    private function moveBoth(RedisBackendHarness $redis, MemoryBackendHarness $memory, int $time): void
    {
        $redis->time()->moveTo($time);
        $memory->time()->moveTo($time);
    }
}
