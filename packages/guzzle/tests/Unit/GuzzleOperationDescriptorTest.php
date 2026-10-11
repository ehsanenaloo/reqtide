<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\OperationSafetyKind;
use Enaxon\Reqtide\Guzzle\Transport\GuzzleOperationDescriptor;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The descriptor carries bounded facts and nothing that could leak a secret. */
final class GuzzleOperationDescriptorTest extends TestCase
{
    private const KEY = 'a-credential-fingerprint-key-32-bytes!';

    private function descriptor(Request $request, string $group = 'billing.read', ?string $key = self::KEY): GuzzleOperationDescriptor
    {
        return GuzzleOperationDescriptor::fromRequest($request, $group, OperationSafety::unsafe(), $key);
    }

    public function testMethodIsNormalizedAndOriginIsFingerprintedWithDefaultPorts(): void
    {
        $a = $this->descriptor(new Request('get', 'HTTPS://Api.Example.com/a?x=1'));
        $b = $this->descriptor(new Request('GET', 'https://api.example.com:443/other'));
        self::assertSame('GET', $a->normalizedMethod);
        self::assertSame($a->originFingerprint, $b->originFingerprint, 'case, default port and path do not change the origin');
        self::assertMatchesRegularExpression('~^[0-9a-f]{32}$~', $a->originFingerprint);

        foreach (['http://api.example.com/a', 'https://api.example.com:8443/a', 'https://other.example.com/a'] as $uri) {
            self::assertNotSame($a->originFingerprint, $this->descriptor(new Request('GET', $uri))->originFingerprint, $uri);
        }
    }

    public function testNothingButBoundedFactsReachesTheCoreMetadata(): void
    {
        $request = new Request(
            'POST',
            'https://api.example.com/v1/customers/cust_4711/secret-path?token=QUERY-CANARY',
            ['Authorization' => 'Bearer HEADER-CANARY', 'X-Custom' => 'HEADER-CANARY-2', 'Idempotency-Key' => 'KEY-CANARY'],
            'BODY-CANARY',
        );
        $metadata = $this->descriptor($request)->toCoreMetadata();

        $dump = json_encode($metadata->attributes->values, JSON_THROW_ON_ERROR);
        foreach (['CANARY', 'cust_4711', 'secret-path', 'token', 'v1', 'api.example.com', 'Bearer'] as $leak) {
            self::assertStringNotContainsString($leak, $dump);
        }
        self::assertSame(['http_method', 'origin_fingerprint', 'operation_group', 'credential_fingerprint'], array_keys($metadata->attributes->values));
        self::assertSame(OperationSafetyKind::Unsafe, $metadata->safety->kind);
    }

    public function testTheCredentialFingerprintIsKeyedStableAndAbsentWithoutCredentials(): void
    {
        $request = new Request('GET', 'https://api.example.com/', ['Authorization' => 'Bearer one']);
        $first = $this->descriptor($request)->credentialFingerprint;
        self::assertNotNull($first);
        self::assertSame($first, $this->descriptor($request)->credentialFingerprint);
        self::assertNotSame($first, $this->descriptor($request, 'billing.read', self::KEY . 'x')->credentialFingerprint, 'a different key gives a different fingerprint');
        self::assertNotSame($first, $this->descriptor($request->withHeader('Authorization', 'Bearer two'))->credentialFingerprint);
        self::assertNotSame($first, $this->descriptor($request->withHeader('Proxy-Authorization', 'Basic x'))->credentialFingerprint);
        self::assertStringNotContainsString('one', (string) $first);

        $plain = $this->descriptor(new Request('GET', 'https://api.example.com/'));
        self::assertNull($plain->credentialFingerprint);
        self::assertArrayNotHasKey('credential_fingerprint', $plain->toCoreMetadata()->attributes->values);
    }

    public function testWithoutAKeyTheFingerprintIsStableWithinTheProcess(): void
    {
        $request = new Request('GET', 'https://api.example.com/', ['Authorization' => 'Bearer one']);
        self::assertSame($this->descriptor($request, 'g', null)->credentialFingerprint, $this->descriptor($request, 'g', null)->credentialFingerprint);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badGroups(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['a b'];
        yield 'slash' => ['a/b'];
        yield 'too long' => [str_repeat('g', 65)];
        yield 'newline at the end' => ["group\n"];
    }

    #[DataProvider('badGroups')]
    public function testTheGroupIsAStrictBoundedToken(string $group): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->descriptor(new Request('GET', 'https://api.example.com/'), $group);
    }

    public function testARelativeUriIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->descriptor(new Request('GET', '/relative/only'));
    }

    public function testDirectConstructionRejectsUriLikeAndMalformedFields(): void
    {
        $safety = OperationSafety::unsafe();
        $fingerprint = str_repeat('a', 32);
        $rejected = 0;
        foreach ([
            fn() => new GuzzleOperationDescriptor('get', $fingerprint, 'g', null, $safety),
            fn() => new GuzzleOperationDescriptor('GET', 'https://api.example.com', 'g', null, $safety),
            fn() => new GuzzleOperationDescriptor('GET', $fingerprint, 'g', 'Bearer token', $safety),
            fn() => new GuzzleOperationDescriptor('GET', $fingerprint, 'https://x/y?z', null, $safety),
        ] as $make) {
            try {
                $make();
                self::fail('expected an exception');
            } catch (InvalidConfigurationException) {
                ++$rejected;
            }
        }
        self::assertSame(4, $rejected);
    }
}
