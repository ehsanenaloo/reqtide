<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Completion\CompletionDisposition;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Exception\AdmissionDeferredException;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Exception\ReqtideException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\ExecutionContext;
use Enaxon\Reqtide\Fallback\FallbackOperation;
use Enaxon\Reqtide\Fallback\FallbackScope;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Pipeline\CompiledPipeline;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\Ratio;
use PHPUnit\Framework\TestCase;

/** Builder, in-memory environment, classifier and context factory working together over the real memory backends. */
final class BuilderEndToEndTest extends TestCase
{
    private static function builder(): PipelineBuilder
    {
        return PipelineBuilder::profile('e2e', 'v1')
            ->partition('app', 'test', 'subject')
            ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative);
    }

    /** @return \Closure(AttemptContext): string */
    private static function failing(): \Closure
    {
        return static fn(AttemptContext $attempt): string => self::down(new \RuntimeException('upstream down'));
    }

    /** Throws, but is declared to return a string, so the analyser types the callables below as returning one. */
    private static function down(\Throwable $failure): string
    {
        throw $failure;
    }

    private static function circuit(PipelineBuilder $builder): PipelineBuilder
    {
        return $builder->circuitBreaker(PipelineBuilder::lastCalls(2), 2, Ratio::of(1, 2), Duration::seconds(30), 1, 1, Duration::seconds(10));
    }

    public function testFailuresOpenTheCircuitAndTheRefusalNamesTheOperation(): void
    {
        $pipeline = self::circuit(self::builder())->compileInMemory(ExceptionClassifier::create());
        $calls = 0;
        $work = static function (AttemptContext $attempt) use (&$calls): string {
            ++$calls;

            return self::down(new \RuntimeException('upstream down'));
        };

        for ($i = 0; $i < 2; ++$i) {
            try {
                $pipeline->execute($work, $pipeline->newContext(Duration::seconds(30)));
                self::fail('The callable always fails.');
            } catch (\RuntimeException $e) {
                self::assertSame('upstream down', $e->getMessage(), 'a closed circuit passes the business exception through');
            }
        }
        try {
            $pipeline->execute($work, $pipeline->newContext(Duration::seconds(30), operationId: 'third-call'));
            self::fail('The open circuit must refuse the third call.');
        } catch (AdmissionRejectedException $refusal) {
            $problem = $refusal->problem();
            self::assertSame(ReasonCode::CircuitOpen, $problem->code);
            self::assertSame('third-call', $problem->operationId?->value);
            self::assertSame('v1', $problem->profileVersion?->value);
        }
        self::assertSame(2, $calls, 'the refused call never reached the callable');
    }

    public function testExceptionsIgnoredForTheCircuitNeverOpenIt(): void
    {
        $pipeline = self::circuit(self::builder())->compileInMemory(ExceptionClassifier::create()->ignoreForCircuit(\InvalidArgumentException::class));
        $calls = 0;
        $work = static function (AttemptContext $attempt) use (&$calls): string {
            ++$calls;

            return self::down(new \InvalidArgumentException('caller mistake'));
        };

        for ($i = 0; $i < 6; ++$i) {
            try {
                $pipeline->execute($work, $pipeline->newContext(Duration::seconds(30)));
                self::fail('The callable always fails.');
            } catch (\InvalidArgumentException) {
                // the circuit stays closed: every call reaches the callable
            }
        }

        self::assertSame(6, $calls);
    }

    public function testAnExhaustedTokenBucketRefusesWithAWaitingProblem(): void
    {
        $pipeline = self::builder()
            ->rateLimit(PipelineBuilder::tokenBucket(1, 1, Duration::seconds(3600)))
            ->compileInMemory(ExceptionClassifier::create());
        $work = static fn(AttemptContext $attempt): string => 'ok';

        self::assertSame('ok', $pipeline->execute($work, $pipeline->newContext(Duration::seconds(30))));
        try {
            $pipeline->execute($work, $pipeline->newContext(Duration::seconds(30)));
            self::fail('The single token is spent.');
        } catch (ReqtideException $refusal) {
            self::assertTrue($refusal instanceof AdmissionRejectedException || $refusal instanceof AdmissionDeferredException);
            self::assertSame(ReasonCode::RateExhausted, $refusal->reason());
            self::assertSame('after_wait', $refusal->problem()->retryability()->value);
        }
    }

    public function testABulkheadOfOneRefusesAReentrantCallWhileTheFirstIsRunning(): void
    {
        $pipeline = self::builder()->bulkhead(1, Duration::seconds(10))->compileInMemory(ExceptionClassifier::create());
        $refusal = null;

        $result = $pipeline->execute(
            static function (AttemptContext $attempt) use ($pipeline, &$refusal): string {
                try {
                    $pipeline->execute(static fn(AttemptContext $inner): string => 'never', $pipeline->newContext(Duration::seconds(30)));
                } catch (AdmissionRejectedException $e) {
                    $refusal = $e;
                }

                return 'outer';
            },
            $pipeline->newContext(Duration::seconds(30)),
        );

        self::assertSame('outer', $result);
        self::assertInstanceOf(AdmissionRejectedException::class, $refusal);
        self::assertSame(ReasonCode::ConcurrencyCapacityExhausted, $refusal->reason());
        // After the outer call finished its slot is free again.
        self::assertSame('again', $pipeline->execute(static fn(AttemptContext $a): string => 'again', $pipeline->newContext(Duration::seconds(30))));
    }

    /**
     * @param FallbackOperation<string> $fallback
     * @return ExecutionContext<string>
     */
    private static function withFallback(CompiledPipeline $pipeline, FallbackOperation $fallback): ExecutionContext
    {
        return $pipeline->newContext(Duration::seconds(30), fallback: $fallback);
    }

    public function testTheAdmissionFallbackScopeFlowsThroughTheBuilder(): void
    {
        $fallback = new class implements FallbackOperation {
            public int $runs = 0;

            public function invoke(Outcome $original, ExecutionContext $context): string
            {
                ++$this->runs;

                return 'substitute';
            }
        };
        $default = self::circuit(self::builder())->compileInMemory(ExceptionClassifier::create()->fallbackOn(\Throwable::class));
        $optIn = self::circuit(self::builder())
            ->fallbackScope(FallbackScope::IncludeAdmissionRefusals)
            ->compileInMemory(ExceptionClassifier::create()->fallbackOn(\Throwable::class));

        foreach ([$default, $optIn] as $pipeline) {
            for ($i = 0; $i < 2; ++$i) {
                // The business failures are replaced by the fallback, but they still count against the circuit.
                self::assertSame('substitute', $pipeline->execute(self::failing(), self::withFallback($pipeline, $fallback)));
            }
        }
        $fallback->runs = 0;

        try {
            $default->execute(self::failing(), self::withFallback($default, $fallback));
            self::fail('The default scope shows the refusal.');
        } catch (AdmissionRejectedException) {
            self::assertSame(0, $fallback->runs);
        }
        self::assertSame('substitute', $optIn->execute(self::failing(), self::withFallback($optIn, $fallback)));
        self::assertSame(1, $fallback->runs);
        $completion = $optIn->executeDetailed(self::failing(), self::withFallback($optIn, $fallback));
        self::assertSame(CompletionDisposition::FallbackSucceeded, $completion->disposition);
        $cause = $completion->policyCause;
        self::assertInstanceOf(AdmissionRejectedException::class, $cause);
        self::assertSame(ReasonCode::CircuitOpen, $cause->reason());
    }
}
