<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Operation;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Body decorator that computes the SHA-256 of the bytes the transport reads, while it reads them. Guzzle sends a body
 * below 1 MB as one string (`__toString()`) and a larger one through `read()` up to its size; both go through `read()`
 * here. The digest counts only for one sequential read from position 0 that ends exactly at the expected size; a seek
 * to 0 starts again, any other seek or a longer read voids it.
 *
 * With an expected digest (a retry, whose body was checked at its final check) the bytes are verified while they are
 * handed over: a body below 1 MB that differs is refused before Guzzle has anything to send, and a larger one is stopped
 * at its last chunk, so the server never receives a complete request with another payload.
 *
 * @internal
 */
final class DigestingStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private \HashContext $context;

    private int $read = 0;

    private bool $valid = true;

    private ?string $digest = null;

    private bool $touched = false;

    private bool $overran = false;

    public function __construct(private readonly StreamInterface $stream, private readonly int $expectedSize, private readonly ?string $expectedDigest = null)
    {
        $this->context = hash_init('sha256');
    }

    public function __toString(): string
    {
        $this->seek(0);
        $content = $this->getContents();
        if ($this->expectedDigest !== null && $this->completeDigest() === null) {
            throw self::changed();
        }

        return $content;
    }

    /** The digest of exactly the expected bytes read in order from the start, or null when that has not happened. */
    public function completeDigest(): ?string
    {
        return $this->valid && $this->read === $this->expectedSize ? $this->digest : null;
    }

    /**
     * True when the transport took at least one byte but never the whole body in order from its start. A body that
     * turned out longer than its size is not partial; the check of the next attempt reports that.
     */
    public function partiallyRead(): bool
    {
        return $this->touched && !$this->overran && $this->completeDigest() === null;
    }

    public function read(int $length): string
    {
        $chunk = $this->stream->read($length);
        if ($chunk !== '') {
            $this->touched = true;
        }
        if ($this->valid && $chunk !== '') {
            $this->read += strlen($chunk);
            if ($this->read > $this->expectedSize) {
                $this->overran = true;
                $this->valid = false;
                $this->digest = null;
            } else {
                hash_update($this->context, $chunk);
                if ($this->read === $this->expectedSize) {
                    $this->digest = hash_final(hash_copy($this->context));
                }
            }
            if ($this->expectedDigest !== null && ($this->read > $this->expectedSize || ($this->digest !== null && !hash_equals($this->expectedDigest, $this->digest)))) {
                throw self::changed();
            }
        }

        return $chunk;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);
        if ($offset === 0 && $whence === SEEK_SET) {
            $this->context = hash_init('sha256');
            $this->read = 0;
            $this->valid = true;
            $this->digest = null;

            return;
        }
        $this->valid = false;
        $this->digest = null;
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    private static function changed(): \RuntimeException
    {
        return new \RuntimeException('Request is not replayable: the body changed after its final check; the transfer was stopped.');
    }
}
