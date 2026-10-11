<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Unit;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Redis\RedisRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedisRegistryTest extends TestCase
{
    private const SECRET = 'a-host-managed-secret-of-32-bytes!!';

    public function testDefaultsAreTheDocumentedCandidateCeilings(): void
    {
        $registry = new RedisRegistry('rt', 'eu1-prod', self::SECRET);
        self::assertSame(10_000, $registry->maxPartitions);
        self::assertSame(20_000, $registry->maxRecords);
        self::assertSame('rt:m', $registry->keys()->metadata());
    }

    /**
     * @return iterable<string, array{string, string, int, int}>
     */
    public static function invalid(): iterable
    {
        yield 'empty identity' => ['rt', '', 1, 1];
        yield 'identity with space' => ['rt', 'a b', 1, 1];
        yield 'identity too long' => ['rt', str_repeat('i', 65), 1, 1];
        yield 'zero partitions' => ['rt', 'id', 0, 1];
        yield 'zero records' => ['rt', 'id', 1, 0];
        yield 'ceiling too large' => ['rt', 'id', 1_000_001, 1];
        yield 'bad prefix' => ['r:t', 'id', 1, 1];
    }

    #[DataProvider('invalid')]
    public function testInvalidIdentityPrefixAndCeilingsAreRejected(string $prefix, string $identity, int $partitions, int $records): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new RedisRegistry($prefix, $identity, self::SECRET, $partitions, $records);
    }

    public function testAShortSecretIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new RedisRegistry('rt', 'id', 'too-short');
    }
}
