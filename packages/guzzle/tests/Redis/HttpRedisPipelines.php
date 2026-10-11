<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Redis;

use Enaxon\Reqtide\Guzzle\GuzzleConfiguration;
use Enaxon\Reqtide\Guzzle\ReqtideClient;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Redis\RedisCircuitBackend;
use Enaxon\Reqtide\Redis\RedisConcurrencyBackend;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisCooldownBackend;
use Enaxon\Reqtide\Redis\RedisRateLimitBackend;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRetryBudgetBackend;
use Enaxon\Reqtide\Telemetry\EventSink;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\Ratio;

/**
 * The pipelines of the cross-process HTTP scenarios, built the same way by the coordinator and by every worker
 * process, so that they share one policy identity: a rate limit (`rate`), a cooldown (`cooldown`), a circuit (`circuit`),
 * a retry budget with two attempts (`budget`), a bulkhead (`bulkhead`).
 */
final class HttpRedisPipelines
{
    private function __construct() {}

    public static function builder(string $kind, int $capacity = 1): PipelineBuilder
    {
        $builder = PipelineBuilder::profile('http-redis-' . $kind, 'v1')
            ->partition('test', 'local', 'fixture')
            ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::TransportEnforced);

        return match ($kind) {
            'rate' => $builder
                ->retry(1, BackoffMode::Constant, Duration::milliseconds(5), Duration::seconds(1), JitterMode::None)
                ->rateLimit(PipelineBuilder::tokenBucket($capacity, 1, Duration::seconds(3600))),
            'cooldown' => $builder
                ->retry(1, BackoffMode::Constant, Duration::milliseconds(5), Duration::seconds(1), JitterMode::None)
                ->cooldown(Duration::seconds(60)),
            'circuit' => $builder
                ->retry(1, BackoffMode::Constant, Duration::milliseconds(5), Duration::seconds(1), JitterMode::None)
                ->circuitBreaker(PipelineBuilder::lastCalls(10), 4, Ratio::of(1, 2), Duration::seconds(60), 1, 1, Duration::seconds(10)),
            'budget' => $builder
                ->retry(2, BackoffMode::Constant, Duration::milliseconds(5), Duration::seconds(1), JitterMode::None)
                ->retryBudget(5, 5, Duration::seconds(3600), Duration::seconds(30)),
            'bulkhead' => $builder
                ->retry(1, BackoffMode::Constant, Duration::milliseconds(5), Duration::seconds(1), JitterMode::None)
                ->bulkhead(1, Duration::seconds(30)),
            default => throw new \InvalidArgumentException('unknown pipeline kind ' . $kind),
        };
    }

    public static function client(string $kind, RedisConnectionOptions $options, RedisRegistry $registry, int $capacity = 1, ?EventSink $events = null): ReqtideClient
    {
        return ReqtideClient::withBackends(
            self::builder($kind, $capacity),
            rateLimit: $kind === 'rate' ? new RedisRateLimitBackend($options, $registry) : null,
            circuit: $kind === 'circuit' ? new RedisCircuitBackend($options, $registry) : null,
            concurrency: $kind === 'bulkhead' ? new RedisConcurrencyBackend($options, $registry) : null,
            retryBudget: $kind === 'budget' ? new RedisRetryBudgetBackend($options, $registry) : null,
            cooldown: $kind === 'cooldown' ? new RedisCooldownBackend($options, $registry) : null,
            configuration: new GuzzleConfiguration(),
            events: $events,
        );
    }
}
