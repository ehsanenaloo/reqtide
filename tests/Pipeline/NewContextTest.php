<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\OperationCancelledException;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\OperationSafetyKind;
use Enaxon\Reqtide\Tests\Pipeline\Support\ClosureFallback;
use Enaxon\Reqtide\Tests\Pipeline\Support\DispatchSpy;
use Enaxon\Reqtide\Tests\Pipeline\Support\Harness;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Time\CancellationToken;
use Enaxon\Reqtide\Time\NeverCancelled;
use Enaxon\Reqtide\Value\ApplicationId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\EnvironmentId;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\PartitionIdentity;
use Enaxon\Reqtide\Value\SubjectId;
use PHPUnit\Framework\TestCase;

/** CompiledPipeline::newContext(): the per-operation context built on the pipeline's own clock and partition. */
final class NewContextTest extends TestCase
{
    public function testTheDeadlineIsMeasuredOnThePipelineClock(): void
    {
        $h = new Harness(false, false, true);
        $h->clock->now = 5_000_000_000;

        $context = $h->pipeline()->newContext(Duration::seconds(3));

        self::assertSame(8_000_000_000, $context->deadline->monotonicNanoseconds);
        self::assertSame(3_000_000_000, $context->deadline->remaining($h->clock)->nanoseconds);
    }

    public function testThePartitionDefaultsToTheOneEveryPolicyShares(): void
    {
        $h = new Harness(false, false, true);

        $context = $h->pipeline()->newContext(Duration::seconds(1));

        self::assertTrue($context->partition->equals(Fixtures::partition()));
    }

    public function testAnExplicitPartitionWinsAndIsRequiredWhenNoPolicyCarriesOne(): void
    {
        $partition = new PartitionIdentity(new ApplicationId('other'), new EnvironmentId('env'), null, new SubjectId('s'));

        $withPolicy = (new Harness(false, false, true))->pipeline()->newContext(Duration::seconds(1), partition: $partition);
        self::assertTrue($withPolicy->partition->equals($partition));

        $bare = (new Harness())->pipeline();
        self::assertTrue($bare->newContext(Duration::seconds(1), partition: $partition)->partition->equals($partition));
        try {
            $bare->newContext(Duration::seconds(1));
            self::fail('A pipeline without policies cannot guess the partition.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('Pass partition: explicitly', $e->getMessage());
        }
    }

    public function testEveryContextGetsItsOwnRandomOperationIdUnlessOneIsGiven(): void
    {
        $pipeline = (new Harness(false, false, true))->pipeline();

        $first = $pipeline->newContext(Duration::seconds(1))->operationId->value;
        $second = $pipeline->newContext(Duration::seconds(1))->operationId->value;

        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $first);
        self::assertNotSame($first, $second);
        self::assertSame('order-42', $pipeline->newContext(Duration::seconds(1), operationId: 'order-42')->operationId->value);
        $given = new OperationId('order-43');
        self::assertSame($given, $pipeline->newContext(Duration::seconds(1), operationId: $given)->operationId);
    }

    public function testAnInvalidOperationIdIsRejected(): void
    {
        $pipeline = (new Harness(false, false, true))->pipeline();

        $this->expectException(InvalidConfigurationException::class);
        $pipeline->newContext(Duration::seconds(1), operationId: 'not valid!');
    }

    public function testAZeroTimeoutIsRejectedBecauseItCanOnlyFail(): void
    {
        $pipeline = (new Harness(false, false, true))->pipeline();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('positive timeout');
        $pipeline->newContext(Duration::zero());
    }

    public function testTheDefaultsAreUnsafeNoFallbackNeverCancelledAndEmptyAttributes(): void
    {
        $context = (new Harness(false, false, true))->pipeline()->newContext(Duration::seconds(1));

        self::assertSame(OperationSafetyKind::Unsafe, $context->metadata->safety->kind);
        self::assertNull($context->fallback);
        self::assertInstanceOf(NeverCancelled::class, $context->cancellation);
        self::assertSame([], $context->metadata->attributes->values);
    }

    public function testSafetyFallbackCancellationAndAttributesAreCarriedThrough(): void
    {
        $h = new Harness(false, false, true);
        $safety = Harness::replayable();
        $fallback = new ClosureFallback(static fn(): string => 'fallback');
        $token = new CancellationToken();
        $attributes = new BoundedMetadata(['route' => 'orders.show']);

        $context = $h->pipeline()->newContext(Duration::seconds(1), $safety, $fallback, $token, $attributes);

        self::assertSame($safety, $context->metadata->safety);
        self::assertSame($fallback, $context->fallback);
        self::assertSame($token, $context->cancellation);
        self::assertSame($attributes, $context->metadata->attributes);
    }

    public function testAContextBuiltHereRunsAndHonoursItsCancellation(): void
    {
        $h = new Harness(false, false, true);
        $pipeline = $h->pipeline();
        $spy = DispatchSpy::returning('value', $h->log);

        self::assertSame('value', $pipeline->execute($spy->callable(), $pipeline->newContext(Duration::seconds(5))));

        $token = new CancellationToken();
        $token->cancel();
        try {
            $pipeline->execute($spy->callable(), $pipeline->newContext(Duration::seconds(5), cancellation: $token));
            self::fail('A cancelled context must not dispatch.');
        } catch (OperationCancelledException) {
            self::assertSame(1, $spy->dispatches);
        }
    }

