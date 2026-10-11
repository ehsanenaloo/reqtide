<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/** A readable stream of known size that cannot be rewound. */
final class NonSeekableStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public function __construct(private readonly StreamInterface $stream) {}

    public function getSize(): ?int
    {
        return $this->stream->getSize();
    }

    public function isSeekable(): bool
    {
        return false;
    }
}
