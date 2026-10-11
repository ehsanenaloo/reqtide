<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/** Counts close() calls; optionally throws from close(). */
final class CloseSpyStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public int $closed = 0;

    public function __construct(private readonly StreamInterface $stream, private readonly bool $failOnClose = false) {}

    public function close(): void
    {
        ++$this->closed;
        if ($this->failOnClose) {
            throw new \RuntimeException('close failed');
        }
        $this->stream->close();
    }
}
