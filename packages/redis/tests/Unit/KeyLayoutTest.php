<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Unit;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Redis\State\KeyLayout;
use Enaxon\Reqtide\Redis\State\StateKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeyLayoutTest extends TestCase
{
    private const SECRET = 'a-host-managed-secret-of-32-bytes!!';

    private function partition(KeyLayout $layout, string $tenant = 'acme', string $subject = 'user-4711'): string
    {
        return $layout->partition(StateKind::RateLimit, 'billing', 'prod', 'api-calls', 'v3', $tenant, $subject);
    }

    public function testMetadataAndPartitionKeysHaveTheDocumentedShape(): void
    {
        $layout = new KeyLayout('rt.eu1', self::SECRET);
        self::assertSame('rt.eu1:m', $layout->metadata());
        self::assertMatchesRegularExpression('~^rt\.eu1:p:rl:billing:prod:api-calls:v3:[0-9a-f]{32}$~', $this->partition($layout));
    }

    public function testTenantAndSubjectNeverAppearInAKey(): void
    {
        $layout = new KeyLayout('rt', self::SECRET);
        $key = $this->partition($layout, 'Acme Corp', 'alice@example.com');
        foreach (['Acme', 'acme', 'Corp', 'alice', 'example.com', '4711'] as $leak) {
            self::assertStringNotContainsStringIgnoringCase($leak, $key);
        }
        self::assertStringNotContainsString(self::SECRET, $key);
    }

    public function testKeysAreStableAndDependOnTheSecretAndOnBothDimensions(): void
    {
        $layout = new KeyLayout('rt', self::SECRET);
        self::assertSame($this->partition($layout), $this->partition($layout));
        self::assertNotSame($this->partition($layout), $this->partition(new KeyLayout('rt', self::SECRET . 'x')));
        self::assertNotSame($this->partition($layout, 'a', 'b'), $this->partition($layout, 'a', 'c'));
        self::assertNotSame($this->partition($layout, 'a', 'b'), $this->partition($layout, 'z', 'b'));
    }

    public function testFieldBoundariesAreUnambiguous(): void
    {
        $layout = new KeyLayout('rt', self::SECRET);
        self::assertNotSame($this->partition($layout, 'ab', 'c'), $this->partition($layout, 'a', 'bc'));
        self::assertNotSame($this->partition($layout, '', 'ab'), $this->partition($layout, 'ab', ''));
    }

    public function testEveryKindHasItsOwnSegment(): void
    {
        $layout = new KeyLayout('rt', self::SECRET);
        $keys = array_map(
            static fn(StateKind $kind): string => $layout->partition($kind, 'a', 'e', 'p', 'v', 't', 's'),
            StateKind::cases(),
        );
        self::assertCount(5, array_unique($keys));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function badTokens(): iterable
    {
        yield 'colon in application' => ['a:b', 'prod', 'p', 'v'];
        yield 'space in environment' => ['a', 'pr od', 'p', 'v'];
        yield 'empty policy' => ['a', 'prod', '', 'v'];
        yield 'slash in profile' => ['a', 'prod', 'p', 'v/1'];
        yield 'too long' => [str_repeat('a', 65), 'prod', 'p', 'v'];
        yield 'newline' => ['a', "prod\n", 'p', 'v'];
    }

    #[DataProvider('badTokens')]
    public function testReadableSegmentsAreStrictTokens(string $application, string $environment, string $policy, string $profile): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new KeyLayout('rt', self::SECRET))->partition(StateKind::Cooldown, $application, $environment, $policy, $profile, 't', 's');
    }

    public function testPrefixSecretAndDimensionLimitsAreEnforced(): void
    {
        foreach ([fn() => new KeyLayout('', self::SECRET), fn() => new KeyLayout('a:b', self::SECRET), fn() => new KeyLayout('rt', 'short')] as $make) {
            try {
                $make();
                self::fail('expected an exception');
            } catch (InvalidConfigurationException) {
            }
        }
        $layout = new KeyLayout('rt', self::SECRET);
        self::assertNotSame('', $layout->partition(StateKind::Circuit, 'a', 'e', 'p', 'v', str_repeat('t', 4096), 's'));
        $this->expectException(InvalidConfigurationException::class);
        $layout->partition(StateKind::Circuit, 'a', 'e', 'p', 'v', str_repeat('t', 4097), 's');
    }

    public function testTheSecretIsHiddenAndNotSerializable(): void
    {
        foreach ([new KeyLayout('rt', self::SECRET), new RedisRegistry('rt', 'id', self::SECRET)] as $object) {
            ob_start();
            var_dump($object);
            self::assertStringNotContainsString(self::SECRET, (string) ob_get_clean());
            try {
                serialize($object);
                self::fail('expected an exception');
            } catch (\LogicException $e) {
                self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            }
        }
    }
}
