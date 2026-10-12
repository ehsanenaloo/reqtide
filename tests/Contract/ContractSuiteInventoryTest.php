<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Contract;

use PHPUnit\Framework\TestCase;

/**
 * Guards the shared backend contract against silent shrinkage: the number of test methods in every abstract suite is
 * pinned, and every suite must have at least one concrete subclass (the process-local backends today; the shared
 * backends of the Redis package run the same suites from there). Changing a number here is a deliberate review point:
 * a lower number needs a recorded reason, a higher number means new shared behaviour.
 */
final class ContractSuiteInventoryTest extends TestCase
{
    /** @var array<class-string<TestCase>, int> */
    private const EXPECTED_TEST_METHODS = [
        CooldownBackendContract::class => 9,
        ConcurrencyBackendContract::class => 17,
        RateLimitBackendContract::class => 20,
        RetryBudgetBackendContract::class => 20,
        CircuitBackendContract::class => 28,
    ];

    public function testEverySuiteKeepsItsPinnedNumberOfTests(): void
    {
        foreach (self::EXPECTED_TEST_METHODS as $suite => $expected) {
            $count = 0;
            foreach ((new \ReflectionClass($suite))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (str_starts_with($method->getName(), 'test') && $method->getDeclaringClass()->getName() === $suite) {
                    ++$count;
                }
            }
            self::assertSame($expected, $count, $suite . ' changed its number of test methods');
        }
    }

    public function testEverySuiteIsRunByAtLeastOneConcreteBackendTest(): void
    {
        $classes = get_declared_classes();
        foreach (array_keys(self::EXPECTED_TEST_METHODS) as $suite) {
            $concrete = array_filter(
                $classes,
                static fn(string $class): bool => is_subclass_of($class, $suite) && !(new \ReflectionClass($class))->isAbstract(),
            );
            self::assertNotSame([], $concrete, $suite . ' is not run by any concrete test class');
        }
    }
}
