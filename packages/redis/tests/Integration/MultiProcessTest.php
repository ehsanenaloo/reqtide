<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Integration;

use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\RateLimitPolicy;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Policy\TokenBucketOptions;
use Enaxon\Reqtide\Redis\RedisCircuitBackend;
use Enaxon\Reqtide\Redis\RedisConcurrencyBackend;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisRateLimitBackend;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRegistryAdmin;
use Enaxon\Reqtide\Redis\RedisRegistryMaintenance;
use Enaxon\Reqtide\Redis\RedisRetryBudgetBackend;
use Enaxon\Reqtide\Redis\Tests\Support\BarrierHarness;
use Enaxon\Reqtide\Redis\Tests\Support\RedisEndpoint;
use Enaxon\Reqtide\Redis\Tests\Support\ScriptWarmer;
use Enaxon\Reqtide\State\CircuitAdmissionStatus;
use Enaxon\Reqtide\State\ConcurrencyAdmissionStatus;
use Enaxon\Reqtide\Tests\Contract\ContractCircuitRig;
use Enaxon\Reqtide\Tests\Contract\ContractIdentities;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\TestCase;
use Predis\Client;

/**
 * Rate limit, concurrency, circuit and registry checks with independent processes: workers of real PHP processes start on
 * a barrier of the server clock and work on one registry, and the totals are checked against what the algorithm allows.
 */
final class MultiProcessTest extends TestCase
{
    private const SECRET = 'a-host-managed-secret-of-32-bytes!!';

    private const IDENTITY = 'multi-process';

    private string $prefix = '';

    protected function setUp(): void
    {
        $this->prefix = 'rt_mp_' . bin2hex(random_bytes(5));
        ScriptWarmer::warm($this->client());
    }

