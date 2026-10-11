<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Value;

use Enaxon\Reqtide\Value\ApplicationId;
use Enaxon\Reqtide\Value\EnvironmentId;
use Enaxon\Reqtide\Value\PartitionIdentity;
use Enaxon\Reqtide\Value\PolicyId;
use Enaxon\Reqtide\Value\PolicyIdentity;
use Enaxon\Reqtide\Value\ProfileVersion;
use Enaxon\Reqtide\Value\SubjectId;
use Enaxon\Reqtide\Value\TenantId;
use PHPUnit\Framework\TestCase;

/**
 * Identity equality decides which quota a context may touch, so each component must matter on its own, and the
 * registry keys must keep the components apart.
 */
final class PartitionAndPolicyIdentityHardeningTest extends TestCase
{
    private static function partition(string $app = 'app', string $env = 'prod', ?string $tenant = null, string $subject = 'subject'): PartitionIdentity
    {
        return new PartitionIdentity(new ApplicationId($app), new EnvironmentId($env), $tenant === null ? null : new TenantId($tenant), new SubjectId($subject));
    }

    private static function policy(?PartitionIdentity $partition = null, string $version = 'v1', string $policy = 'rate'): PolicyIdentity
    {
        return new PolicyIdentity($partition ?? self::partition(), new ProfileVersion($version), new PolicyId($policy));
    }

    public function testPartitionsAreEqualOnlyWhenEveryComponentIsEqual(): void
    {
        $base = self::partition('app', 'prod', 't1', 'subject');

        self::assertTrue($base->equals(self::partition('app', 'prod', 't1', 'subject')));
        foreach ([
            'application' => self::partition('other', 'prod', 't1', 'subject'),
            'environment' => self::partition('app', 'stage', 't1', 'subject'),
            'tenant' => self::partition('app', 'prod', 't2', 'subject'),
            'subject' => self::partition('app', 'prod', 't1', 'other'),
            'tenant removed' => self::partition('app', 'prod', null, 'subject'),
        ] as $component => $different) {
            self::assertFalse($base->equals($different), $component . ' alone must make partitions differ');
            self::assertFalse($different->equals($base), $component . ' (reverse)');
        }
        self::assertTrue(self::partition('app', 'prod', null, 'subject')->equals(self::partition('app', 'prod', null, 'subject')), 'two absent tenants are equal');
    }

    public function testPoliciesAreEqualOnlyWhenEveryComponentIsEqual(): void
    {
        $base = self::policy();

        self::assertTrue($base->equals(self::policy()));
        foreach ([
            'partition' => self::policy(self::partition('other')),
            'profile version' => self::policy(null, 'v2'),
            'policy id' => self::policy(null, 'v1', 'circuit-breaker'),
        ] as $component => $different) {
            self::assertFalse($base->equals($different), $component . ' alone must make policies differ');
        }
    }

    public function testPartitionKeysAreExactAndKeepAbsentAndPresentTenantsApart(): void
    {
        self::assertSame("app\x1fprod\x1ft1\x1fsubject", self::partition('app', 'prod', 't1', 'subject')->key());
        self::assertSame("app\x1fprod\x1f\x1e\x1fsubject", self::partition('app', 'prod', null, 'subject')->key());
    }

    public function testPartitionKeysNeverCollideAcrossComponentBoundaries(): void
    {
        $partitions = [
            self::partition('a', 'bc', null, 's'), self::partition('ab', 'c', null, 's'),
            self::partition('a', 'b', 'c', 's'), self::partition('a', 'b', null, 'cs'),
            self::partition('a', 'b', 'cs', 's'), self::partition('ab', 'c', 's', 's'),
        ];
        $keys = array_map(static fn(PartitionIdentity $p): string => $p->key(), $partitions);

        self::assertSame($keys, array_values(array_unique($keys)));
    }

    public function testPolicyKeysAreExactAndNeverCollideAcrossComponentBoundaries(): void
    {
        $partition = self::partition('app', 'prod', null, 'subject');
        self::assertSame($partition->key() . "\x1fv1\x1frate", self::policy($partition, 'v1', 'rate')->key());

        $keys = [
            self::policy($partition, 'v1', 'rate')->key(),
            self::policy($partition, 'v1r', 'ate')->key(),
            self::policy($partition, 'v', '1rate')->key(),
            self::policy(self::partition('app', 'prod', null, 'subject'), 'v1rate', 'x')->key(),
        ];
        self::assertSame($keys, array_values(array_unique($keys)));
    }
}
