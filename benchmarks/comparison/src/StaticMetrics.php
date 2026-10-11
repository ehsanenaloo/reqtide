<?php

declare(strict_types=1);

namespace Comparison;

/** Dependency-count and PHP-floor measurements computed from composer.lock (no heuristics about behaviour). */
final class StaticMetrics
{
    /** @var array<string,array<string,mixed>>|null */
    private static ?array $lock = null;

    /** @return array<string,array<string,mixed>> */
    private static function lock(): array
    {
        if (self::$lock === null) {
            $raw = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
            self::$lock = [];
            foreach ($raw['packages'] as $p) {
                self::$lock[$p['name']] = $p;
            }
        }

        return self::$lock;
    }

    private static function isPlatform(string $name): bool
    {
        return $name === 'php' || str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-') || $name === 'php-64bit' || str_starts_with($name, 'composer-');
    }

    /**
     * @param array<string,string> $require
     * @return array{count:int, packages:list<string>, unresolved:list<string>, direct:list<string>}
     */
    public static function closure(string $package): array
    {
        $lock = self::lock();
        $seen = [];
        $unresolved = [];
        $stack = [$package];
        while ($stack) {
            $cur = array_pop($stack);
            foreach (array_keys($lock[$cur]['require'] ?? []) as $dep) {
                if (self::isPlatform($dep) || isset($seen[$dep])) {
                    continue;
                }
                if (!isset($lock[$dep])) {
                    $unresolved[$dep] = true; // virtual / provided package name
                    continue;
                }
                $seen[$dep] = true;
                $stack[] = $dep;
            }
        }
        $direct = array_values(array_filter(array_keys($lock[$package]['require'] ?? []), static fn (string $d): bool => !self::isPlatform($d)));
        $names = array_keys($seen);
        sort($names);

        return ['count' => \count($names), 'packages' => $names, 'unresolved' => array_keys($unresolved), 'direct' => $direct];
    }

    private static function floorOf(string $constraint): ?string
    {
        $floors = [];
        foreach (preg_split('/\s*\|\|?\s*/', $constraint) ?: [] as $alt) {
            if (preg_match('/(\d+(?:\.\d+){0,2})/', $alt, $m)) {
                $floors[] = $m[1];
            }
        }
        if ($floors === []) {
            return null;
        }
        usort($floors, 'version_compare');

        return $floors[0];
    }

    /** @return array{own:?string, own_floor:?string, closure_floor:?string, closure:array<string,string>} */
    public static function phpFloor(string $package): array
    {
        $lock = self::lock();
        $own = $lock[$package]['require']['php'] ?? null;
        $closure = [];
        foreach (array_merge([$package], self::closure($package)['packages']) as $p) {
            if (isset($lock[$p]['require']['php'])) {
                $closure[$p] = $lock[$p]['require']['php'];
            }
        }
        $floors = array_filter(array_map([self::class, 'floorOf'], $closure));
        usort($floors, 'version_compare');

        return ['own' => $own, 'own_floor' => $own === null ? null : self::floorOf($own), 'closure_floor' => $floors ? end($floors) : null, 'closure' => $closure];
    }
}
