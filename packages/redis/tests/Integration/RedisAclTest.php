<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Integration;

use Enaxon\Reqtide\Exception\InfrastructureException;
use Enaxon\Reqtide\Outcome\CircuitVerdict;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\Policy\RateLimitPolicy;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Policy\TokenBucketOptions;
use Enaxon\Reqtide\Redis\RedisAcl;
use Enaxon\Reqtide\Redis\RedisCircuitAdmin;
use Enaxon\Reqtide\Redis\RedisCircuitBackend;
use Enaxon\Reqtide\Redis\RedisConcurrencyBackend;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisCooldownBackend;
use Enaxon\Reqtide\Redis\RedisRateLimitBackend;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\RedisRegistryAdmin;
use Enaxon\Reqtide\Redis\RedisRegistryMaintenance;
use Enaxon\Reqtide\Redis\RedisRetryBudgetBackend;
use Enaxon\Reqtide\Redis\Tests\Support\RedisEndpoint;
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
 * The documented ACL rules (RedisAcl) are enough for every operation, and each rule is needed: with one removed the first
 * call is refused as unavailable and nothing is written. The script cache is flushed before every run so that the
 * `SCRIPT LOAD` rule is used too.
 */
final class RedisAclTest extends TestCase
{
    private const SECRET = 'a-host-managed-secret-of-32-bytes!!';

    private const PREFIX_START = 'rt_acl_';

    private ?RedisRegistry $registry = null;

    /** @var list<string> */
    private array $users = [];

    protected function setUp(): void
    {
        $this->registry = new RedisRegistry(self::PREFIX_START . bin2hex(random_bytes(5)), 'acl-test', self::SECRET);
        (new RedisRegistryAdmin($this->direct(), $this->registry))->initialize();
    }

