<?php

declare(strict_types=1);

namespace Comparison;

use Composer\InstalledVersions;

/**
 * Dispatches a scenario id to a method named after it (S01 -> s01()). Missing method => the adapter's
 * default NOT_APPLICABLE reason. Resets virtual time before every scenario.
 */
abstract class BaseAdapter implements Adapter
{
    /** @return string reason used for scenarios the adapter does not implement */
    abstract protected function scope(): string;

    public function run(array $scenario): Result
    {
        VirtualTime::reset();
        $method = strtolower((string) $scenario['id']);

        if (!method_exists($this, $method) && in_array($scenario['id'], ['S19', 'S20'], true)) {
            return $this->measure((string) $scenario['id']);
        }

        if (!method_exists($this, $method)) {
            return Result::na($this->scope());
        }

        try {
            return $this->{$method}();
        } catch (\Throwable $e) {
            // An adapter bug or an unexpected library exception is surfaced, never converted to a verdict.
            throw new \RuntimeException(sprintf('%s/%s crashed: %s: %s at %s:%d', $this->id(), $scenario['id'], $e::class, $e->getMessage(), $e->getFile(), $e->getLine()), 0, $e);
        }
    }

    private function measure(string $id): Result
    {
        $pkg = $this->describe()['package'];
        if ($id === 'S19') {
            $c = StaticMetrics::closure($pkg);

            return Result::info(sprintf('%d transitive runtime package(s): %s%s', $c['count'], $c['packages'] ? implode(', ', $c['packages']) : '(none)', $c['unresolved'] ? ' [virtual/provided names not resolved: ' . implode(', ', $c['unresolved']) . ']' : ''), $c);
        }
        $f = StaticMetrics::phpFloor($pkg);

        return Result::info(sprintf('own php constraint "%s" (floor %s); highest floor in closure: %s', $f['own'] ?? 'none declared', $f['own_floor'] ?? 'n/a', $f['closure_floor'] ?? 'n/a'), $f);
    }

    protected static function version(string $package): string
    {
        return InstalledVersions::getPrettyVersion($package) ?? 'unknown';
    }

    /** Format virtual seconds for observations. */
    protected static function s(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') . 's';
    }
}
