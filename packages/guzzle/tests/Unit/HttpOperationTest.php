<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\OperationSafetyKind;
use Enaxon\Reqtide\Execution\ReplayPreparation;
use Enaxon\Reqtide\Guzzle\HttpOperation;
use Enaxon\Reqtide\Value\Duration;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Nothing is inferred; every repeatable operation carries an explicit, bounded statement. */
final class HttpOperationTest extends TestCase
{
    public function testAReadIsOnlyForGetAndHeadAndCarriesAnExplicitStatement(): void
    {
        $operation = HttpOperation::read(new Request('GET', 'https://api.example.com/items'), 'items.read', Duration::seconds(30), ['http_503']);
        $safety = $operation->descriptor->safety;

        self::assertSame(OperationSafetyKind::ReplayableRead, $safety->kind);
        self::assertNotNull($safety->contract);
        self::assertSame('http-safe-method', $safety->contract->contractReference);
        self::assertSame(ReplayPreparation::VerifiedRewind, $safety->contract->preparation);
        self::assertSame(['http_503'], $safety->contract->retryableOutcomeCodes);
        self::assertEquals(Duration::seconds(30), $safety->contract->retention);
        self::assertEquals(30, $safety->contract->validUntil->getTimestamp() - $safety->contract->establishedAt->getTimestamp());
        self::assertSame($safety, $operation->metadata()->safety);

        HttpOperation::read(new Request('HEAD', 'https://api.example.com/items'), 'items.head', Duration::seconds(1));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notReads(): iterable
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('notReads')]
    public function testAReadRefusesEveryOtherMethod(string $method): void
    {
        $this->expectException(InvalidConfigurationException::class);
        HttpOperation::read(new Request($method, 'https://api.example.com/items'), 'g', Duration::seconds(30));
    }

    public function testAMutationNeedsAnIdentityAndItsStatementIsRecorded(): void
    {
        $operation = HttpOperation::idempotentMutation(
            new Request('POST', 'https://api.example.com/charges', ['Idempotency-Key' => 'key-123'], 'x'),
            'charges.create',
            'key-123',
            'account and endpoint',
            'returns the stored response for 24 hours',
            Duration::seconds(60),
            Duration::seconds(86400),
            retryableOutcomeCodes: ['http_503'],
        );
        $contract = $operation->descriptor->safety->contract;

        self::assertSame(OperationSafetyKind::ProviderIdempotentMutation, $operation->descriptor->safety->kind);
        self::assertNotNull($contract);
        self::assertSame('provider-idempotent-mutation', $contract->contractReference);
        self::assertSame('key-123', $contract->identityValue);
        self::assertSame('account and endpoint', $contract->identityScope);
        self::assertSame('returns the stored response for 24 hours', $contract->duplicateHandlingContract);
        self::assertEquals(Duration::seconds(86400), $contract->retention);
        self::assertSame('Idempotency-Key', $operation->identityHeader);
    }

    public function testAMutationRefusesGetAndHeadAndAnEmptyIdentity(): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            try {
                HttpOperation::idempotentMutation(new Request($method, 'https://api.example.com/x'), 'g', 'k', 's', 'd', Duration::seconds(1));
                self::fail('expected an exception');
            } catch (InvalidConfigurationException) {
            }
        }
        $this->expectException(InvalidConfigurationException::class);
        HttpOperation::idempotentMutation(new Request('POST', 'https://api.example.com/x'), 'g', '', 's', 'd', Duration::seconds(1));
    }

    public function testABodyFactorySelectsTheFactoryPreparationAndAZeroWindowIsRefused(): void
    {
        $operation = HttpOperation::read(
            new Request('GET', 'https://api.example.com/x'),
            'g',
            Duration::seconds(5),
            bodyFactory: static fn() => Utils::streamFor('abc'),
        );
        self::assertSame(ReplayPreparation::BodyFactory, $operation->descriptor->safety->contract?->preparation);

        $this->expectException(InvalidConfigurationException::class);
        HttpOperation::read(new Request('GET', 'https://api.example.com/x'), 'g', Duration::zero());
    }

    public function testSingleIsUnsafeAndHasNoContract(): void
    {
        $operation = HttpOperation::single(new Request('POST', 'https://api.example.com/x', [], 'body'), 'g');
        self::assertSame(OperationSafetyKind::Unsafe, $operation->descriptor->safety->kind);
        self::assertNull($operation->descriptor->safety->contract);
        self::assertSame('POST', $operation->metadata()->attributes->values['http_method']);
    }

    public function testTheIdentityHeaderNameIsValidated(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        HttpOperation::idempotentMutation(new Request('POST', 'https://api.example.com/x'), 'g', 'k', 's', 'd', Duration::seconds(1), identityHeader: "Bad Header\n");
    }
    public function testALowerCaseMethodIsSentInUpperCase(): void
    {
        $read = HttpOperation::read(new Request('get', 'https://api.example.com/x'), 'g', Duration::seconds(1));
        self::assertSame('GET', $read->request->getMethod());

        $mutation = HttpOperation::idempotentMutation(new Request('post', 'https://api.example.com/x', ['Idempotency-Key' => 'k']), 'g', 'k', 's', 'd', Duration::seconds(1));
        self::assertSame('POST', $mutation->request->getMethod());
    }

    public function testAnIdentityValueWithSurroundingWhitespaceIsRefusedBecauseGuzzleTrimsTheHeader(): void
    {
        foreach ([' k', 'k ', "\tk", "k\t"] as $value) {
            try {
                HttpOperation::idempotentMutation(new Request('POST', 'https://api.example.com/x', ['Idempotency-Key' => $value]), 'g', $value, 's', 'd', Duration::seconds(1));
                self::fail('expected a refusal');
            } catch (InvalidConfigurationException) {
                self::addToAssertionCount(1);
            }
        }
        $inner = HttpOperation::idempotentMutation(new Request('POST', 'https://api.example.com/x', ['Idempotency-Key' => 'a b']), 'g', 'a b', 's', 'd', Duration::seconds(1));
        self::assertSame(['a b'], $inner->request->getHeader('Idempotency-Key'));
    }
}
