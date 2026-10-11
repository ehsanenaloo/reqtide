<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Value;

use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\BackendExpiry;
use Enaxon\Reqtide\Value\BackendInstant;
use Enaxon\Reqtide\Value\CommandId;
use Enaxon\Reqtide\Value\Generation;
use Enaxon\Reqtide\Value\IncarnationId;
use Enaxon\Reqtide\Value\NonNegativeInt;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\PartitionIdentity;
use Enaxon\Reqtide\Value\PermitOwnerId;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\Ratio;
use Enaxon\Reqtide\Value\TenantId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdentityTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function invalidBoundedIds(): iterable
    {
        yield 'empty' => [''];
        yield 'leading dot' => ['.a'];
        yield 'space' => ['a b'];
        yield 'separator' => ["a\x1fb"];
        yield 'control' => ["a\n"];
        yield 'trailing newline' => ["abc\n"];
        yield 'non ascii' => ["caf\u{e9}"];
        yield '129 bytes' => [str_repeat('a', 129)];
    }

    #[DataProvider('invalidBoundedIds')]
    public function testInvalidBoundedIdsAreRejected(string $value): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new OperationId($value);
    }

    public function testBoundedIdBoundary(): void
    {
        self::assertSame(128, strlen((new OperationId(str_repeat('a', 128)))->value));
        self::assertSame('a.b_c-1', (string) new OperationId('a.b_c-1'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidHex(): iterable
    {
        yield 'uppercase' => [strtoupper(Fixtures::hex(255))];
        yield 'short' => [str_repeat('a', 31)];
        yield 'long' => [str_repeat('a', 33)];
        yield 'non hex' => [str_repeat('g', 32)];
        yield 'trailing newline' => [str_repeat('a', 32) . "\n"];
    }

    #[DataProvider('invalidHex')]
    public function testHexIdentitiesRejectMalformedValues(string $value): void
    {
        $this->expectException(InvalidConfigurationException::class);
        AttemptId::fromLowerHex($value);
    }

    public function testHexIdentitiesAreTypedAndComparable(): void
    {
        $hex = Fixtures::hex(0xabc);
        self::assertSame($hex, CommandId::fromLowerHex($hex)->lowerHex);
        self::assertSame($hex, PermitOwnerId::fromLowerHex($hex)->lowerHex);
        self::assertTrue(IncarnationId::fromLowerHex($hex)->equals(IncarnationId::fromLowerHex($hex)));
        self::assertFalse(AttemptId::fromLowerHex($hex)->equals(AttemptId::fromLowerHex(Fixtures::hex(1))));
    }

    public function testGenerationBoundsAndExhaustion(): void
    {
        self::assertSame(1, Generation::first()->value);
        self::assertSame(Generation::MAX, (new Generation(Generation::MAX))->value);
        try {
            new Generation(0);
            self::fail('Generation zero must be rejected.');
        } catch (InvalidConfigurationException) {
        }
        try {
            new Generation(Generation::MAX + 1);
            self::fail('Generation above the exact ceiling must be rejected.');
        } catch (InvalidConfigurationException) {
        }
        self::assertSame(Generation::MAX, (new Generation(Generation::MAX - 1))->next()->value);
        $this->expectException(CorruptStateException::class);
        (new Generation(Generation::MAX))->next();
    }

    public function testBackendInstantDomains(): void
    {
        self::assertSame(999_999, (new BackendInstant(0, 999_999))->microseconds);
        self::assertSame(9_007_199_254_740_991, (new BackendInstant(9_007_199_254_740_991, 0))->seconds);
        $this->expectException(InvalidConfigurationException::class);
        new BackendInstant(0, 1_000_000);
    }

    public function testBackendExpiryFactories(): void
    {
        $local = BackendExpiry::local(5);
        self::assertSame(5, $local->localMonotonicNanoseconds);
        self::assertNull($local->sharedInstant);
        $shared = BackendExpiry::shared(new BackendInstant(1, 2));
        self::assertNull($shared->localMonotonicNanoseconds);
        self::assertNotNull($shared->sharedInstant);
    }

    public function testIntegerWrappers(): void
    {
        self::assertSame(0, (new NonNegativeInt(0))->value);
        try {
            new PositiveInt(0);
            self::fail('Zero is not positive.');
        } catch (InvalidConfigurationException) {
        }
        $this->expectException(InvalidConfigurationException::class);
        new NonNegativeInt(-1);
    }

    public function testRatioMustBeReducedAndNotExceedOne(): void
    {
        self::assertSame(1, Ratio::of(10, 20)->numerator->value);
        self::assertSame(2, Ratio::of(10, 20)->denominator->value);
        try {
            new Ratio(new PositiveInt(10), new PositiveInt(20));
            self::fail('Unreduced ratio must be rejected.');
        } catch (InvalidConfigurationException) {
        }
        try {
            new Ratio(new PositiveInt(3), new PositiveInt(2));
            self::fail('Ratio above one must be rejected.');
        } catch (InvalidConfigurationException) {
        }
        $this->expectException(InvalidConfigurationException::class);
        new Ratio(new PositiveInt(1), new PositiveInt(9_007_199_254_740_992));
    }

    public function testPartitionKeyDistinguishesNullTenantFromTenant(): void
    {
        $withoutTenant = Fixtures::partition();
        $withTenant = new PartitionIdentity(
            $withoutTenant->application,
            $withoutTenant->environment,
            new TenantId('t'),
            $withoutTenant->subject,
        );
        self::assertNotSame($withoutTenant->key(), $withTenant->key());
        self::assertFalse($withoutTenant->equals($withTenant));
        self::assertTrue($withoutTenant->equals(Fixtures::partition()));
    }
}
