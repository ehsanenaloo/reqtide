<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Clocked;

use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Redis\Tests\Support\RedisBackendHarness;
use Enaxon\Reqtide\State\RetryBudgetInspection;
use Enaxon\Reqtide\State\RetryBudgetReservation;
use Enaxon\Reqtide\State\RetryReservationDecision;
use Enaxon\Reqtide\State\RetryReservationStatus;
use Enaxon\Reqtide\Tests\Contract\ContractIdentities;
use Enaxon\Reqtide\Tests\State\Memory\MemoryBackendHarness;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\TestCase;

/**
 * The differential run: the memory backend and the Redis backend are driven through the same seeded histories of
 * reservations, commits, releases (also repeated and stale ones), inspections, time steps and clock regressions, and must
 * agree on every status, every inspection and every wait up to the declared resolution (the Redis wait is the nanosecond
 * wait rounded up to a whole microsecond, never early). Intervals and leases are whole microseconds.
 */
final class RetryBudgetDifferentialTest extends TestCase
{
    private const MICROSECOND = 1_000;

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
        mt_srand(20_261_011);
        $redis = RedisBackendHarness::create();
        $this->created[] = $redis;
        $memory = new MemoryBackendHarness();
        $ids = new ContractIdentities();
        $compared = 0;
        $time = 0;

        for ($case = 0; $case < 80; ++$case) {
            $capacity = mt_rand(1, 6);
            $interval = mt_rand(1, 400) * 1_000 * self::MICROSECOND;
            $lease = mt_rand(1, 60) * 1_000 * self::MICROSECOND;
            $policy = new RetryBudgetPolicy(
                ContractIdentities::identity('case' . $case),
                new PositiveInt($capacity),
                new PositiveInt(mt_rand(1, 6)),
                new Duration($interval),
                new Duration($lease),
            );
            $this->moveBoth($redis, $memory, $time);
            $redisBackend = $redis->retryBudget();
            $memoryBackend = $memory->retryBudget();
            /** @var list<array{0: RetryBudgetReservation, 1: RetryBudgetReservation}> $held */
            $held = [];

            for ($step = 0; $step < 30; ++$step) {
                $roll = mt_rand(0, 99);
                if ($roll < 8 && $time > 2 * $lease) {
                    $time -= mt_rand(1, intdiv($lease, self::MICROSECOND)) * self::MICROSECOND; // a clock regression
                } else {
                    $time += mt_rand(0, intdiv($interval + $lease, self::MICROSECOND)) * self::MICROSECOND;
                }
                $this->moveBoth($redis, $memory, $time);
                $label = "case $case step $step";

                if ($roll % 5 === 0) {
                    self::assertEquals(
                        self::inspectionAtMicrosecondResolution($memoryBackend->inspect($policy)),
                        $redisBackend->inspect($policy),
                        "inspect $label",
                    );
                    ++$compared;
                }

                if ($held !== [] && $roll >= 45) {
                    [$memoryReservation, $redisReservation] = $held[array_rand($held)];
                    $commit = $roll % 2 === 0;
                    $expected = $commit
                        ? $memoryBackend->commit($policy, $memoryReservation, $ids->command())
                        : $memoryBackend->release($policy, $memoryReservation, $ids->command());
                    $actual = $commit
                        ? $redisBackend->commit($policy, $redisReservation, $ids->command())
                        : $redisBackend->release($policy, $redisReservation, $ids->command());
                    self::assertEquals($expected, $actual, ($commit ? 'commit' : 'release') . " $label");
                    ++$compared;
                    continue;
                }

                $attempt = $ids->attempt();
                $owner = $ids->owner();
                $expected = $memoryBackend->reserve($policy, $attempt, $owner, $ids->command());
                $actual = $redisBackend->reserve($policy, $attempt, $owner, $ids->command());
                self::assertSame($expected->status, $actual->status, "reserve $label");
                self::assertEquals(self::waitAtMicrosecondResolution($expected), $actual->retryAfter, "reserve wait $label");
                if ($expected->status === RetryReservationStatus::Reserved && $expected->reservation !== null && $actual->reservation !== null) {
                    $held[] = [$expected->reservation, $actual->reservation];
                }
                ++$compared;
            }
        }

        self::assertGreaterThan(2_000, $compared);
    }

    private function moveBoth(RedisBackendHarness $redis, MemoryBackendHarness $memory, int $time): void
    {
        $redis->time()->moveTo($time);
        $memory->time()->moveTo($time);
    }

    private static function roundedUp(?Duration $wait): ?Duration
    {
        return $wait === null ? null : Duration::nanoseconds(intdiv($wait->nanoseconds + self::MICROSECOND - 1, self::MICROSECOND) * self::MICROSECOND);
    }

    private static function waitAtMicrosecondResolution(RetryReservationDecision $decision): ?Duration
    {
        return self::roundedUp($decision->retryAfter);
    }

    private static function inspectionAtMicrosecondResolution(RetryBudgetInspection $inspection): RetryBudgetInspection
    {
        return new RetryBudgetInspection($inspection->availableTokens, $inspection->wouldReserve, self::roundedUp($inspection->retryAfter));
    }
}
