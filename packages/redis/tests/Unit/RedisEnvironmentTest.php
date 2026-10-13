<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Unit;

use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Redis\RedisCircuitBackend;
use Enaxon\Reqtide\Redis\RedisConcurrencyBackend;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisCooldownBackend;
use Enaxon\Reqtide\Redis\RedisEnvironment;
use Enaxon\Reqtide\Redis\RedisRateLimitBackend;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRetryBudgetBackend;
use Enaxon\Reqtide\Value\Duration;
use PHPUnit\Framework\TestCase;

/**
 * The Redis environment factory: the right backends for the configured policies, no I/O at construction or explanation,
 * and an explanation that names scope, timeout and registry without host, credential, prefix or secret.
 */
final class RedisEnvironmentTest extends TestCase
{
    private const SECRET = 'a-secret-that-must-never-be-printed!!';

    private static function closedPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server);
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function connection(): RedisConnectionOptions
    {
        return new RedisConnectionOptions('127.0.0.1', 1.0, 2.5, self::closedPort(), 'app-user', 'a-password-that-must-never-be-printed');
    }

    private function registry(): RedisRegistry
    {
        return new RedisRegistry('billing-prod', 'eu1-billing', self::SECRET, 500, 900);
    }

    private function builder(): PipelineBuilder
    {
        return PipelineBuilder::profile('billing', 'v1')
            ->partition('shop', 'prod', 'billing-api')
            ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
            ->rateLimit(PipelineBuilder::tokenBucket(10, 10, Duration::seconds(1)))
            ->bulkhead(3, Duration::seconds(10));
    }

    /** @return iterable<string, array{string, string}> */
    public static function longIdentities(): iterable
    {
        yield 'application' => ['application', 'a'];
        yield 'environment' => ['environment', 'e'];
        yield 'profile version' => ['profile version', 'v'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('longIdentities')]
    public function testAnIdentityLongerThanTheKeyLayoutAllowsIsRefusedWhenTheEnvironmentIsBuilt(string $part, string $letter): void
    {
        $long = str_repeat($letter, 65);
        $builder = PipelineBuilder::profile('billing', $part === 'profile version' ? $long : 'v1')
            ->partition($part === 'application' ? $long : 'shop', $part === 'environment' ? $long : 'prod', 'billing-api')
            ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
            ->bulkhead(3, Duration::seconds(10));

        $this->expectException(\Enaxon\Reqtide\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessage('at most 64');
        RedisEnvironment::for($builder->definition(), ExceptionClassifier::create(), $this->connection(), $this->registry());
    }

    public function testAnIdentityOfExactlyTheLimitIsAccepted(): void
    {
        $builder = PipelineBuilder::profile('billing', str_repeat('v', 64))
            ->partition(str_repeat('a', 64), str_repeat('e', 64), 'billing-api')
            ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
            ->bulkhead(3, Duration::seconds(10));

        $environment = RedisEnvironment::for($builder->definition(), ExceptionClassifier::create(), $this->connection(), $this->registry());

        self::assertNotNull($environment->concurrency);
    }

    public function testItBuildsOnlyTheBackendsOfTheConfiguredPolicies(): void
    {
        $builder = $this->builder();
        $environment = RedisEnvironment::for($builder->definition(), ExceptionClassifier::create(), $this->connection(), $this->registry());

        self::assertInstanceOf(RedisRateLimitBackend::class, $environment->rateLimit);
        self::assertInstanceOf(RedisConcurrencyBackend::class, $environment->concurrency);
        self::assertNull($environment->circuit);
        self::assertNull($environment->retryBudget);
        self::assertNull($environment->cooldown);
    }

    public function testItBuildsAllFiveBackendsWhenAllPoliciesAreConfigured(): void
    {
        $builder = $this->builder()
            ->circuitBreaker(PipelineBuilder::lastCalls(20), 10, \Enaxon\Reqtide\Value\Ratio::of(1, 2), Duration::seconds(30), 1, 1, Duration::seconds(10))
            ->retry(2, \Enaxon\Reqtide\Policy\BackoffMode::Constant, Duration::milliseconds(5), Duration::seconds(1), \Enaxon\Reqtide\Policy\JitterMode::None)
            ->retryBudget(10, 10, Duration::seconds(60), Duration::seconds(10))
            ->cooldown(Duration::seconds(60));
        $environment = RedisEnvironment::for($builder->definition(), ExceptionClassifier::create(), $this->connection(), $this->registry());

        self::assertInstanceOf(RedisCircuitBackend::class, $environment->circuit);
        self::assertInstanceOf(RedisRetryBudgetBackend::class, $environment->retryBudget);
        self::assertInstanceOf(RedisCooldownBackend::class, $environment->cooldown);
    }

    public function testNothingIsSentOrConnectedWhenTheEnvironmentIsBuiltCompiledAndExplained(): void
    {
        $builder = $this->builder();
        $connection = $this->connection();
        $registry = $this->registry();

        $started = hrtime(true);
        $pipeline = $builder->compile(RedisEnvironment::for($builder->definition(), ExceptionClassifier::create(), $connection, $registry));
        RedisEnvironment::explain($pipeline, $connection, $registry);
        $seconds = (hrtime(true) - $started) / 1e9;

        // The port is closed: any attempt to connect would fail or take time; nothing did.
        self::assertLessThan(0.5, $seconds);
    }

    public function testTheExplanationNamesScopeTimeoutAndRegistryButNoHostCredentialPrefixOrSecret(): void
    {
        $builder = $this->builder();
        $connection = $this->connection();
        $registry = $this->registry();
        $pipeline = $builder->compile(RedisEnvironment::for($builder->definition(), ExceptionClassifier::create(), $connection, $registry));

        $explanation = RedisEnvironment::explain($pipeline, $connection, $registry);

        foreach ([
            'state_scope=shared_authority',
            'state_clock=redis_server',
            'state_io_timeout_seconds=2.5',
            'state_tls=false',
            'registry_identity=eu1-billing',
            'registry_schema=1',
            'registry_max_partitions=500',
            'registry_max_records=900',
        ] as $note) {
            self::assertContains($note, $explanation->capabilityNotes);
        }
        self::assertContains('rate_limit', $explanation->enabledPolicies);
        $text = implode("\n", [...$explanation->capabilityNotes, ...$explanation->enabledPolicies]);
        foreach (['127.0.0.1', 'app-user', 'a-password-that-must-never-be-printed', 'billing-prod', self::SECRET] as $private) {
            self::assertStringNotContainsString($private, $text);
        }
    }

    public function testAnOperationOnAnUnreachableServerIsUnavailableAndNeverRunsTheCallable(): void
    {
        $builder = $this->builder();
        $pipeline = $builder->compile(RedisEnvironment::for($builder->definition(), ExceptionClassifier::create(), $this->connection(), $this->registry()));
        $calls = 0;

        try {
            $pipeline->execute(static function () use (&$calls): string {
                ++$calls;

                return 'must not run';
            }, $pipeline->newContext(Duration::seconds(5)));
            self::fail('An unreachable store must reject the call.');
        } catch (StateUnavailableException) {
            self::assertSame(0, $calls);
        }
    }
}