    protected function tearDown(): void
    {
        $client = $this->client();
        foreach ($this->users as $user) {
            $client->executeRaw(['ACL', 'DELUSER', $user]);
        }
        if ($this->registry !== null) {
            $cursor = '0';
            do {
                /** @var array{0: string, 1: list<string>} $page */
                $page = $client->scan($cursor, ['MATCH' => $this->registry->keyPrefix . ':*', 'COUNT' => 500]);
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

    private function registry(): RedisRegistry
    {
        return $this->registry ?? throw new \LogicException('No registry.');
    }

    /** @param list<string> $rules */
    private function userWith(array $rules): RedisConnectionOptions
    {
        $user = 'rt_acl_' . bin2hex(random_bytes(3));
        $password = 'pw-' . bin2hex(random_bytes(8));
        $reply = $this->client()->executeRaw(['ACL', 'SETUSER', $user, 'on', '>' . $password, ...$rules]);
        self::assertSame('OK', $reply, 'the rules were accepted by the server');
        $this->users[] = $user;
        $endpoint = RedisEndpoint::forTest();

        return new RedisConnectionOptions($endpoint->host, 2.0, 5.0, $endpoint->port, $user, $password);
    }

    /** @return array<string, mixed> every key of the registry with its complete content */
    private function snapshot(): array
    {
        $client = $this->client();
        /** @var list<string> $keys */
        $keys = $client->keys($this->registry()->keyPrefix . ':*');
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

    /** Every operation of the execution user, mutating one first. */
    private function executionFlow(RedisConnectionOptions $options): void
    {
        $registry = $this->registry();
        $ids = new ContractIdentities();
        $identity = ContractIdentities::identity('acl');

        $cooldown = new RedisCooldownBackend($options, $registry);
        $cooldownPolicy = new CooldownPolicy($identity, Duration::seconds(60));
        $cooldown->extend($cooldownPolicy, Duration::seconds(30), $ids->command());
        $cooldown->inspect($cooldownPolicy);

        $concurrency = new RedisConcurrencyBackend($options, $registry);
        $concurrencyPolicy = new ConcurrencyPolicy($identity, new PositiveInt(2), Duration::seconds(30));
        $admission = $concurrency->acquire($concurrencyPolicy, $ids->owner(), $ids->command());
        self::assertSame(ConcurrencyAdmissionStatus::Accepted, $admission->status);
        self::assertNotNull($admission->permit);
        $concurrency->release($concurrencyPolicy, $admission->permit, $ids->command());

        $rate = new RedisRateLimitBackend($options, $registry);
        $ratePolicy = new RateLimitPolicy($identity, new TokenBucketOptions(new PositiveInt(5), new PositiveInt(1), Duration::seconds(3600)));
        $rate->consume($ratePolicy, new PositiveInt(1), $ids->command());
        $rate->inspect($ratePolicy, new PositiveInt(1));

        $budget = new RedisRetryBudgetBackend($options, $registry);
        $budgetPolicy = new RetryBudgetPolicy($identity, new PositiveInt(5), new PositiveInt(1), Duration::seconds(3600), Duration::seconds(60));
        $reservation = $budget->reserve($budgetPolicy, $ids->attempt(), $ids->owner(), $ids->command())->reservation;
        self::assertNotNull($reservation);
        $budget->commit($budgetPolicy, $reservation, $ids->command());
        $budget->inspect($budgetPolicy);

        $circuit = new RedisCircuitBackend($options, $registry);
        $circuitPolicy = ContractCircuitRig::countPolicy(identity: $identity);
        $permit = $circuit->acquire($circuitPolicy, $ids->attempt(), $ids->owner(), $ids->command())->permit;
        self::assertNotNull($permit);
        $circuit->record($circuitPolicy, $permit, CircuitVerdict::Success, $ids->command());
        (new RedisCircuitAdmin($options, $registry))->reset($identity);

        (new RedisRegistryMaintenance($options, $registry))->step();
    }

    private function flushScripts(): void
    {
        self::assertSame('OK', $this->client()->executeRaw(['SCRIPT', 'FLUSH']));
    }

    public function testTheExecutionRulesAreEnoughForEveryOperation(): void
    {
        $options = $this->userWith(RedisAcl::execution($this->registry()));
        $this->flushScripts();

        $this->executionFlow($options);

        self::addToAssertionCount(1);
    }

    public function testTheAdministrationRulesAreEnoughForTheRegistryOperations(): void
    {
        $admin = new RedisRegistry('rt_acl_adm_' . bin2hex(random_bytes(4)), 'acl-admin-test', self::SECRET);
        $options = $this->userWith(RedisAcl::administration($admin));
        $this->flushScripts();
        $this->registry = $admin;

        $status = new RedisRegistryAdmin($options, $admin);
        self::assertTrue($status->initialize()->created);
        self::assertSame(0, $status->verify()->partitions);
        self::assertNotNull($status->inspectServer()->evictionPolicy);
        $status->retire();
        self::assertSame(0, $status->purgeRetired());
    }

    /** @return iterable<string, array{string}> */
    public static function executionRules(): iterable
    {
        foreach (RedisAcl::execution(new RedisRegistry('x', 'y', self::SECRET)) as $rule) {
            if (str_starts_with($rule, '+')) {
                yield $rule => [$rule];
            }
        }
    }

    #[DataProvider('executionRules')]
    public function testWithOneRuleRemovedTheFirstCallIsRefusedAndNothingIsWritten(string $removed): void
    {
        $rules = array_values(array_filter(RedisAcl::execution($this->registry()), static fn(string $rule): bool => $rule !== $removed));
        $options = $this->userWith($rules);
        $this->flushScripts();
        $before = $this->snapshot();

        try {
            $this->executionFlow($options);
            self::fail('The rule ' . $removed . ' is not needed: take it out of RedisAcl.');
        } catch (InfrastructureException $failure) {
            self::assertInstanceOf(\Enaxon\Reqtide\Exception\StateUnavailableException::class, $failure, 'a refusal is a definite unavailable result');
        }
        self::assertSame($before, $this->snapshot(), 'nothing was written');
    }

    public function testRulesForAnotherKeyPatternRefuseEverything(): void
    {
        $rules = array_map(static fn(string $rule): string => str_starts_with($rule, '~') ? '~someone_else:*' : $rule, RedisAcl::execution($this->registry()));
        $options = $this->userWith($rules);
        $this->flushScripts();
        $before = $this->snapshot();

        $this->expectException(\Enaxon\Reqtide\Exception\StateUnavailableException::class);
        try {
            $this->executionFlow($options);
        } finally {
            self::assertSame($before, $this->snapshot());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function administrationExtras(): iterable
    {
        foreach (['+scan', '+unlink', '+config|get', '+info'] as $rule) {
            yield $rule => [$rule];
        }
    }

    #[DataProvider('administrationExtras')]
    public function testEachAdministrationRuleIsNeeded(string $removed): void
    {
        $admin = new RedisRegistry('rt_acl_adm_' . bin2hex(random_bytes(4)), 'acl-admin-test', self::SECRET);
        $this->registry = $admin;
        $rules = array_values(array_filter(RedisAcl::administration($admin), static fn(string $rule): bool => $rule !== $removed));
        $options = $this->userWith($rules);
        $this->flushScripts();

        $registryAdmin = new RedisRegistryAdmin($options, $admin);
        $registryAdmin->initialize();
        $registryAdmin->retire();
        $this->client()->set($admin->keyPrefix . ':p:left:over', 'x');

        if ($removed === '+config|get') {
            self::assertSame(1, $registryAdmin->purgeRetired(), 'the purge still works');
            self::assertNull($registryAdmin->inspectServer()->evictionPolicy, 'without CONFIG GET the policy is reported as unknown');

            return;
        }
        if ($removed === '+info') {
            self::assertSame(1, $registryAdmin->purgeRetired(), 'the purge still works');
            self::assertNotNull($registryAdmin->inspectServer()->evictionPolicy, 'without INFO the version check is skipped; the scripts check for themselves');

            return;
        }
        $this->expectException(\Enaxon\Reqtide\Exception\StateUnavailableException::class);
        $registryAdmin->purgeRetired();
    }
}
