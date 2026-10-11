<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/** A readable, seekable stream of known size whose position cannot be read. */
final class TellFailingStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public function __construct(private readonly StreamInterface $stream) {}

    public function getSize(): ?int
    {
        return $this->stream->getSize();
    }

    public function tell(): int
    {
        throw new \RuntimeException('tell failed');
    }
}
