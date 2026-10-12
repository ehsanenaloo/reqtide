<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Enaxon\Reqtide\Exception\PreparationFailedException;
use Enaxon\Reqtide\Guzzle\Operation\BodyDigest;
use Enaxon\Reqtide\Guzzle\Tests\Support\MutableBodyStream;
use Enaxon\Reqtide\Guzzle\Tests\Support\NonSeekableStream;
use Enaxon\Reqtide\Guzzle\Tests\Support\OverrunningStream;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

/** The digest never reads more than the declared size plus one byte, whatever the stream returns. */
final class BodyDigestTest extends TestCase
{
    private static function refusal(StreamInterface $body, int $size): string
    {
        try {
            BodyDigest::of($body, $size);
        } catch (PreparationFailedException $e) {
            return $e->preparationCause->getMessage();
        }
        self::fail('expected the digest to refuse the body');
    }

    public function testABodyLongerThanItsDeclaredSizeIsRefusedAfterOneExtraByte(): void
    {
        $body = new OverrunningStream(1024, 10 * 1024 * 1024);

        self::assertStringContainsString('longer than its size', self::refusal($body, 1024));
        self::assertSame(1025, $body->bytesRead);
    }

    public function testABodyThatNeverEndsIsRefusedAfterOneExtraByte(): void
    {
        $body = new OverrunningStream(10, null);

        self::assertStringContainsString('longer than its size', self::refusal($body, 10));
        self::assertSame(11, $body->bytesRead);
    }

    public function testAnEmptyDeclaredBodyThatHasDataIsRefused(): void
    {
        $body = new OverrunningStream(0, 5);

        self::assertStringContainsString('longer than its size', self::refusal($body, 0));
        self::assertSame(1, $body->bytesRead);
    }

    public function testABodyShorterThanItsDeclaredSizeIsRefused(): void
    {
        self::assertStringContainsString('shorter than its size', self::refusal(new OverrunningStream(100, 40), 100));
    }

    public function testAnEmptyBodyHasTheDigestOfNothing(): void
    {
        self::assertSame(hash('sha256', ''), BodyDigest::of(Utils::streamFor(''), 0));
        self::assertSame(hash('sha256', ''), BodyDigest::of(new NonSeekableStream(Utils::streamFor('')), 0));
    }

    public function testTheDigestIsTheSha256OfTheBodyAndTheStreamIsLeftAtItsStart(): void
    {
        $payload = str_repeat('0123456789abcdef', 20_000);
        $body = new MutableBodyStream($payload);

        self::assertSame(hash('sha256', $payload), BodyDigest::of($body, strlen($payload)));
        self::assertSame(0, $body->tell());
    }

    public function testANonSeekableBodyIsRefused(): void
    {
        self::assertStringContainsString('cannot be read twice', self::refusal(new NonSeekableStream(Utils::streamFor('abc')), 3));
    }
}
