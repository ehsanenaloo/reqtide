<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Support;

use Psr\Http\Message\StreamInterface;

/**
 * Seekable stream whose `getSize()` is fixed while reads return more data: up to `$available` bytes, or without end when
 * `$available` is null. It counts every byte handed out, so a test can bound the work of a reader.
 */
final class OverrunningStream implements StreamInterface
{
    public int $bytesRead = 0;

    private int $position = 0;

    public function __construct(private readonly int $declaredSize, private readonly ?int $available) {}

    public function __toString(): string
    {
        return '';
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getSize(): int
    {
        return $this->declaredSize;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->available !== null && $this->position >= $this->available;
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->position = $offset;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('read only');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        if ($length < 1) {
            return '';
        }
        $left = $this->available === null ? $length : max(0, min($length, $this->available - $this->position));
        $this->position += $left;
        $this->bytesRead += $left;

        return str_repeat('a', $left);
    }

    public function getContents(): string
    {
        throw new \LogicException('getContents() would not end on this stream');
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
