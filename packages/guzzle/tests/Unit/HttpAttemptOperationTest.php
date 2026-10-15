<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Enaxon\Reqtide\Exception\PreparationFailedException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Guzzle\BodyIntegrity;
use Enaxon\Reqtide\Guzzle\HttpOperation;
use Enaxon\Reqtide\Guzzle\Operation\HttpPreparedAttempt;
use Enaxon\Reqtide\Guzzle\Tests\Support\CloseSpyStream;
use Enaxon\Reqtide\Guzzle\Tests\Support\CountingStream;
use Enaxon\Reqtide\Guzzle\Tests\Support\MutableBodyStream;
use Enaxon\Reqtide\Guzzle\Tests\Support\NonSeekableStream;
use Enaxon\Reqtide\Guzzle\Tests\Support\OverrunningStream;
use Enaxon\Reqtide\Guzzle\Tests\Support\RewindRefusingStream;
use Enaxon\Reqtide\Guzzle\Tests\Support\TellFailingStream;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Time\CancellationToken;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Time\SystemClock;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Replay preparation: a body is made ready again before each attempt or the attempt is
 * refused before anything is sent.
 */
final class HttpAttemptOperationTest extends TestCase
{
    private const URL = 'https://api.example.com/items';

    /** @var list<RequestInterface> */
    private array $sent = [];

    /** @var list<string> the bytes each send read from its body */
    private array $transmitted = [];

    protected function setUp(): void
    {
        $this->sent = [];
        $this->transmitted = [];
    }

    private function context(): AttemptContext
    {
        return new AttemptContext(
            new OperationId('op-1'),
            AttemptId::fromLowerHex(str_repeat('a', 32)),
            1,
            Deadline::after(Duration::seconds(5), new SystemClock()),
            new CancellationToken(),
            Duration::seconds(1),
        );
    }

    /** @return \Closure(RequestInterface): ResponseInterface */
    private function sender(): \Closure
    {
        return function (RequestInterface $request): ResponseInterface {
            $this->transmitted[] = self::transmit($request);
            $this->sent[] = $request;

            return new Response(200, [], 'ok');
        };
    }

