<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline\Support;

/** Ordered record of every state, runtime and observer interaction of one test. */
final class CallLog
{
    /** @var list<string> */
    public array $entries = [];

    public function add(string $entry): void
    {
        $this->entries[] = $entry;
    }

    public function count(string $entry): int
    {
        return count(array_filter($this->entries, static fn(string $e): bool => $e === $entry));
    }

    public function has(string $entry): bool
    {
        return in_array($entry, $this->entries, true);
    }

    public function indexOf(string $entry): int
    {
        $index = array_search($entry, $this->entries, true);

        return $index === false ? -1 : $index;
    }

    /** @return list<string> */
    public function withPrefix(string $prefix): array
    {
        return array_values(array_filter($this->entries, static fn(string $e): bool => str_starts_with($e, $prefix)));
    }
}
