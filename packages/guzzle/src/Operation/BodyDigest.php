<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Operation;

use Enaxon\Reqtide\Exception\PreparationFailedException;
use Psr\Http\Message\StreamInterface;

/**
 * SHA-256 of a seekable body, computed in fixed-size chunks so the body is never held in memory. The stream is left at
 * its start.
 *
 * @internal
 */
final class BodyDigest
{
    private const CHUNK = 65536;

    /** @throws PreparationFailedException when the body cannot be read completely and rewound */
    public static function of(StreamInterface $body, int $expectedSize): string
    {
        if ($expectedSize === 0) {
            return hash('sha256', '');
        }
        try {
            if (!$body->isSeekable()) {
                throw HttpAttemptOperation::refuse('the body cannot be read twice, so its content cannot be verified; use a seekable stream or BodyIntegrity::CallerAsserted');
            }
            $body->rewind();
            $context = hash_init('sha256');
            $read = 0;
            while (!$body->eof()) {
                $chunk = $body->read(self::CHUNK);
                if ($chunk === '') {
                    break;
                }
                $read += strlen($chunk);
                hash_update($context, $chunk);
            }
            $body->rewind();
        } catch (PreparationFailedException $refusal) {
            throw $refusal;
        } catch (\Throwable $failure) {
            throw HttpAttemptOperation::refuse('the body could not be read to verify its content', $failure);
        }
        if ($read !== $expectedSize) {
            throw HttpAttemptOperation::refuse('the body is not as long as its size says');
        }

        return hash_final($context);
    }
}
