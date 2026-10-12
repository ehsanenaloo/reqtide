<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Guzzle\GuzzleConfiguration;
use Enaxon\Reqtide\Guzzle\HttpOperation;
use Enaxon\Reqtide\Guzzle\HttpOutcomeClassifier;
use Enaxon\Reqtide\Guzzle\ReqtideClient;
use Enaxon\Reqtide\Guzzle\Tests\Support\FixtureServer;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Value\Duration;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The credential fingerprint that the pipeline sees describes the credentials the client really sends: the request's own
 * Authorization and Proxy-Authorization headers, default headers, a Basic `auth` default that replaces the header,
 * Digest `auth` defaults and the user information of a proxy. The fixture server's record shows what was sent, and the
 * expected fingerprint is computed from that.
 */
final class CredentialMetadataBoundaryTest extends TestCase
{
    private const KEY = 'a-credential-fingerprint-key-32-bytes!';

    private static FixtureServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = FixtureServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        self::$server->reset();
    }

    /** @return iterable<string, array{bool}> */
    public static function paths(): iterable
    {
        yield 'synchronous' => [false];
        yield 'promise' => [true];
    }

    private function read(Request $request): HttpOperation
    {
        return HttpOperation::read($request, 'fixture.read', Duration::seconds(60), [], credentialKey: self::KEY);
    }

    /** @param array<string, mixed> $defaults */
    private function client(array $defaults): ReqtideClient
    {
        $pipeline = PipelineBuilder::profile('credential-boundary', 'v1')
            ->partition('test', 'local', 'fixture')
            ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::TransportEnforced);

        return ReqtideClient::inMemory($pipeline, new GuzzleConfiguration(HttpOutcomeClassifier::starter(), $defaults));
    }

    /** @param array<string, mixed> $defaults */
    private function dispatch(array $defaults, HttpOperation $operation, bool $async): void
    {
        $client = $this->client($defaults);
        if ($async) {
            $client->sendAsync($operation, Duration::seconds(10))->wait();
        } else {
            $client->send($operation, Duration::seconds(10));
        }
    }

    /** @return list<string> */
    private static function sentAuthorization(): array
    {
        $log = self::$server->log();
        self::assertCount(1, $log);
        self::assertIsArray($log[0]['headers']);
        $values = $log[0]['headers']['authorization'] ?? [];
        self::assertIsArray($values);

        return array_values(array_map(static fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $values));
    }

    /** The fingerprint of a request that carries exactly the headers the server received. */
    private function fingerprintOfWhatWasSent(): ?string
    {
        $sent = self::sentAuthorization();
        $headers = $sent === [] ? [] : ['Authorization' => $sent];

        return $this->read(new Request('GET', self::$server->baseUri . '/status/200', $headers))->descriptor->credentialFingerprint;
    }

    /** @param array<string, mixed> $defaults */
    private function resolved(array $defaults, Request $request): ?string
    {
        return $this->read($request)->forClient(new GuzzleConfiguration(HttpOutcomeClassifier::starter(), $defaults))->descriptor->credentialFingerprint;
    }

    #[DataProvider('paths')]
    public function testACredentialAddedByTheDefaultAuthOptionIsInTheFingerprint(bool $async): void
    {
        $defaults = ['auth' => ['test-user', 'test-pass']];
        $request = new Request('GET', self::$server->baseUri . '/status/200');
        self::assertNull($this->read($request)->descriptor->credentialFingerprint, 'the request alone carries no credential');

        $this->dispatch($defaults, $this->read($request), $async);

        self::assertSame(['Basic ' . base64_encode('test-user:test-pass')], self::sentAuthorization());
        self::assertNotNull($this->resolved($defaults, $request));
        self::assertSame($this->fingerprintOfWhatWasSent(), $this->resolved($defaults, $request));
    }

    #[DataProvider('paths')]
    public function testACredentialAddedByTheDefaultHeadersIsInTheFingerprint(bool $async): void
    {
        $defaults = ['headers' => ['authorization' => 'Bearer default-token']];
        $request = new Request('GET', self::$server->baseUri . '/status/200');

        $this->dispatch($defaults, $this->read($request), $async);

        self::assertSame(['Bearer default-token'], self::sentAuthorization());
        self::assertSame($this->fingerprintOfWhatWasSent(), $this->resolved($defaults, $request));
    }

    #[DataProvider('paths')]
    public function testTheDefaultAuthOptionThatReplacesAGivenHeaderChangesTheFingerprint(bool $async): void
    {
        $defaults = ['auth' => ['test-user', 'test-pass']];
        $request = new Request('GET', self::$server->baseUri . '/status/200', ['Authorization' => 'Bearer given-token']);

        $this->dispatch($defaults, $this->read($request), $async);

        self::assertSame(['Basic ' . base64_encode('test-user:test-pass')], self::sentAuthorization());
        self::assertSame($this->fingerprintOfWhatWasSent(), $this->resolved($defaults, $request));
        self::assertNotSame($this->read($request)->descriptor->credentialFingerprint, $this->resolved($defaults, $request));
    }

    #[DataProvider('paths')]
    public function testAGivenHeaderStaysInTheFingerprintWhenNothingReplacesIt(bool $async): void
    {
        $defaults = ['headers' => ['Authorization' => 'Bearer default-token']];
        $request = new Request('GET', self::$server->baseUri . '/status/200', ['Authorization' => 'Bearer given-token']);

        $this->dispatch($defaults, $this->read($request), $async);

        self::assertSame(['Bearer given-token'], self::sentAuthorization());
        self::assertSame($this->fingerprintOfWhatWasSent(), $this->resolved($defaults, $request));
        self::assertSame($this->read($request)->descriptor->credentialFingerprint, $this->resolved($defaults, $request));
    }

    /** @param array<string, string> $headers */
    private function withUserInfo(string $userInfo, array $headers = []): Request
    {
        $uri = (new \GuzzleHttp\Psr7\Uri(self::$server->baseUri . '/status/200'));

        return new Request('GET', $uri->withUserInfo(...explode(':', $userInfo, 2)), $headers);
    }

    /** @return iterable<string, array{bool, string, string}> */
    public static function userInfo(): iterable
    {
        foreach ([false => 'synchronous', true => 'promise'] as $async => $path) {
            yield $path . ', plain' => [(bool) $async, 'probe-user:probe-pass', 'Basic ' . base64_encode('probe-user:probe-pass')];
            yield $path . ', encoded' => [(bool) $async, 'us@er:p:ss', 'Basic ' . base64_encode('us@er:p:ss')];
        }
    }

    #[DataProvider('userInfo')]
    public function testCredentialsInTheRequestUriAreInTheFingerprint(bool $async, string $userInfo, string $expectedHeader): void
    {
        $request = $this->withUserInfo($userInfo);

        $this->dispatch([], $this->read($request), $async);

        self::assertSame([$expectedHeader], self::sentAuthorization());
        self::assertNotNull($this->read($request)->descriptor->credentialFingerprint);
        self::assertSame($this->fingerprintOfWhatWasSent(), $this->read($request)->descriptor->credentialFingerprint);
        self::assertNotSame(
            $this->read($request)->descriptor->credentialFingerprint,
            $this->read($this->withUserInfo('other-user:probe-pass'))->descriptor->credentialFingerprint,
            'another user is another credential',
        );
    }

    #[DataProvider('paths')]
    public function testAnAuthorizationHeaderWinsOverCredentialsInTheRequestUri(bool $async): void
    {
        $request = $this->withUserInfo('probe-user:probe-pass', ['Authorization' => 'Bearer given-token']);

        $this->dispatch([], $this->read($request), $async);

        self::assertSame(['Bearer given-token'], self::sentAuthorization());
        self::assertSame($this->fingerprintOfWhatWasSent(), $this->read($request)->descriptor->credentialFingerprint);
    }

    #[DataProvider('paths')]
    public function testABasicAuthDefaultWinsOverCredentialsInTheRequestUri(bool $async): void
    {
        $defaults = ['auth' => ['test-user', 'test-pass']];
        $request = $this->withUserInfo('probe-user:probe-pass');

        $this->dispatch($defaults, $this->read($request), $async);

        self::assertSame(['Basic ' . base64_encode('test-user:test-pass')], self::sentAuthorization());
        self::assertSame($this->fingerprintOfWhatWasSent(), $this->resolved($defaults, $request));
    }

    public function testCredentialsInTheRequestUriTogetherWithDigestAuthChangeTheFingerprintWhenEitherChanges(): void
    {
        $digest = ['auth' => ['user', 'one', 'digest']];
        $request = new Request('GET', 'https://probe-user:probe-pass@api.example.com/items');
        $both = $this->resolved($digest, $request);

        self::assertNotNull($both);
        self::assertNotSame($both, $this->resolved($digest, new Request('GET', 'https://other-user:probe-pass@api.example.com/items')));
        self::assertNotSame($both, $this->resolved(['auth' => ['user', 'two', 'digest']], $request));
        self::assertNotSame($both, $this->resolved($digest, new Request('GET', 'https://api.example.com/items')));
    }

    /**
     * @param array<string, mixed> $defaults
     * @param list<string> $credentialHeaders
     */
    private function resolvedWith(array $defaults, array $credentialHeaders, Request $request): ?string
    {
        return $this->read($request)->forClient(new GuzzleConfiguration(HttpOutcomeClassifier::starter(), $defaults, null, $credentialHeaders))->descriptor->credentialFingerprint;
    }

    public function testConfiguredCredentialHeadersAreInTheFingerprint(): void
    {
        $plain = new Request('GET', 'https://api.example.com/items');
        $keyed = $plain->withHeader('X-Api-Key', 'key-one');

        self::assertNull($this->resolvedWith([], ['X-Api-Key'], $plain));
        self::assertNotNull($this->resolvedWith([], ['X-Api-Key'], $keyed));
        self::assertNotSame($this->resolvedWith([], ['X-Api-Key'], $keyed), $this->resolvedWith([], ['X-Api-Key'], $plain->withHeader('X-Api-Key', 'key-two')));
        self::assertNull($this->resolvedWith([], [], $keyed), 'a header that is not named as a credential is not part of the fingerprint');
        self::assertSame(
            $this->resolvedWith([], ['x-api-key'], $keyed),
            $this->resolvedWith(['headers' => ['X-API-KEY' => 'key-one']], ['X-Api-Key'], $plain),
            'a default header is the same credential as the request header it fills in, whatever the case of the name',
        );
        self::assertSame(
            $this->resolvedWith([], ['X-Api-Key'], $keyed),
            $this->resolvedWith(['headers' => ['X-Api-Key' => 'other']], ['X-Api-Key'], $keyed),
            'the request header wins over the default',
        );
    }

    public function testTheClientCertificateAndKeyAreInTheFingerprint(): void
    {
        $request = new Request('GET', 'https://api.example.com/items');
        $a = $this->resolvedWith(['cert' => '/etc/tls/client-a.pem'], [], $request);
        $b = $this->resolvedWith(['cert' => '/etc/tls/client-b.pem'], [], $request);
        $withPassword = $this->resolvedWith(['cert' => ['/etc/tls/client-a.pem', 'pass-one']], [], $request);
        $key = $this->resolvedWith(['cert' => '/etc/tls/client-a.pem', 'ssl_key' => ['/etc/tls/client-a.key', 'pass-two']], [], $request);

        self::assertNotNull($a);
        self::assertNotSame($a, $b);
        self::assertNotSame($a, $withPassword);
        self::assertNotSame($a, $key);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function badHeaderNames(): iterable
    {
        yield 'space' => [['X Api Key']];
        yield 'colon' => [['X-Api-Key:']];
        yield 'empty' => [['']];
        yield 'too long' => [[str_repeat('a', 65)]];
    }

    /** @param list<string> $names */
    #[DataProvider('badHeaderNames')]
    public function testACredentialHeaderMustBeAHeaderName(array $names): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new GuzzleConfiguration(null, [], null, $names);
    }

    public function testWithoutDefaultsTheDescriptorIsUnchanged(): void
    {
        $operation = $this->read(new Request('GET', self::$server->baseUri . '/status/200', ['Authorization' => 'Bearer given-token']));

        self::assertSame($operation, $operation->forClient(new GuzzleConfiguration()));
        self::assertSame($operation->descriptor->credentialFingerprint, $operation->forClient(new GuzzleConfiguration(null, ['verify' => false]))->descriptor->credentialFingerprint);
    }

    public function testDigestCredentialsAndProxyUserInformationAreInTheFingerprint(): void
    {
        $request = new Request('GET', 'https://api.example.com/items');

        $digestA = $this->resolved(['auth' => ['user', 'one', 'digest']], $request);
        $digestB = $this->resolved(['auth' => ['user', 'two', 'digest']], $request);
        self::assertNotNull($digestA);
        self::assertNotSame($digestA, $digestB, 'another password is another credential');

        $proxy = $this->resolved(['proxy' => 'http://proxy-user:proxy-pass@proxy.example.com:8080'], $request);
        self::assertNotNull($proxy);
        self::assertNotSame($proxy, $this->resolved(['proxy' => 'http://other-user:proxy-pass@proxy.example.com:8080'], $request));
        self::assertNull($this->resolved(['proxy' => 'http://proxy.example.com:8080'], $request), 'a proxy without user information adds nothing');
        self::assertNull($this->resolved(['proxy' => ['https' => 'http://proxy-user:proxy-pass@proxy.example.com:8080', 'no' => ['example.com']]], $request), 'a host on the no-proxy list does not use the proxy');
        self::assertNull($this->resolved(['proxy' => ['http' => 'http://proxy-user:proxy-pass@proxy.example.com:8080']], $request), 'the proxy of another scheme is not used');
        self::assertNotNull($this->resolved(['proxy' => ['https' => 'http://proxy-user:proxy-pass@proxy.example.com:8080']], $request));
    }

    public function testTheFingerprintDependsOnTheKeyAndNeverShowsTheCredential(): void
    {
        $defaults = ['auth' => ['test-user', 'unique-secret-value']];
        $request = new Request('GET', 'https://api.example.com/items');
        $first = HttpOperation::read($request, 'g', Duration::seconds(60), [], credentialKey: self::KEY)->forClient(new GuzzleConfiguration(null, $defaults));
        $second = HttpOperation::read($request, 'g', Duration::seconds(60), [], credentialKey: self::KEY . 'x')->forClient(new GuzzleConfiguration(null, $defaults));

        self::assertNotSame($first->descriptor->credentialFingerprint, $second->descriptor->credentialFingerprint);
        $fingerprint = (string) $first->descriptor->credentialFingerprint;
        self::assertMatchesRegularExpression('~^[0-9a-f]{32}\z~', $fingerprint);
        $dump = print_r($first->metadata()->attributes, true) . print_r($first, true);
        self::assertStringNotContainsString('unique-secret-value', $dump);
        self::assertStringNotContainsString(self::KEY, $dump, 'the key is not part of a dump of the operation');
        self::assertStringNotContainsString(base64_encode('test-user:unique-secret-value'), print_r($first->metadata()->attributes, true));
    }

    #[DataProvider('paths')]
    public function testABasicAuthDefaultThatWouldReplaceTheIdentityHeaderIsRefusedBeforeAnythingIsSent(bool $async): void
    {
        $operation = HttpOperation::idempotentMutation(
            new Request('POST', self::$server->baseUri . '/status/200', ['Authorization' => 'key-1'], 'x'),
            'fixture.create',
            'key-1',
            's',
            'd',
            Duration::seconds(60),
            identityHeader: 'Authorization',
        );

        try {
            $this->dispatch(['auth' => ['test-user', 'test-pass']], $operation, $async);
            self::fail('expected the operation to be refused');
        } catch (InvalidConfigurationException) {
            self::assertSame([], self::$server->log());
        }
    }
}