    protected function tearDown(): void
    {
        $client = $this->client();
        foreach ([$this->prefix . ':*', 'probe:' . $this->prefix, 'start:' . $this->prefix] as $pattern) {
            $cursor = '0';
            do {
                /** @var array{0: string, 1: list<string>} $page */
                $page = $client->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 500]);
                $cursor = (string) $page[0];
                if ($page[1] !== []) {
                    $client->del($page[1]);
                }
            } while ($cursor !== '0');
        }
    }

    private function client(): Client
    {
        return new Client(RedisEndpoint::forTest()->parameters(10.0));
    }

    private function options(): RedisConnectionOptions
    {
        $endpoint = RedisEndpoint::forTest();

        return new RedisConnectionOptions($endpoint->host, 2.0, 10.0, $endpoint->port);
    }

    private function registry(int $maxPartitions = 10_000, int $maxRecords = 20_000): RedisRegistry
    {
        $registry = new RedisRegistry($this->prefix, self::IDENTITY, self::SECRET, $maxPartitions, $maxRecords);
        (new RedisRegistryAdmin($this->options(), $registry))->initialize();

        return $registry;
    }

    /**
     * @param array<string, mixed> $spec
     * @return list<array<string, mixed>>
     */
    private function workers(int $count, array $spec, int $maxPartitions = 10_000, int $maxRecords = 20_000, float $limit = 60.0, ?\Closure $tick = null): array
    {
        $results = BarrierHarness::run(RedisEndpoint::forTest(), $this->prefix, self::IDENTITY, self::SECRET, $count, $spec, $maxPartitions, $maxRecords, $limit, $tick);
        self::assertCount($count, $results);
        foreach ($results as $result) {
            self::assertSame([], $result['errors'], 'worker ' . json_encode($result['worker']) . ' reported errors');
        }

        return $results;
    }

    /**
     * @param list<array<string, mixed>> $results
     */
    private static function sum(array $results, string $field): int
    {
        return array_sum(array_map(static fn(array $result): int => is_int($result[$field]) ? $result[$field] : 0, $results));
    }

    /**
     * @param list<array<string, mixed>> $results
     */
    private static function extra(array $results, string $field): int
    {
        return array_sum(array_map(static fn(array $result): int => is_array($result['extra']) && is_int($result['extra'][$field] ?? null) ? $result['extra'][$field] : 0, $results));
    }

    public function testTwelveProcessesNeverDrawMoreThanTheCapacityOfOneBucket(): void
    {
        $this->registry();
        $results = $this->workers(12, ['scenario' => 'rate', 'capacity' => 100, 'calls' => 40, 'mixedCosts' => false]);

        self::assertSame(480, self::sum($results, 'accepted') + self::sum($results, 'rejected'), 'every call was answered');
        self::assertSame(100, self::sum($results, 'accepted'), 'exactly the capacity was admitted');
        $policy = new RateLimitPolicy(ContractIdentities::identity('shared'), new TokenBucketOptions(new PositiveInt(100), new PositiveInt(1), Duration::seconds(3600)));
        $available = (new RedisRateLimitBackend($this->options(), $this->registry()))->inspect($policy, new PositiveInt(1))->available->value;
        self::assertSame(0, $available);
    }

    public function testWeightedCostsAreAccountedExactlyAcrossProcesses(): void
    {
        $this->registry();
        $results = $this->workers(12, ['scenario' => 'rate', 'capacity' => 150, 'calls' => 40, 'mixedCosts' => true]);

        $debited = self::sum($results, 'acceptedCost');
        self::assertLessThanOrEqual(150, $debited);
        $policy = new RateLimitPolicy(ContractIdentities::identity('shared'), new TokenBucketOptions(new PositiveInt(150), new PositiveInt(1), Duration::seconds(3600)));
        $available = (new RedisRateLimitBackend($this->options(), $this->registry()))->inspect($policy, new PositiveInt(1))->available->value;
        self::assertSame(150 - $debited, $available, 'the tokens left are exactly the capacity minus the cost admitted');
        self::assertLessThan(3, $available, 'and the bucket was used up to the cost of one request');
    }

    public function testTheBulkheadNeverHoldsMoreValidLeasesThanItsCapacity(): void
    {
        $this->registry();
        $results = $this->workers(12, ['scenario' => 'bulkhead', 'capacity' => 4, 'calls' => 60]);

        $inflight = max(0, ...array_map(static fn(array $result): int => is_array($result['extra']) && is_int($result['extra']['maxInflight'] ?? null) ? $result['extra']['maxInflight'] : 0, $results));
        self::assertGreaterThan(0, self::sum($results, 'accepted'));
        self::assertGreaterThan(0, self::sum($results, 'rejected'), 'the capacity was contended');
        self::assertLessThanOrEqual(4, $inflight, 'no more than four permits were held at once');
        self::assertSame('0', (string) $this->client()->get('probe:' . $this->prefix), 'every worker that took a permit gave it back');
    }

    public function testKilledHoldersFreeTheirSlotsOnlyWhenTheirLeasesExpire(): void
    {
        $registry = $this->registry();
        $started = hrtime(true);
        $this->workers(4, ['scenario' => 'bulkhead-crash', 'capacity' => 4, 'leaseSeconds' => 4]);
        // The harness has killed the four holders; their permits were never released.
        $backend = new RedisConcurrencyBackend($this->options(), $registry);
        $policy = new ConcurrencyPolicy(ContractIdentities::identity('shared'), new PositiveInt(4), Duration::seconds(4));
        $ids = new ContractIdentities();

        self::assertSame(ConcurrencyAdmissionStatus::CapacityExhausted, $backend->acquire($policy, $ids->owner(), $ids->command())->status, 'the killed holders still own their slots');
        $deadline = hrtime(true) + 12_000_000_000;
        do {
            $admission = $backend->acquire($policy, $ids->owner(), $ids->command());
            if ($admission->status === ConcurrencyAdmissionStatus::Accepted) {
                break;
            }
            usleep(50_000);
        } while (hrtime(true) < $deadline);
        self::assertSame(ConcurrencyAdmissionStatus::Accepted, $admission->status, 'the leases expired and the slots came back');
        $elapsed = (hrtime(true) - $started) / 1e9;
        self::assertGreaterThan(3.0, $elapsed, 'not before the lease of 4 seconds ended');
    }

    public function testTheBudgetNeverCommitsMoreThanItsCapacity(): void
    {
        $this->registry();
        $results = $this->workers(8, ['scenario' => 'budget', 'capacity' => 20, 'calls' => 12]);

        $committed = self::sum($results, 'acceptedCost');
        self::assertLessThanOrEqual(20, $committed);
        self::assertGreaterThan(0, $committed);
        $policy = new RetryBudgetPolicy(ContractIdentities::identity('shared'), new PositiveInt(20), new PositiveInt(1), Duration::seconds(3600), Duration::seconds(60));
        $available = (new RedisRetryBudgetBackend($this->options(), $this->registry()))->inspect($policy)->availableTokens->value;
        self::assertSame(20 - $committed, $available, 'tokens left are the capacity minus the committed cost; released ones came back');
    }

    public function testOnlyTheProbeCapacityIsAdmittedWhenTheOpenPeriodEnds(): void
    {
        $registry = $this->registry();
        $backend = new RedisCircuitBackend($this->options(), $registry);
        $policy = ContractCircuitRig::countPolicy(minimum: 2, probeCapacity: 2, openSeconds: 1, identity: ContractIdentities::identity('shared'));
        $ids = new ContractIdentities();
        for ($i = 0; $i < 2; ++$i) {
            $permit = $backend->acquire($policy, $ids->attempt(), $ids->owner(), $ids->command())->permit;
            self::assertNotNull($permit);
            $backend->record($policy, $permit, CircuitVerdict::Failure, $ids->command());
        }
        self::assertSame(CircuitAdmissionStatus::Open, $backend->acquire($policy, $ids->attempt(), $ids->owner(), $ids->command())->status);
        $deadline = hrtime(true) + 1_300_000_000;
        while (hrtime(true) < $deadline) {
            usleep(10_000);
        }

        $results = $this->workers(10, ['scenario' => 'circuit', 'probes' => 2, 'openSeconds' => 1]);

        self::assertSame(2, self::sum($results, 'accepted'), 'exactly the probe capacity was admitted');
        foreach ($results as $result) {
            self::assertIsArray($result['extra']);
            if ($result['accepted'] === 1) {
                self::assertSame(3, $result['extra']['generation'], 'closed 1, open 2, half open 3: one transition, whoever won it');
            }
        }
    }

    public function testChurnKeepsCountersAndKeysBoundedAndConsistent(): void
    {
        $this->registry(20, 40);
        $client = $this->client();
        $admin = new RedisRegistryAdmin($this->options(), $this->registry(20, 40));
        $maxKeys = 0;
        $maxPartitions = 0;
        $maxRecords = 0;
        $memoryFirst = null;
        $memoryLast = 0;
        $nextSample = 0.0;
        $tick = function () use ($client, $admin, &$maxKeys, &$maxPartitions, &$maxRecords, &$memoryFirst, &$memoryLast, &$nextSample): void {
            $now = microtime(true);
            if ($now < $nextSample) {
                return;
            }
            $nextSample = $now + 0.5;
            $status = $admin->verify();
            $maxPartitions = max($maxPartitions, $status->partitions);
            $maxRecords = max($maxRecords, $status->records);
            $maxKeys = max($maxKeys, count($client->keys($this->prefix . ':*')));
            $memory = $client->info('memory');
            $section = $memory['Memory'] ?? $memory['memory'] ?? [];
            $used = is_array($section) && is_numeric($section['used_memory'] ?? null) ? (int) $section['used_memory'] : 0;
            $memoryFirst ??= $used;
            $memoryLast = $used;
        };

        $results = $this->workers(8, ['scenario' => 'churn', 'seconds' => 8, 'subjects' => 40], 20, 40, 60.0, $tick);

        $operations = self::extra($results, 'operations');
        $counters = sprintf(
            'operations=%d maxPartitions=%d maxRecords=%d maxKeys=%d memoryFirst=%s memoryLast=%d',
            $operations,
            $maxPartitions,
            $maxRecords,
            $maxKeys,
            var_export($memoryFirst, true),
            $memoryLast,
        );

        // Throughput depends on the load of the host, so this only proves that the workers ran.
        self::assertGreaterThan(0, $operations, "the workers did real work ({$counters})");
        self::assertLessThanOrEqual(20, $maxPartitions, $counters);
        self::assertLessThanOrEqual(40, $maxRecords, $counters);
        self::assertLessThanOrEqual(20 * 2 + 4, $maxKeys, "the number of keys follows the partitions, not the history ({$counters})");
        self::assertNotNull($memoryFirst);
        self::assertLessThan(8_000_000, $memoryLast - $memoryFirst, "server memory did not grow with the number of operations ({$counters})");

        // Quiet: maintenance runs to the end, and the counters equal what is stored.
        $maintenance = new RedisRegistryMaintenance($this->options(), $this->registry(20, 40));
        for ($i = 0; $i < 20 && $maintenance->step()['more']; ++$i) {
        }
        $status = $admin->verify();
        /** @var list<string> $keys */
        $keys = $client->keys($this->prefix . ':p:*');
        $partitions = array_filter($keys, static fn(string $key): bool => !str_ends_with($key, ':a') && !str_ends_with($key, ':w'));
        self::assertSame(count($partitions), $status->partitions, 'the partition counter equals the partitions stored');
    }
}