    public function testOnlyRetryingPipelinesNeedASafetyAssertion(): void
    {
        $h = new Harness(false, false, false, true);
        $pipeline = $h->pipeline(Harness::retry(2));
        $spy = DispatchSpy::returning('never', $h->log);

        try {
            $pipeline->execute($spy->callable(), $pipeline->newContext(Duration::seconds(5)));
            self::fail('An unsafe context must be refused when retries are enabled.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('explicit replay-safety contract', $e->getMessage());
        }
        self::assertSame(0, $spy->dispatches);
        self::assertSame('never', $pipeline->execute($spy->callable(), $pipeline->newContext(Duration::seconds(5), OperationSafety::replayableRead(
            \Enaxon\Reqtide\Execution\ReplaySafetyContract::forReadOnlyCallable('read', Duration::seconds(3600), $h->clock, ['transient']),
        ))));
    }

    public function testARetryingPipelineRefusesAReplayContractThatEndsBeforeTheDeadline(): void
    {
        $h = new Harness(false, false, false, true);
        $contract = \Enaxon\Reqtide\Execution\ReplaySafetyContract::forReadOnlyCallable('orders.read', Duration::seconds(5), $h->clock);
        // A millisecond passes between the contract and the context.
        $h->clock->wall = new \DateTimeImmutable('2026-10-08T00:00:00.001+00:00');

        try {
            $h->pipeline(Harness::retry(3))->newContext(Duration::seconds(5), OperationSafety::replayableRead($contract));
            self::fail('A contract that ends with the deadline can never allow a retry.');
        } catch (InvalidConfigurationException $refusal) {
            self::assertStringContainsString('validFor', $refusal->getMessage());
        }

        $longer = \Enaxon\Reqtide\Execution\ReplaySafetyContract::forReadOnlyCallable('orders.read', Duration::seconds(6), $h->clock);
        $context = $h->pipeline(Harness::retry(3))->newContext(Duration::seconds(5), OperationSafety::replayableRead($longer));
        self::assertSame($longer, $context->metadata->safety->contract);
    }

    public function testARetentionShorterThanTheTimeoutIsRefusedToo(): void
    {
        $h = new Harness(false, false, false, true);

        $this->expectException(InvalidConfigurationException::class);
        $h->pipeline(Harness::retry(3))->newContext(Duration::seconds(3601), Harness::replayable());
    }

    public function testAPipelineThatDoesNotRetryDoesNotCheckTheContract(): void
    {
        $h = new Harness(false, false, false, true);
        $contract = \Enaxon\Reqtide\Execution\ReplaySafetyContract::forReadOnlyCallable('orders.read', Duration::seconds(5), $h->clock);

        $context = $h->pipeline(Harness::retry(1))->newContext(Duration::seconds(5), OperationSafety::replayableRead($contract));

        self::assertSame($contract, $context->metadata->safety->contract);
    }

    public function testTheRunTimeCheckStillGuardsAContextBuiltWithoutNewContext(): void
    {
        $h = new Harness(false, false, false, true);
        $contract = \Enaxon\Reqtide\Execution\ReplaySafetyContract::forReadOnlyCallable('orders.read', Duration::seconds(5), $h->clock);
        // A millisecond passes between the contract and the context, as it always does outside a virtual clock.
        $h->clock->wall = new \DateTimeImmutable('2026-10-08T00:00:00.001+00:00');
        $context = $h->context(Duration::seconds(5), null, OperationSafety::replayableRead($contract));
        $spy = DispatchSpy::throwing(new \RuntimeException('transient'), $h->log);

        $completion = $h->pipeline(Harness::retry(3))->executeDetailed($spy->callable(), $context);

        self::assertSame(1, $spy->dispatches);
        self::assertContains('replay_horizon_inadequate', array_map(static fn(\Enaxon\Reqtide\Completion\Diagnostic $d): string => $d->reasonCode, $completion->diagnostics));
    }

    public function testTheContractBoundariesAreExactToTheMicrosecond(): void
    {
        $h = new Harness(false, false, false, true);
        $pipeline = $h->pipeline(Harness::retry(3));

        // Harness::replayable() is valid for exactly one hour from now and keeps the identity for one hour.
        $pipeline->newContext(Duration::seconds(3600), Harness::replayable());

        try {
            $pipeline->newContext(Duration::nanoseconds(3_600_000_000_001), Harness::replayable());
            self::fail('One microsecond (rounded up) beyond the contract is refused.');
        } catch (InvalidConfigurationException) {
        }

        $short = \Enaxon\Reqtide\Execution\ReplaySafetyContract::forReadOnlyCallable('orders.read', Duration::microseconds(5_000_001), $h->clock);
        $pipeline->newContext(Duration::seconds(5), OperationSafety::replayableRead($short));
        $exact = \Enaxon\Reqtide\Execution\ReplaySafetyContract::forReadOnlyCallable('orders.read', Duration::microseconds(4_999_999), $h->clock);
        $this->expectException(InvalidConfigurationException::class);
        $pipeline->newContext(Duration::seconds(5), OperationSafety::replayableRead($exact));
    }

    public function testASinglePermittedAttemptNeverChecksTheContractEvenWhenItIsFarTooShort(): void
    {
        $h = new Harness(false, false, false, true);
        $contract = \Enaxon\Reqtide\Execution\ReplaySafetyContract::forReadOnlyCallable('orders.read', Duration::seconds(1), $h->clock);

        $context = $h->pipeline(Harness::retry(1))->newContext(Duration::seconds(60), OperationSafety::replayableRead($contract));

        self::assertSame($contract, $context->metadata->safety->contract);
    }
}
