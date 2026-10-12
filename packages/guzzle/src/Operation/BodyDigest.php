<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Operation;

use Enaxon\Reqtide\Exception\PreparationFailedException;
use Psr\Http\Message\StreamInterface;

/**
 * SHA-256 of a body, computed in fixed-size chunks so the body is never held in memory. The work is bounded by the
 * expected size: at most that many bytes plus one are read, and a body with more data than its size says is refused at
 * that extra byte, so a stream with a stale size or without an end cannot keep the reader going. A blocking `read()`
 * itself cannot be bounded in time here. The stream is left at its start.
 *
 * @internal
 */
final class BodyDigest
{
    private const CHUNK = 65536;

    /** @throws PreparationFailedException when the body cannot be read, is not exactly `$expectedSize` long, or cannot be rewound */
    public static function of(StreamInterface $body, int $expectedSize): string
    {
        try {
            if ($expectedSize === 0) {
                // Nothing can be sent twice; one byte is read only to prove that the body is really empty.
                if ($body->isSeekable()) {
                    $body->rewind();
                }
                $extra = $body->read(1);
                if ($body->isSeekable()) {
                    $body->rewind();
                }
                if ($extra !== '') {
                    throw HttpAttemptOperation::refuse('the body is longer than its size says');
                }

                return hash('sha256', '');
            }
            if (!$body->isSeekable()) {
                throw HttpAttemptOperation::refuse('the body cannot be read twice, so its content cannot be verified; use a seekable stream or BodyIntegrity::CallerAsserted');
            }
            $body->rewind();
            $context = hash_init('sha256');
            $read = 0;
            while ($read < $expectedSize) {
                $chunk = $body->read(min(self::CHUNK, $expectedSize - $read));
                if ($chunk === '') {
                    break;
                }
                $read += strlen($chunk);
                if ($read > $expectedSize) {
                    throw HttpAttemptOperation::refuse('the body is longer than its size says');
                }
                hash_update($context, $chunk);
            }
            if ($read < $expectedSize) {
                throw HttpAttemptOperation::refuse('the body is shorter than its size says');
            }
            if ($body->read(1) !== '') {
                throw HttpAttemptOperation::refuse('the body is longer than its size says');
            }
            $body->rewind();
        } catch (PreparationFailedException $refusal) {
            throw $refusal;
        } catch (\Throwable $failure) {
            throw HttpAttemptOperation::refuse('the body could not be read to verify its content', $failure);
        }

        return hash_final($context);
    }
}
