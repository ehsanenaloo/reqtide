<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Support;

use Psr\Http\Message\StreamInterface;

/** Seekable in-memory stream whose content can be replaced between attempts; the size is always current. */
final class MutableBodyStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(private string $content) {}

    public function replace(string $content): void
    {
        $this->content = $content;
        $this->position = 0;
    }

    public function __toString(): string
    {
        return $this->content;
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getSize(): int
    {
        return strlen($this->content);
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position >= strlen($this->content);
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->position = $whence === SEEK_END ? strlen($this->content) + $offset : ($whence === SEEK_CUR ? $this->position + $offset : $offset);
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
        $chunk = substr($this->content, $this->position, $length);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        return $this->read(PHP_INT_MAX);
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
