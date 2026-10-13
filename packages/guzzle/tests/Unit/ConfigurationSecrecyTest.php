<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Enaxon\Reqtide\Guzzle\GuzzleConfiguration;
use Enaxon\Reqtide\Guzzle\HttpOperation;
use Enaxon\Reqtide\Guzzle\HttpOutcomeClassifier;
use Enaxon\Reqtide\Guzzle\ReqtideClient;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Value\Duration;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

/**
 * The client defaults carry credentials (Basic and Digest auth, proxy user information, key passphrases, default
 * Authorization headers). Like the Redis connection options, the configuration and the client do not print them in a dump,
 * do not serialize, and keep them out of the arguments of a stack trace.
 */
final class ConfigurationSecrecyTest extends TestCase
{
    private const SECRETS = ['unique-basic-secret', 'unique-proxy-secret', 'unique-passphrase', 'unique-bearer-secret'];

    /** @return array<string, mixed> */
    private static function defaults(): array
    {
        return [
            'auth' => ['user', 'unique-basic-secret'],
            'proxy' => 'http://proxy-user:unique-proxy-secret@proxy.example.com:8080',
            'cert' => ['/etc/tls/client.pem', 'unique-passphrase'],
            'headers' => ['Authorization' => 'Bearer unique-bearer-secret', 'Accept' => 'application/json'],
            'verify' => true,
        ];
    }

    private static function assertNoSecret(string $text): void
    {
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
    }

    public function testADumpOfTheConfigurationHidesTheClientDefaultsThatAreCredentials(): void
    {
        $configuration = new GuzzleConfiguration(HttpOutcomeClassifier::starter(), self::defaults(), 'https://api.example.com/v1', ['X-Api-Key']);

        self::assertNoSecret(print_r($configuration, true));
        ob_start();
        var_dump($configuration);
        self::assertNoSecret((string) ob_get_clean());
        self::assertStringContainsString('baseUri', print_r($configuration, true), 'the dump still shows what is not secret');
        self::assertStringContainsString('verify', print_r($configuration, true));
    }

    public function testTheConfigurationCannotBeSerialized(): void
    {
        $this->expectException(\LogicException::class);
        serialize(new GuzzleConfiguration(null, self::defaults()));
    }

    public function testADumpOfTheClientHidesTheClientDefaultsToo(): void
    {
        $pipeline = PipelineBuilder::profile('secrecy', 'v1')->partition('t', 'l', 's')->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::TransportEnforced);
        $client = ReqtideClient::inMemory($pipeline, new GuzzleConfiguration(null, self::defaults()));

        self::assertNoSecret(print_r($client, true));
        ob_start();
        var_dump($client);
        self::assertNoSecret((string) ob_get_clean());

        $this->expectException(\LogicException::class);
        serialize($client);
    }

    public function testAStackTraceDoesNotCarryTheClientDefaultsAsArguments(): void
    {
        try {
            // A refused option makes the constructor throw while the credentials are still its arguments.
            new GuzzleConfiguration(null, self::defaults() + ['sink' => '/tmp/x']);
            self::fail('expected the option to be refused');
        } catch (\Throwable $failure) {
            self::assertNoSecret($failure->getTraceAsString());
            foreach ($failure->getTrace() as $frame) {
                self::assertNoSecret(print_r($frame['args'] ?? [], true));
            }
        }
    }

    public function testTheOperationKeepsHidingItsCredentialKey(): void
    {
        $operation = HttpOperation::read(new Request('GET', 'https://api.example.com/x'), 'g', Duration::seconds(30), [], credentialKey: 'unique-credential-key-value');

        self::assertStringNotContainsString('unique-credential-key-value', print_r($operation, true));
    }
}
