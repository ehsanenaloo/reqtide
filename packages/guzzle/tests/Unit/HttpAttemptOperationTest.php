<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Tests\Unit;

use Enaxon\Reqtide\Exception\PreparationFailedException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Guzzle\HttpOperation;
use Enaxon\Reqtide\Guzzle\Operation\HttpPreparedAttempt;
use Enaxon\Reqtide\Guzzle\Tests\Support\CloseSpyStream;
use Enaxon\Reqtide\Guzzle\Tests\Support\NonSeekableStream;
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

    protected function setUp(): void
    {
        $this->sent = [];
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
            $this->sent[] = $request;

            return new Response(200, [], 'ok');
        };
    }

    private function post(StreamInterface|string $body, ?\Closure $factory = null, string $key = 'key-1'): HttpOperation
    {
        return HttpOperation::idempotentMutation(
            new Request('POST', self::URL, ['Idempotency-Key' => $key], $body),
            'items.create',
            'key-1',
            'account and endpoint',
            'returns the stored response',
            Duration::seconds(60),
            bodyFactory: $factory,
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
        $operation = $this->post('placeholder', $factory);
        $attempt = $operation->attemptOperation($this->sender());

        for ($i = 0; $i < 3; ++$i) {
            $prepared = $attempt->prepare($this->context());
            $prepared->verifyReplay($this->context());
            $prepared->invoke($this->context());
        }
        self::assertSame(3, $created);
        self::assertSame(['abcdef', 'abcdef', 'abcdef'], array_map(static fn(RequestInterface $r): string => (string) $r->getBody(), $this->sent));
    }

    public function testAFactoryThatReturnsTheSameUnrewindableStreamTwiceIsRefusedTheSecondTime(): void
    {
        $stream = new NonSeekableStream(Utils::streamFor('abc'));
        $attempt = $this->post('x', static fn(): StreamInterface => $stream)->attemptOperation($this->sender());

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
