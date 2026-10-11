<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Integration;

use Enaxon\Reqtide\Exception\IndeterminateStateException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\Policy\RateLimitPolicy;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Policy\TokenBucketOptions;
use Enaxon\Reqtide\Redis\RedisCircuitAdmin;
use Enaxon\Reqtide\Redis\RedisCircuitBackend;
use Enaxon\Reqtide\Redis\RedisConcurrencyBackend;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisCooldownBackend;
use Enaxon\Reqtide\Redis\RedisRateLimitBackend;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRegistryAdmin;
use Enaxon\Reqtide\Redis\RedisRetryBudgetBackend;
use Enaxon\Reqtide\Redis\Tests\Support\FaultProxy;
use Enaxon\Reqtide\Redis\Tests\Support\RedisEndpoint;
use Enaxon\Reqtide\Redis\Tests\Support\ScriptWarmer;
use Enaxon\Reqtide\State\ConcurrencyAdmissionStatus;
use Enaxon\Reqtide\Tests\Contract\ContractCircuitRig;
use Enaxon\Reqtide\Tests\Contract\ContractIdentities;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\PositiveInt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\Response\Status;

/**
 * Faults against every mutating script. A fault proxy sits between the backend and the real
 * server. For each operation and each fault the test records what the server holds afterwards, what the caller saw, and
 * whether the operation was applied exactly once:
 *
 *   drop-request  the server never receives the command: state unchanged, the caller sees an indeterminate result;
 *   drop-reply    the server executes the command and the reply is lost: state changed, indeterminate result, applied once;
 *   stall         the server executes the command and the reply never arrives: the caller times out, indeterminate, applied once.
 *
 * The backend never sends a command twice, so "applied once" is read from the state after the fault, not from a retry.
 */
final class RedisFaultMatrixTest extends TestCase
{
    private const SECRET = 'a-host-managed-secret-of-32-bytes!!';

    private ?FaultProxy $proxy = null;

    /** @var list<RedisRegistry> */
    private array $registries = [];

    private ?ContractIdentities $ids = null;

