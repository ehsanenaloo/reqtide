<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/** Counts the bytes handed out by `read()`, so a test can prove how often a body was read. */
final class CountingStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public int $bytesRead = 0;

    public function __construct(private readonly StreamInterface $stream) {}

    public function read(int $length): string
    {
        $chunk = $this->stream->read($length);
        $this->bytesRead += strlen($chunk);

        return $chunk;
    }
}
