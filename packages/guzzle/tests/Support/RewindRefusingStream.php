<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/** A stream that claims to be seekable but refuses to rewind or seek. */
final class RewindRefusingStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public function __construct(private readonly StreamInterface $stream) {}

    public function getSize(): ?int
    {
        return $this->stream->getSize();
    }

    public function rewind(): void
    {
        throw new \RuntimeException('rewind refused');
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('seek refused');
    }
}