    protected function tearDown(): void
    {
        $this->proxy?->stop();
        $client = $this->client();
        foreach ($this->registries as $registry) {
            $cursor = '0';
            do {
                /** @var array{0: string, 1: list<string>} $page */
                $page = $client->scan($cursor, ['MATCH' => $registry->keyPrefix . ':*', 'COUNT' => 500]);
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

    private function direct(): RedisConnectionOptions
    {
        $endpoint = RedisEndpoint::forTest();

        return new RedisConnectionOptions($endpoint->host, 2.0, 5.0, $endpoint->port);
    }

    private function proxy(): FaultProxy
    {
        return $this->proxy ??= FaultProxy::start(RedisEndpoint::forTest());
    }

    private function ids(): ContractIdentities
    {
        return $this->ids ??= new ContractIdentities();
    }

    private function warmScripts(): void
    {
        ScriptWarmer::warm($this->client());
    }

    private function newRegistry(): RedisRegistry
    {
        $registry = new RedisRegistry('rt_fault_' . bin2hex(random_bytes(5)), 'fault-matrix', self::SECRET);
        $this->registries[] = $registry;

        return $registry;
    }

    /** @return array<string, mixed> every key of the registry with its complete content */
    private function snapshot(RedisRegistry $registry): array
    {
        $client = $this->client();
        /** @var list<string> $keys */
        $keys = $client->keys($registry->keyPrefix . ':*');
        sort($keys);
        $content = [];
        foreach ($keys as $key) {
            $reply = $client->type($key);
            $type = $reply instanceof Status ? $reply->getPayload() : (is_string($reply) ? $reply : '');
            $content[$key] = match ($type) {
                'hash' => $client->hgetall($key),
                'zset' => $client->zrange($key, 0, -1, ['withscores' => true]),
                'list' => $client->lrange($key, 0, -1),
                default => $type,
            };
        }

        return $content;
    }

    /**
     * The operation under fault, and how to tell afterwards whether it was applied once.
     *
     * @return array{call: \Closure(): mixed, effect: \Closure(): string, applied: string}
     */
    private function operation(string $name, RedisRegistry $registry, ?RedisConnectionOptions $faultedOptions = null): array
    {
        $direct = $this->direct();
        $proxy = $faultedOptions ?? $this->proxy()->options();
        $admin = new RedisRegistryAdmin($direct, $registry);
        if ($name !== 'registry.initialize') {
            $admin->initialize();
        }
        $ids = $this->ids();
        $identity = ContractIdentities::identity('fault');
        $counters = static function () use ($admin): string {
            $status = $admin->verify();

            return $status->partitions . '/' . $status->records;
        };

        switch ($name) {
            case 'registry.initialize':
                $faulted = new RedisRegistryAdmin($proxy, $registry);

                return [
                    'call' => static fn() => $faulted->initialize(),
                    'effect' => static fn(): string => $admin->initialize()->created ? 'created now' : 'existed',
                    'applied' => 'existed',
                ];
            case 'registry.retire':
                $faulted = new RedisRegistryAdmin($proxy, $registry);

                return [
                    'call' => static fn() => $faulted->retire(),
                    'effect' => static function () use ($admin): string {
                        try {
                            $admin->verify();

                            return 'present';
                        } catch (StateUnavailableException) {
                            return 'absent';
                        }
                    },
                    'applied' => 'absent',
                ];
            case 'cooldown.extend':
                $policy = new CooldownPolicy($identity, Duration::seconds(60));
                $faulted = new RedisCooldownBackend($proxy, $registry);
                $plain = new RedisCooldownBackend($direct, $registry);

                return [
                    'call' => fn() => $faulted->extend($policy, Duration::seconds(30), $ids->command()),
                    'effect' => static fn(): string => $plain->inspect($policy)->status->value . ' ' . $counters(),
                    'applied' => 'active 1/1',
                ];
            case 'concurrency.acquire':
                $policy = new ConcurrencyPolicy($identity, new PositiveInt(3), Duration::seconds(30));
                $faulted = new RedisConcurrencyBackend($proxy, $registry);

                return [
                    'call' => fn() => $faulted->acquire($policy, $ids->owner(), $ids->command()),
                    'effect' => $counters,
                    'applied' => '1/1',
                ];
            case 'concurrency.release':
                $policy = new ConcurrencyPolicy($identity, new PositiveInt(3), Duration::seconds(30));
                $plain = new RedisConcurrencyBackend($direct, $registry);
                $admission = $plain->acquire($policy, $ids->owner(), $ids->command());
                self::assertSame(ConcurrencyAdmissionStatus::Accepted, $admission->status);
                $permit = $admission->permit;
                self::assertNotNull($permit);
                $faulted = new RedisConcurrencyBackend($proxy, $registry);

                return [
                    'call' => fn() => $faulted->release($policy, $permit, $ids->command()),
                    'effect' => fn(): string => $plain->release($policy, $permit, $ids->command())->status->value,
                    'applied' => 'already_finalized',
                ];
            case 'ratelimit.consume':
                $policy = new RateLimitPolicy($identity, new TokenBucketOptions(new PositiveInt(5), new PositiveInt(1), Duration::seconds(3600)));
                $faulted = new RedisRateLimitBackend($proxy, $registry);
                $plain = new RedisRateLimitBackend($direct, $registry);

                return [
                    'call' => fn() => $faulted->consume($policy, new PositiveInt(1), $ids->command()),
                    'effect' => static fn(): string => (string) $plain->inspect($policy, new PositiveInt(1))->available->value,
                    'applied' => '4',
                ];
            case 'retrybudget.reserve':
                $policy = new RetryBudgetPolicy($identity, new PositiveInt(5), new PositiveInt(1), Duration::seconds(3600), Duration::seconds(60));
                $faulted = new RedisRetryBudgetBackend($proxy, $registry);
                $plain = new RedisRetryBudgetBackend($direct, $registry);

                return [
                    'call' => fn() => $faulted->reserve($policy, $ids->attempt(), $ids->owner(), $ids->command()),
                    'effect' => static fn(): string => $counters() . ' tokens ' . $plain->inspect($policy)->availableTokens->value,
                    'applied' => '1/1 tokens 4',
                ];
            case 'retrybudget.commit':
                $policy = new RetryBudgetPolicy($identity, new PositiveInt(5), new PositiveInt(1), Duration::seconds(3600), Duration::seconds(60));
                $plain = new RedisRetryBudgetBackend($direct, $registry);
                $decision = $plain->reserve($policy, $ids->attempt(), $ids->owner(), $ids->command());
                $reservation = $decision->reservation;
                self::assertNotNull($reservation);
                $faulted = new RedisRetryBudgetBackend($proxy, $registry);

                return [
                    'call' => fn() => $faulted->commit($policy, $reservation, $ids->command()),
                    'effect' => fn(): string => $plain->commit($policy, $reservation, $ids->command())->status->value,
                    'applied' => 'already_committed',
                ];
            case 'circuit.acquire':
                $policy = ContractCircuitRig::countPolicy(identity: $identity);
                $faulted = new RedisCircuitBackend($proxy, $registry);

                return [
                    'call' => fn() => $faulted->acquire($policy, $ids->attempt(), $ids->owner(), $ids->command()),
                    'effect' => $counters,
                    'applied' => '1/1',
                ];
            case 'circuit.record':
                $policy = ContractCircuitRig::countPolicy(identity: $identity);
                $plain = new RedisCircuitBackend($direct, $registry);
                $permit = $plain->acquire($policy, $ids->attempt(), $ids->owner(), $ids->command())->permit;
                self::assertNotNull($permit);
                $faulted = new RedisCircuitBackend($proxy, $registry);

                return [
                    'call' => fn() => $faulted->record($policy, $permit, CircuitVerdict::Success, $ids->command()),
                    'effect' => fn(): string => $plain->record($policy, $permit, CircuitVerdict::Success, $ids->command())->status->value,
                    'applied' => 'already_finalized',
                ];
            case 'circuit.reset':
                $policy = ContractCircuitRig::countPolicy(identity: $identity);
                $plain = new RedisCircuitBackend($direct, $registry);
                self::assertNotNull($plain->acquire($policy, $ids->attempt(), $ids->owner(), $ids->command())->permit);
                $faulted = new RedisCircuitAdmin($proxy, $registry);
                $again = new RedisCircuitAdmin($direct, $registry);

                return [
                    'call' => static fn() => $faulted->reset($identity),
                    'effect' => static fn(): string => 'generation ' . ($again->reset($identity)->value ?? 0),
                    'applied' => 'generation 3',
                ];
        }

        self::fail('unknown operation ' . $name);
    }

    /** @return iterable<string, array{string, string}> */
    public static function matrix(): iterable
    {
        foreach ([
            'registry.initialize', 'registry.retire', 'cooldown.extend', 'concurrency.acquire', 'concurrency.release',
            'ratelimit.consume', 'retrybudget.reserve', 'retrybudget.commit', 'circuit.acquire', 'circuit.record', 'circuit.reset',
        ] as $operation) {
            foreach (['drop-request', 'drop-reply', 'stall'] as $fault) {
                yield $operation . ' / ' . $fault => [$operation, $fault];
            }
        }
    }

    #[DataProvider('matrix')]
    public function testAFaultOnAMutatingCommandIsIndeterminateBoundedAndAppliedAtMostOnce(string $operation, string $fault): void
    {
        $registry = $this->newRegistry();
        $this->warmScripts();
        $case = $this->operation($operation, $registry);
        $before = $this->snapshot($registry);

        $this->proxy()->mode($fault);
        $started = hrtime(true);
        try {
            $case['call']();
            self::fail('The fault must surface as an indeterminate result.');
        } catch (IndeterminateStateException) {
        } finally {
            $this->proxy()->mode('pass');
        }
        $seconds = (hrtime(true) - $started) / 1e9;
        self::assertLessThan(3.5, $seconds, 'the fault ended in bounded time');

        $after = $this->snapshot($registry);
        if ($fault === 'drop-request') {
            self::assertSame($before, $after, 'the server never received the command: nothing changed');
            self::assertNotSame($case['applied'], ($case['effect'])(), 'and the operation was not applied');

            return;
        }
        self::assertNotSame($before, $after, 'the server executed the command: its effect is stored');
        self::assertSame($case['applied'], ($case['effect'])(), 'the operation was applied exactly once; nothing sent it a second time');
    }

    public function testAFaultOnAReadOnlyCommandIsUnavailableAndChangesNothing(): void
    {
        $registry = $this->newRegistry();
        $this->warmScripts();
        $direct = $this->direct();
        (new RedisRegistryAdmin($direct, $registry))->initialize();
        $identity = ContractIdentities::identity('read');
        $cooldown = new CooldownPolicy($identity, Duration::seconds(60));
        $bucket = new RateLimitPolicy($identity, new TokenBucketOptions(new PositiveInt(5), new PositiveInt(1), Duration::seconds(3600)));
        $budget = new RetryBudgetPolicy($identity, new PositiveInt(5), new PositiveInt(1), Duration::seconds(3600), Duration::seconds(60));
        $proxy = $this->proxy()->options();
        $reads = [
            'cooldown.inspect' => static fn() => (new RedisCooldownBackend($proxy, $registry))->inspect($cooldown),
            'ratelimit.inspect' => static fn() => (new RedisRateLimitBackend($proxy, $registry))->inspect($bucket, new PositiveInt(1)),
            'retrybudget.inspect' => static fn() => (new RedisRetryBudgetBackend($proxy, $registry))->inspect($budget),
            'registry.verify' => static fn() => (new RedisRegistryAdmin($proxy, $registry))->verify(),
        ];

        foreach (['drop-request', 'drop-reply', 'stall'] as $fault) {
            foreach ($reads as $name => $read) {
                $before = $this->snapshot($registry);
                $this->proxy()->mode($fault);
                try {
                    $read();
                    self::fail($name . ' / ' . $fault . ': the fault must surface.');
                } catch (StateUnavailableException) {
                    self::addToAssertionCount(1);
                } finally {
                    $this->proxy()->mode('pass');
                }
                self::assertSame($before, $this->snapshot($registry), $name . ' / ' . $fault . ' changed state');
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function aclSweep(): iterable
    {
        foreach ([
            'registry.initialize', 'registry.retire', 'cooldown.extend', 'concurrency.acquire', 'concurrency.release',
            'ratelimit.consume', 'retrybudget.reserve', 'retrybudget.commit', 'circuit.acquire', 'circuit.record', 'circuit.reset',
        ] as $operation) {
            foreach (['hset', 'hdel', 'del', 'zadd', 'zrem', 'rpush', 'lpop'] as $denied) {
                yield $operation . ' without ' . $denied => [$operation, $denied];
            }
        }
    }

    /**
     * A user who may run every command except one write command. If the script reaches that command, the denial must
     * leave no earlier write behind; if it never uses it, the operation just succeeds.
     */
    #[DataProvider('aclSweep')]
    public function testADeniedWriteCommandNeverLeavesAPartialWrite(string $operation, string $denied): void
    {
        $registry = $this->newRegistry();
        $this->warmScripts();
        $admin = $this->client();
        $user = 'rt_sweep_' . bin2hex(random_bytes(3));
        $password = 'pw-' . bin2hex(random_bytes(6));
        $admin->executeRaw(['ACL', 'SETUSER', $user, 'on', '>' . $password, '~*', '&*', '+@all', '-' . $denied]);
        try {
            $endpoint = RedisEndpoint::forTest();
            $limited = new RedisConnectionOptions($endpoint->host, 2.0, 5.0, $endpoint->port, $user, $password);
            $case = $this->operation($operation, $registry, $limited);
            $before = $this->snapshot($registry);
            try {
                $case['call']();
                self::addToAssertionCount(1);
            } catch (StateUnavailableException $failure) {
                self::assertStringContainsString('rt_acl', $failure->getMessage(), 'the denial is caught by the access check before the first write');
                self::assertSame($before, $this->snapshot($registry), 'the denial of ' . $denied . ' in ' . $operation . ' left a partial write');
            } catch (IndeterminateStateException) {
                self::addToAssertionCount(1);
            }
        } finally {
            $admin->executeRaw(['ACL', 'DELUSER', $user]);
        }
    }
}