    /**
     * Reads the body the way the Guzzle cURL handler does: as one string below 1 MB, otherwise in chunks up to its size.
     * Returns the bytes read.
     */
    private static function transmit(RequestInterface $request): string
    {
        $body = $request->getBody();
        $size = $body->getSize();
        if ($size !== null && $size < 1_000_000) {
            return (string) $body;
        }
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $sent = '';
        $remaining = $size ?? PHP_INT_MAX;
        while ($remaining > 0 && !$body->eof()) {
            $chunk = $body->read(min(65_536, $remaining));
            if ($chunk === '') {
                break;
            }
            $sent .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $sent;
    }

    /**
     * One attempt as the pipeline runs it: prepare, final check, send.
     *
     * @param \Enaxon\Reqtide\Runtime\AttemptOperation<ResponseInterface> $attempt
     */
    private function attemptOnce(\Enaxon\Reqtide\Runtime\AttemptOperation $attempt): void
    {
        $prepared = $attempt->prepare($this->context());
        $prepared->verifyReplay($this->context());
        $prepared->invoke($this->context());
    }

    /**
     * Prepares and checks an attempt and returns the reason it was refused.
     *
     * @param \Enaxon\Reqtide\Runtime\AttemptOperation<ResponseInterface> $attempt
     */
    private function refusalOf(\Enaxon\Reqtide\Runtime\AttemptOperation $attempt, ?\Closure $betweenPrepareAndCheck = null): string
    {
        try {
            $prepared = $attempt->prepare($this->context());
            if ($betweenPrepareAndCheck !== null) {
                $betweenPrepareAndCheck();
            }
            $prepared->verifyReplay($this->context());
        } catch (PreparationFailedException $e) {
            return $e->preparationCause->getMessage();
        }
        self::fail('expected the attempt to be refused before it was sent');
    }

    private function post(StreamInterface|string $body, ?\Closure $factory = null, string $key = 'key-1', BodyIntegrity $integrity = BodyIntegrity::Verified, int $maxBytes = HttpOperation::DEFAULT_MAX_VERIFIED_BODY_BYTES): HttpOperation
    {
        return HttpOperation::idempotentMutation(
            new Request('POST', self::URL, ['Idempotency-Key' => $key], $body),
            'items.create',
            'key-1',
            'account and endpoint',
            'returns the stored response',
            Duration::seconds(60),
            bodyFactory: $factory,
            bodyIntegrity: $integrity,
            maxVerifiedBodyBytes: $maxBytes,
        );
    }

    private function assertRefused(HttpOperation $operation, string $reasonPart): void
    {
        $attempt = $operation->attemptOperation($this->sender());
        try {
            $attempt->prepare($this->context());
            self::fail('expected the preparation to be refused');
        } catch (PreparationFailedException $e) {
            self::assertStringContainsString($reasonPart, $e->preparationCause->getMessage());
            self::assertStringNotContainsString('api.example.com', $e->preparationCause->getMessage(), 'no URL in the message');
        }
        self::assertSame([], $this->sent, 'nothing was dispatched');
    }

    public function testASeekableBodyThatWasConsumedIsRewoundBeforeEveryAttempt(): void
    {
        $body = Utils::streamFor('payload');
        $operation = $this->post($body);
        $attempt = $operation->attemptOperation($this->sender());

        for ($i = 0; $i < 3; ++$i) {
            $body->getContents(); // an earlier attempt read it to the end
            $prepared = $attempt->prepare($this->context());
            $prepared->verifyReplay($this->context());
            $prepared->invoke($this->context());
            self::assertCount($i + 1, $this->sent);
            self::assertSame('payload', (string) $this->sent[$i]->getBody());
        }
        self::assertCount(3, $this->sent);
    }

    public function testABodyThatRefusesToRewindIsRefusedBeforeDispatch(): void
    {
        $this->assertRefused($this->post(new RewindRefusingStream(Utils::streamFor('abc'))), 'could not be rewound');
    }

    public function testANonSeekableBodyWithoutAFactoryIsRefusedForEveryReplayableKind(): void
    {
        $pump = static fn(): PumpStream => new PumpStream(static fn(): false => false);
        $this->assertRefused($this->post($pump()), 'no body factory was given');

        $read = HttpOperation::read(new Request('GET', self::URL, [], $pump()), 'g', Duration::seconds(30));
        $this->assertRefused($read, 'no body factory was given');
    }

    public function testASingleOperationMayStreamAnUnrewindableBody(): void
    {
        $operation = HttpOperation::single(new Request('POST', self::URL, [], new PumpStream(static fn(): false => false)), 'g');
        $prepared = $operation->attemptOperation($this->sender())->prepare($this->context());
        $prepared->verifyReplay($this->context());
        $prepared->invoke($this->context());
        self::assertCount(1, $this->sent);
    }

    public function testAFactoryGivesAFreshBodyForEveryAttempt(): void
    {
        $created = 0;
        // A non-seekable fresh stream of known size on every call.
        $factory = static function () use (&$created): StreamInterface {
            ++$created;

            return new NonSeekableStream(Utils::streamFor('abcdef'));
        };
        $operation = $this->post('placeholder', $factory, integrity: BodyIntegrity::CallerAsserted);
        $attempt = $operation->attemptOperation($this->sender());

        for ($i = 0; $i < 3; ++$i) {
            $prepared = $attempt->prepare($this->context());
            $prepared->verifyReplay($this->context());
            $prepared->invoke($this->context());
        }
        self::assertSame(3, $created);
        self::assertSame(['abcdef', 'abcdef', 'abcdef'], $this->transmitted, 'each send read a fresh factory body');
    }

    public function testAFactoryThatReturnsTheSameUnrewindableStreamTwiceIsRefusedTheSecondTime(): void
    {
        $stream = new NonSeekableStream(Utils::streamFor('abc'));
        $attempt = $this->post('x', static fn(): StreamInterface => $stream, integrity: BodyIntegrity::CallerAsserted)->attemptOperation($this->sender());

        $attempt->prepare($this->context());
        $this->expectException(PreparationFailedException::class);
        $attempt->prepare($this->context());
    }

    public function testAFactoryStreamThatWasAlreadyConsumedIsRefused(): void
    {
        $stream = Utils::streamFor('abc');
        $stream->getContents();
        $this->assertRefused($this->post('x', static fn(): StreamInterface => $stream), 'not at its start');
    }

    public function testFactoryDefectsAreRefused(): void
    {
        $this->assertRefused($this->post('x', static fn(): StreamInterface => throw new \LogicException('boom')), 'the body factory failed');
        $this->assertRefused($this->post('x', static fn(): StreamInterface => new PumpStream(static fn(): false => false)), 'known size');

        $declared = HttpOperation::idempotentMutation(
            new Request('POST', self::URL, ['Idempotency-Key' => 'key-1', 'Content-Length' => '10'], 'x'),
            'g',
            'key-1',
            's',
            'd',
            Duration::seconds(60),
            bodyFactory: static fn(): StreamInterface => Utils::streamFor('abc'),
        );
        $this->assertRefused($declared, 'declared Content-Length');
    }

    public function testTheBodySizeMustNotChangeBetweenAttempts(): void
    {
        $sizes = ['abc', 'abcd'];
        $operation = $this->post('x', static function () use (&$sizes): StreamInterface {
            return Utils::streamFor(array_shift($sizes) ?? '');
        });
        $attempt = $operation->attemptOperation($this->sender());
        $attempt->prepare($this->context());
        $this->expectException(PreparationFailedException::class);
        $attempt->prepare($this->context());
    }

    public function testAnEmptyBodyNeedsNothing(): void
    {
        $operation = HttpOperation::read(new Request('GET', self::URL), 'g', Duration::seconds(30));
        $attempt = $operation->attemptOperation($this->sender());
        $attempt->prepare($this->context())->invoke($this->context());
        $attempt->prepare($this->context())->invoke($this->context());
        self::assertCount(2, $this->sent);
    }

    public function testABodyThatBecomesEmptyBetweenAttemptsIsRefused(): void
    {
        $body = new MutableBodyStream('amount=100');
        $attempt = $this->post($body)->attemptOperation($this->sender());
        $attempt->prepare($this->context());

        $body->replace('');

        try {
            $attempt->prepare($this->context());
            self::fail('expected the preparation to be refused');
        } catch (PreparationFailedException $e) {
            self::assertStringContainsString('size changed', $e->preparationCause->getMessage());
        }
    }

    public function testABodyThatBecomesNonEmptyBetweenAttemptsIsRefused(): void
    {
        $body = new MutableBodyStream('');
        $attempt = $this->post($body)->attemptOperation($this->sender());
        $attempt->prepare($this->context());

        $body->replace('amount=900');

        try {
            $attempt->prepare($this->context());
            self::fail('expected the preparation to be refused');
        } catch (PreparationFailedException $e) {
            self::assertStringContainsString('size changed', $e->preparationCause->getMessage());
        }
    }

    public function testAnEmptyBodyThatStaysEmptyIsAcceptedEveryTime(): void
    {
        $attempt = $this->post(new MutableBodyStream(''))->attemptOperation($this->sender());

        $first = $attempt->prepare($this->context());
        $attempt->prepare($this->context());
        $attempt->prepare($this->context());

        self::assertInstanceOf(HttpPreparedAttempt::class, $first);
    }

    public function testABodyWithTheSameSizeButOtherBytesIsRefusedWhenTheContentIsVerified(): void
    {
        $contents = ['amount=100', 'amount=900'];
        $attempt = $this->post('x', static function () use (&$contents): StreamInterface {
            return Utils::streamFor(array_shift($contents) ?? '');
        })->attemptOperation($this->sender());

        $this->attemptOnce($attempt);

        self::assertStringContainsString('content changed', $this->refusalOf($attempt));
        self::assertCount(1, $this->sent, 'the retry with other bytes was never sent');
    }

    public function testABodyWithTheSameBytesIsAcceptedEveryTimeWhenTheContentIsVerified(): void
    {
        $attempt = $this->post('x', static fn(): StreamInterface => Utils::streamFor('amount=100'))->attemptOperation($this->sender());

        for ($i = 0; $i < 3; ++$i) {
            $prepared = $attempt->prepare($this->context());
            $prepared->verifyReplay($this->context());
            $prepared->invoke($this->context());
        }

        self::assertSame(['amount=100', 'amount=100', 'amount=100'], array_map(static fn(RequestInterface $r): string => (string) $r->getBody(), $this->sent));
    }

    public function testASeekableBodyThatChangesItsBytesIsRefusedWhenTheContentIsVerified(): void
    {
        $body = new MutableBodyStream('amount=100');
        $attempt = $this->post($body)->attemptOperation($this->sender());
        $this->attemptOnce($attempt);

        $body->replace('amount=900');

        self::assertStringContainsString('content changed', $this->refusalOf($attempt));
        self::assertCount(1, $this->sent);
    }

    public function testTheFinalCheckOfARetryCatchesBytesChangedAfterPreparation(): void
    {
        $body = new MutableBodyStream('amount=100');
        $attempt = $this->post($body)->attemptOperation($this->sender());
        $this->attemptOnce($attempt);

        $reason = $this->refusalOf($attempt, static function () use ($body): void {
            $body->replace('amount=900');
        });

        self::assertStringContainsString('content changed', $reason);
        self::assertCount(1, $this->sent);
    }

    public function testTheFinalCheckRefusesABodyThatGrewAfterPreparationWithoutReadingTheGrowth(): void
    {
        $body = new MutableBodyStream('amount=100');
        $attempt = $this->post($body)->attemptOperation($this->sender());
        $this->attemptOnce($attempt);

        $reason = $this->refusalOf($attempt, static function () use ($body): void {
            $body->replace(str_repeat('x', 5_000_000));
        });

        self::assertStringContainsString('content changed', $reason);
        self::assertSame(0, $body->tell());
        self::assertCount(1, $this->sent);
    }

    public function testABodyWhoseSizeIsStaleIsRefusedAtTheFirstExtraByteOfTheRetryCheck(): void
    {
        $body = new OverrunningStream(10, 2_000_000);
        $attempt = $this->post($body)->attemptOperation($this->sender());
        $this->attemptOnce($attempt);
        $readBySend = $body->bytesRead;

        self::assertStringContainsString('longer than its size', $this->refusalOf($attempt));
        self::assertSame(11, $body->bytesRead - $readBySend, 'the check reads the size plus one byte, not the whole stream');
    }

    public function testTheFirstAttemptReadsTheBodyOnlyOnceAndThatReadIsTheSend(): void
    {
        $payload = str_repeat('0123456789', 200_000);
        $body = new CountingStream(Utils::streamFor($payload));
        $attempt = $this->post($body)->attemptOperation($this->sender());

        $this->attemptOnce($attempt);

        self::assertSame(strlen($payload), $body->bytesRead, 'no read before the send');
    }

    public function testARetryReadsTheBodyOnceAtItsFinalCheckBesideTheSend(): void
    {
        $payload = str_repeat('0123456789', 200_000);
        $body = new CountingStream(Utils::streamFor($payload));
        $attempt = $this->post($body)->attemptOperation($this->sender());
        $this->attemptOnce($attempt);
        $afterFirst = $body->bytesRead;

        $this->attemptOnce($attempt);

        self::assertSame(2 * strlen($payload), $body->bytesRead - $afterFirst, 'one read at the final check and one by the send');
        self::assertCount(2, $this->sent);
    }

    public function testWhenTheFirstSendDidNotReadTheWholeBodyTheRetryTakesTheReference(): void
    {
        $body = new MutableBodyStream('amount=100');
        $calls = 0;
        $sender = function (RequestInterface $request) use (&$calls): ResponseInterface {
            if (++$calls > 1) {
                self::transmit($request);
            }
            $this->sent[] = $request;

            return new Response(503);
        };
        $attempt = $this->post($body)->attemptOperation($sender);
        $this->attemptOnce($attempt);

        $this->attemptOnce($attempt);
        $body->replace('amount=900');

        self::assertStringContainsString('content changed', $this->refusalOf($attempt));
        self::assertCount(2, $this->sent);
    }

    /** @return \Closure(RequestInterface): ResponseInterface a send that reads only a prefix of the body, as an interrupted upload does */
    private function prefixSender(int $prefixBytes): \Closure
    {
        return function (RequestInterface $request) use ($prefixBytes): ResponseInterface {
            $body = $request->getBody();
            $body->rewind();
            $this->transmitted[] = $body->read($prefixBytes);
            $this->sent[] = $request;

            return new Response(503);
        };
    }

    public function testAfterAPartialSendTheRetryOfAChangedBodyOfTheSameSizeIsRefused(): void
    {
        $bodies = [str_repeat('a', 2_000_000), str_repeat('b', 2_000_000)];
        $calls = 0;
        $factory = static function () use ($bodies, &$calls): StreamInterface {
            return Utils::streamFor($bodies[min($calls++, 1)]);
        };
        $attempt = $this->post($bodies[0], $factory)->attemptOperation($this->prefixSender(100_000));
        $this->attemptOnce($attempt);

        $reason = $this->refusalOf($attempt);

        self::assertStringContainsString('only part of the body', $reason);
        self::assertCount(1, $this->sent, 'the second attempt was not sent');
    }

    public function testAfterAPartialSendEvenTheSameBodyIsNotRetriedBecauseItsContentCannotBeVerified(): void
    {
        $body = Utils::streamFor(str_repeat('a', 2_000_000));
        $attempt = $this->post($body)->attemptOperation($this->prefixSender(100_000));
        $this->attemptOnce($attempt);

        self::assertStringContainsString('only part of the body', $this->refusalOf($attempt));
    }

    public function testAfterAPartialSendACallerAssertedBodyIsStillRetried(): void
    {
        $body = Utils::streamFor(str_repeat('a', 2_000_000));
        $attempt = $this->post($body, null, 'key-1', BodyIntegrity::CallerAsserted)->attemptOperation($this->prefixSender(100_000));
        $this->attemptOnce($attempt);

        $this->attemptOnce($attempt);

        self::assertCount(2, $this->sent);
    }

    public function testAfterASendThatReadNoByteTheRetryStillTakesTheReference(): void
    {
        $sender = function (RequestInterface $request): ResponseInterface {
            $this->sent[] = $request;

            return new Response(503);
        };
        $attempt = $this->post(str_repeat('a', 2_000_000))->attemptOperation($sender);
        $this->attemptOnce($attempt);

        $this->attemptOnce($attempt);

        self::assertCount(2, $this->sent);
    }

    public function testAfterAFullReadFollowedByALostResponseTheRetryIsVerifiedAgainstTheFullDigest(): void
    {
        $body = new MutableBodyStream(str_repeat('a', 2_000_000));
        $attempt = $this->post($body)->attemptOperation($this->sender());
        $this->attemptOnce($attempt);
        $body->replace(str_repeat('b', 2_000_000));

        self::assertStringContainsString('content changed', $this->refusalOf($attempt));
    }

    public function testABodyChangedAfterTheFinalCheckOfARetryIsStoppedBeforeItIsSent(): void
    {
        $body = new MutableBodyStream('amount=100');
        $attempt = $this->post($body)->attemptOperation($this->sender());
        $this->attemptOnce($attempt);

        $prepared = $attempt->prepare($this->context());
        $prepared->verifyReplay($this->context());
        $body->replace('amount=900');

        try {
            $prepared->invoke($this->context());
            self::fail('expected the send to be stopped');
        } catch (\RuntimeException $stopped) {
            self::assertStringContainsString('changed after its final check', $stopped->getMessage());
        }
        self::assertCount(1, $this->sent, 'the changed body was never handed over');
    }

    public function testALargeBodyChangedAfterTheFinalCheckOfARetryIsStoppedAtItsLastChunk(): void
    {
        $payload = str_repeat('a', 1_200_000);
        $body = new MutableBodyStream($payload);
        $attempt = $this->post($body)->attemptOperation($this->sender());
        $this->attemptOnce($attempt);

        $prepared = $attempt->prepare($this->context());
        $prepared->verifyReplay($this->context());
        $body->replace(str_repeat('a', 1_199_999) . 'b');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('changed after its final check');
        $prepared->invoke($this->context());
    }

    public function testAnUnchangedRetryIsSentWhole(): void
    {
        $body = new MutableBodyStream('amount=100');
        $attempt = $this->post($body)->attemptOperation($this->sender());

        $this->attemptOnce($attempt);
        $this->attemptOnce($attempt);

        self::assertSame(['amount=100', 'amount=100'], $this->transmitted);
    }

    public function testTheBytesAreTheCallersResponsibilityWhenTheCallerAssertsThem(): void
    {
        $contents = ['amount=100', 'amount=900'];
        $attempt = $this->post('x', static function () use (&$contents): StreamInterface {
            return Utils::streamFor(array_shift($contents) ?? '');
        }, integrity: BodyIntegrity::CallerAsserted)->attemptOperation($this->sender());

        $first = $attempt->prepare($this->context());
        $second = $attempt->prepare($this->context());
        $first->invoke($this->context());
        $second->invoke($this->context());

        self::assertSame(['amount=100', 'amount=900'], array_map(static fn(RequestInterface $r): string => (string) $r->getBody(), $this->sent));
    }

    public function testABodyThatCannotBeReadTwiceIsRefusedWhenTheContentIsVerified(): void
    {
        $this->assertRefused(
            $this->post('x', static fn(): StreamInterface => new NonSeekableStream(Utils::streamFor('abc'))),
            'content cannot be verified',
        );
    }

    public function testABodyLargerThanTheLimitIsRefusedWhenTheContentIsVerified(): void
    {
        $this->assertRefused($this->post('0123456789', null, 'key-1', BodyIntegrity::Verified, 9), 'maxVerifiedBodyBytes');

        $this->post('0123456789', null, 'key-1', BodyIntegrity::Verified, 10)->attemptOperation($this->sender())->prepare($this->context());
        $this->post('0123456789', null, 'key-1', BodyIntegrity::CallerAsserted, 1)->attemptOperation($this->sender())->prepare($this->context());
        self::assertSame([], $this->sent);
    }

    public function testTheContentOfALargeBodyIsReadInChunksAndTheStreamIsLeftAtItsStart(): void
    {
        $body = Utils::streamFor(str_repeat('abcdefgh', 100_000));
        $prepared = $this->post($body)->attemptOperation($this->sender())->prepare($this->context());

        $prepared->verifyReplay($this->context());
        self::assertSame(0, $body->tell());
    }

    /** @return iterable<string, array{string, string}> */
    public static function wrongLengths(): iterable
    {
        yield 'too small' => ['abcdef', '3'];
        yield 'too large' => ['abcdef', '999'];
        yield 'not a number' => ['abcdef', 'abc'];
        yield 'two values' => ['abcdef', '6, 6'];
        yield 'empty body with a length' => ['', '5'];
    }

    #[DataProvider('wrongLengths')]
    public function testADeclaredContentLengthMustMatchADirectBody(string $body, string $length): void
    {
        $operation = HttpOperation::idempotentMutation(
            new Request('POST', self::URL, ['Idempotency-Key' => 'key-1', 'Content-Length' => $length], $body),
            'items.create',
            'key-1',
            'account and endpoint',
            'returns the stored response',
            Duration::seconds(60),
        );

        $this->assertRefused($operation, 'Content-Length');
    }

    #[DataProvider('wrongLengths')]
    public function testADeclaredContentLengthMustMatchAFactoryBody(string $body, string $length): void
    {
        $operation = HttpOperation::idempotentMutation(
            new Request('POST', self::URL, ['Idempotency-Key' => 'key-1', 'Content-Length' => $length], 'x'),
            'items.create',
            'key-1',
            'account and endpoint',
            'returns the stored response',
            Duration::seconds(60),
            bodyFactory: static fn(): StreamInterface => Utils::streamFor($body),
        );

        $this->assertRefused($operation, 'Content-Length');
    }

    public function testAMatchingContentLengthIsAcceptedOnBothPaths(): void
    {
        foreach ([null, static fn(): StreamInterface => Utils::streamFor('abcdef')] as $factory) {
            HttpOperation::idempotentMutation(
                new Request('POST', self::URL, ['Idempotency-Key' => 'key-1', 'Content-Length' => '6'], 'abcdef'),
                'items.create',
                'key-1',
                's',
                'd',
                Duration::seconds(60),
                bodyFactory: $factory,
            )->attemptOperation($this->sender())->prepare($this->context());
        }
        self::assertSame([], $this->sent);
    }

    public function testTheStatedIdentityMustBePresentOnEveryAttempt(): void
    {
        $this->assertRefused($this->post('x', null, 'a-different-key'), 'identity header');

        $missing = HttpOperation::idempotentMutation(new Request('POST', self::URL, [], 'x'), 'g', 'key-1', 's', 'd', Duration::seconds(60));
        $this->assertRefused($missing, 'identity header');

        $elsewhere = HttpOperation::idempotentMutation(new Request('POST', self::URL, [], 'x'), 'g', 'key-1', 's', 'd', Duration::seconds(60), identityHeader: null);
        $elsewhere->attemptOperation($this->sender())->prepare($this->context());
        self::assertSame([], $this->sent);
    }

    public function testTheFinalCheckCatchesABodyThatMovedAfterPreparation(): void
    {
        $body = Utils::streamFor('payload');
        $prepared = $this->post($body)->attemptOperation($this->sender())->prepare($this->context());
        $body->getContents();

        $this->expectException(PreparationFailedException::class);
        $prepared->verifyReplay($this->context());
    }

    public function testDisposalClosesOnlyTheResponseOfADiscardedOutcome(): void
    {
        $prepared = $this->post('x')->attemptOperation($this->sender())->prepare($this->context());
        $spy = new CloseSpyStream(Utils::streamFor('discarded'));

        $prepared->discardOutcome(Outcome::fromValue(new Response(503, [], $spy), BoundedMetadata::empty()));
        self::assertSame(1, $spy->closed);

        $errorSpy = new CloseSpyStream(Utils::streamFor('error body'));
        $error = new ClientException('m', new Request('GET', self::URL), new Response(429, [], $errorSpy));
        $prepared->discardOutcome(Outcome::fromException($error, BoundedMetadata::empty()));
        self::assertSame(1, $errorSpy->closed);

        // Outcomes without a response own nothing to dispose.
        $prepared->discardOutcome(Outcome::fromException(new ConnectException('c', new Request('GET', self::URL)), BoundedMetadata::empty()));
        self::assertSame(1, $spy->closed);
    }

    public function testACloseFailureIsLeftToCoreToTurnIntoADiagnostic(): void
    {
        $prepared = $this->post('x')->attemptOperation($this->sender())->prepare($this->context());
        $spy = new CloseSpyStream(Utils::streamFor('x'), true);

        $this->expectException(\RuntimeException::class);
        $prepared->discardOutcome(Outcome::fromValue(new Response(503, [], $spy), BoundedMetadata::empty()));
    }
    public function testAFactoryThatReturnsTheSameSeekableStreamEveryTimeIsRewoundAndSentWhole(): void
    {
        $stream = Utils::streamFor('abcdef');
        $attempt = $this->post('x', static fn(): StreamInterface => $stream)->attemptOperation($this->sender());

        for ($i = 0; $i < 3; ++$i) {
            $prepared = $attempt->prepare($this->context());
            $prepared->verifyReplay($this->context());
            $prepared->invoke($this->context());
            $stream->getContents();
        }
        self::assertCount(3, $this->sent);
    }

    public function testAnUnreadablePositionIsARefusalAndNotARawException(): void
    {
        $this->assertRefused($this->post(new TellFailingStream(Utils::streamFor('abc'))), 'position cannot be read');
        $this->assertRefused($this->post('x', static fn(): StreamInterface => new TellFailingStream(Utils::streamFor('abc'))), 'position cannot be read');

        $broken = new HttpPreparedAttempt(
            $this->post('x'),
            new Request('POST', self::URL, ['Idempotency-Key' => 'key-1'], new TellFailingStream(Utils::streamFor('abc'))),
            $this->sender(),
        );
        $this->expectException(PreparationFailedException::class);
        $broken->verifyReplay($this->context());
    }
}
