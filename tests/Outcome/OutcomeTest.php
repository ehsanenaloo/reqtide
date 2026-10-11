<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Outcome;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Outcome\Acceptability;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Outcome\FallbackEligibility;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Outcome\OutcomeClassification;
use Enaxon\Reqtide\Outcome\OutcomeKind;
use Enaxon\Reqtide\Outcome\RetryEligibility;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;

final class OutcomeTest extends TestCase
{
    public function testSuccessfulNullRemainsAValue(): void
    {
        $outcome = Outcome::fromValue(null, BoundedMetadata::empty());
        self::assertSame(OutcomeKind::Value, $outcome->kind);
        self::assertNull($outcome->value());
    }

    public function testExceptionOutcomeKeepsOriginalInstance(): void
    {
        $error = new \RuntimeException('original');
        $outcome = Outcome::fromException($error, BoundedMetadata::empty());
        self::assertSame(OutcomeKind::Exception, $outcome->kind);
        self::assertSame($error, $outcome->exception());
    }

    public function testWrongKindAccessThrowsLogicException(): void
    {
        $this->expectException(\LogicException::class);
        Outcome::fromValue(1, BoundedMetadata::empty())->exception();
    }

    public function testValueOfExceptionOutcomeThrowsLogicException(): void
    {
        $this->expectException(\LogicException::class);
        Outcome::fromException(new \RuntimeException(), BoundedMetadata::empty())->value();
    }

    public function testClassificationKeepsRetryAfterNullDistinctFromZero(): void
    {
        $none = new OutcomeClassification(RetryEligibility::Eligible, CircuitVerdict::Failure, FallbackEligibility::Eligible, Acceptability::Unacceptable, 'http_503', null);
        $zero = new OutcomeClassification(RetryEligibility::Eligible, CircuitVerdict::Failure, FallbackEligibility::Eligible, Acceptability::Unacceptable, 'http_503', Duration::zero());
        self::assertNull($none->retryAfterLowerBound);
        self::assertNotNull($zero->retryAfterLowerBound);
    }

    public function testClassificationRejectsUnboundedReasonCode(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new OutcomeClassification(RetryEligibility::Eligible, CircuitVerdict::Failure, FallbackEligibility::Eligible, Acceptability::Unacceptable, str_repeat('a', 65), null);
    }

    public function testMetadataBounds(): void
    {
        $thirtyTwo = array_fill_keys(array_map(static fn(int $i): string => 'k' . $i, range(1, 32)), 1);
        self::assertCount(32, (new BoundedMetadata($thirtyTwo))->values);
        $thirtyThree = $thirtyTwo + ['k33' => 1];
        try {
            new BoundedMetadata($thirtyThree);
            self::fail('33 entries must be rejected.');
        } catch (InvalidConfigurationException) {
        }
        try {
            new BoundedMetadata([str_repeat('k', 65) => 1]);
            self::fail('A 65 byte key must be rejected.');
        } catch (InvalidConfigurationException) {
        }
        self::assertSame(256, strlen((string) (new BoundedMetadata(['k' => str_repeat('a', 256)]))->values['k']));
        $this->expectException(InvalidConfigurationException::class);
        new BoundedMetadata(['k' => str_repeat('a', 257)]);
    }

    public function testMetadataRejectsEmptyKeyAndInvalidUtf8(): void
    {
        try {
            new BoundedMetadata(['' => 1]);
            self::fail('Empty key must be rejected.');
        } catch (InvalidConfigurationException) {
        }
        $this->expectException(InvalidConfigurationException::class);
        new BoundedMetadata(['k' => "\xff\xfe"]);
    }
}
