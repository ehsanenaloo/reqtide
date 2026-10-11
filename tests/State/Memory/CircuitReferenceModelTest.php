<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\State\Memory;

use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\CircuitPermit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Seeded random operation sequences compared with a deliberately naive model: plain lists, full
 * history scans and no incremental counters. The model re-derives the CIR table from the spec text.
 */
final class CircuitReferenceModelTest extends TestCase
{
    private const S = CircuitRig::SECOND;

    /** @return iterable<string, array{int, bool, int, int}> */
    public static function configurations(): iterable
    {
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12] as $seed) {
            yield "count window seed $seed" => [$seed, false, 1, 1];
            yield "count window probes 3/2 seed $seed" => [$seed + 100, false, 3, 2];
            yield "time window seed $seed" => [$seed + 200, true, 1, 1];
            yield "time window probes 2/2 seed $seed" => [$seed + 300, true, 2, 2];
        }
    }

    #[DataProvider('configurations')]
    public function testBackendMatchesTheReferenceModel(int $seed, bool $timeWindow, int $probeCapacity, int $threshold): void
    {
        mt_srand($seed);
        $rig = new CircuitRig();
        $policy = $timeWindow
            ? CircuitRig::timePolicy(windowSeconds: 20, minimum: 3, ratioNumerator: 1, ratioDenominator: 2, probeCapacity: $probeCapacity, threshold: $threshold, openSeconds: 15, leaseSeconds: 6)
            : CircuitRig::countPolicy(window: 6, minimum: 3, ratioNumerator: 1, ratioDenominator: 2, probeCapacity: $probeCapacity, threshold: $threshold, openSeconds: 15, leaseSeconds: 6);
        $model = new ReferenceCircuit($timeWindow, 20 * self::S, 6, 3, 1, 2, 15 * self::S, $probeCapacity, $threshold, 6 * self::S);

        /** @var list<array{CircuitPermit, int}> $issued */
        $issued = [];
        $steps = [0, 1, 999_999_999, 1_000_000_000, 2_500_000_000, 5_999_999_999, 6_000_000_000, 14_999_999_999, 15_000_000_000, 61_000_000_000, 119_999_999_999, 120_000_000_000];

        for ($step = 0; $step < 400; ++$step) {
            $context = "seed $seed step $step";
            $roll = mt_rand(0, 99);
            if ($roll < 35) {
                $admission = $rig->acquire($policy);
                $expected = $model->acquire($rig->clock->now);
                self::assertSame($expected['status'], $admission->status, "$context acquire status");
                if ($expected['status'] === CircuitAdmissionStatus::Open) {
                    self::assertSame($expected['retryAfter'], $admission->retryAfter?->nanoseconds, "$context retryAfter");
                }
                if ($admission->permit !== null) {
                    self::assertSame($expected['kind'], $admission->permit->kind, "$context kind");
                    self::assertSame($expected['generation'], $admission->permit->generation->value, "$context generation");
                    $issued[] = [$admission->permit, $expected['id']];
                }
            } elseif ($roll < 80 && $issued !== []) {
                [$permit, $id] = $issued[mt_rand(0, count($issued) - 1)];
                $verdictRoll = mt_rand(0, 9);
                $now = $rig->clock->now;
                if ($verdictRoll < 4) {
                    $verdict = CircuitVerdict::Success;
                } elseif ($verdictRoll < 7) {
                    $verdict = CircuitVerdict::Failure;
                } elseif ($verdictRoll < 9) {
                    $verdict = CircuitVerdict::Neutral;
                } else {
                    $verdict = null;
                }
                $actual = $verdict === null ? $rig->abandon($policy, $permit) : $rig->record($policy, $permit, $verdict);
                self::assertSame($model->finalize($now, $id, $verdict), $actual, "$context finalize");
            } elseif ($roll < 82) {
                $next = $rig->backend->reset($policy->identity);
                self::assertSame($model->reset(), $next?->value, "$context reset");
            } else {
                $rig->advance($steps[mt_rand(0, count($steps) - 1)]);
            }
        }
    }
}
