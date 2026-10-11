<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Completion\Diagnostic;
use Enaxon\Reqtide\Completion\ExecutionCompletion;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\ExecutionContext;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Tests\Pipeline\Support\DispatchSpy;
use Enaxon\Reqtide\Tests\Pipeline\Support\Harness;
use Enaxon\Reqtide\Time\Cancellation;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;

/** Defects found by a whole-codebase audit, each pinned by the sequence that exposed it. */
final class AuditHardeningTest extends TestCase
{
    /**
     * @param ExecutionCompletion<mixed> $completion
     * @return list<string>
     */
    private static function reasons(ExecutionCompletion $completion): array
    {
        return array_map(static fn(Diagnostic $d): string => $d->reasonCode, $completion->diagnostics);
    }

    private static function failing(): \RuntimeException
    {
        return new \RuntimeException('transient');
    }

    /** @param ExecutionContext<mixed> $context */
    private static function withCancellation(ExecutionContext $context, Cancellation $cancellation): ExecutionContext
    {
        return new ExecutionContext($context->operationId, $context->partition, $context->deadline, $cancellation, $context->metadata, $context->fallback);
    }

    public function testACooldownExtensionIsCappedAtThePolicyMaximumWhileTheOperationKeepsItsOwnWait(): void
    {
        $h = new Harness(false, false, false, true, true);
        $h->classifier->retryAfter = Duration::seconds(86_400 * 365);
        $spy = DispatchSpy::of($h->log, static function (): never {
            throw self::failing();
        });

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));

        self::assertEquals([Duration::seconds(CooldownPolicy::DEFAULT_MAXIMUM_EXTENSION_SECONDS)], $h->cooldown->extensions);
        self::assertContains('cooldown_extension_capped', self::reasons($completion));
    }

    public function testAnExtensionWithinTheMaximumIsPassedOnUnchangedAndLeavesNoDiagnostic(): void
    {
        $h = new Harness(false, false, false, true, true);
        $h->classifier->retryAfter = Duration::seconds(30);
        $spy = DispatchSpy::of($h->log, static function (): never {
            throw self::failing();
        });

        $completion = $h->pipeline()->executeDetailed($spy->callable(), $h->context(Duration::seconds(5)));

        self::assertEquals([Duration::seconds(30)], $h->cooldown->extensions);
        self::assertNotContains('cooldown_extension_capped', self::reasons($completion));
    }

    public function testTheCooldownMaximumMustBePositiveAndDefaultsToOneHour(): void
    {
        $identity = Harness::identity('cooldown');
        self::assertEquals(Duration::seconds(3600), (new CooldownPolicy($identity, Duration::seconds(1)))->maximumExtension);
        $this->expectException(InvalidConfigurationException::class);
        new CooldownPolicy($identity, Duration::seconds(1), Duration::zero());
    }

    public function testACancellationThatCannotBeSampledAfterTheSettlementKeepsTheBusinessResult(): void
    {
        $h = new Harness(false, false, false, true);
        $h->classifier->retryAfter = null;
        $cancellation = new class implements Cancellation {
            private int $calls = 0;

            public function isRequested(): bool
            {
                if (++$this->calls >= 3) {
                    throw new \RuntimeException('sampler broke');
                }

                return false;
            }
        };
        $spy = DispatchSpy::of($h->log, static function (): never {
            throw self::failing();
        });
        $context = self::withCancellation($h->context(Duration::seconds(30), null, Harness::replayable()), $cancellation);

        $completion = $h->pipeline(Harness::retry(2))->executeDetailed($spy->callable(), $context);

        self::assertContains('cancellation_sample_failed', self::reasons($completion));
        self::assertNotSame(CompletionDisposition::Cancelled, $completion->disposition);
    }

    public function testARandomSourceThatThrowsDuringJitterLosesNeitherTheResultNorThePermits(): void
    {
        $h = new Harness(false, false, false, true);
        $h->random->failure = new \RuntimeException('entropy gone');
        $spy = DispatchSpy::of($h->log, static function (): never {
            throw self::failing();
        }, static function (): string {
            return 'second attempt';
        });
        $pipeline = $h->pipeline(Harness::retry(2, 100, 10_000, BackoffMode::Constant, JitterMode::Full));

        $completion = $pipeline->executeDetailed($spy->callable(), $h->context(Duration::seconds(30), null, Harness::replayable()));

        self::assertContains('random_out_of_range', self::reasons($completion));
    }
}
