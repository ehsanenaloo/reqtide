<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Operation;

use Enaxon\Reqtide\Exception\PreparationFailedException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\OperationSafetyKind;
use Enaxon\Reqtide\Guzzle\BodyIntegrity;
use Enaxon\Reqtide\Guzzle\HttpOperation;
use Enaxon\Reqtide\Runtime\AttemptControls;
use Enaxon\Reqtide\Runtime\AttemptOperation;
use Enaxon\Reqtide\Runtime\PreparedAttempt;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Prepares one request for each attempt. For a replayable operation the body
 * is made ready again before the attempt is admitted: a seekable body is rewound and verified, a factory body is created
 * fresh. A body that cannot be made ready is refused with PreparationFailedException, so nothing is dispatched.
 * An operation declared as sent once (`HttpOperation::single()`) is passed through untouched.
 * The checks cover readability, the start position and the size, which is also recorded for an empty body. With
 * `BodyIntegrity::Verified` they also cover the content: the SHA-256 digest of the first body read completely (normally
 * computed while the first attempt is sent) is the reference, and the final check of every retry reads the body once and
 * compares. With `BodyIntegrity::CallerAsserted` the bytes are the caller's statement.
 *
 * One instance serves one logical operation, because it remembers the last factory stream and the body size it first saw.
 *
 * @internal
 * @implements AttemptOperation<ResponseInterface>
 */
final class HttpAttemptOperation implements AttemptOperation
{
    private ?StreamInterface $lastFactoryStream = null;

    private ?int $firstBodySize = null;

    private readonly BodyReference $reference;

    /**
     * @param \Closure(RequestInterface, AttemptContext, ?AttemptControls, bool): ResponseInterface $send
     * @param ?\Closure(RequestInterface, AttemptContext, ?AttemptControls, bool): PromiseInterface $startAsync
     */
    public function __construct(private readonly HttpOperation $operation, private readonly \Closure $send, private readonly ?\Closure $startAsync = null)
    {
        $this->reference = new BodyReference();
    }

    public function prepare(AttemptContext $context): PreparedAttempt
    {
        $request = $this->operation->request;
        $verifiedSize = null;
        $this->reference->harvest();
        if ($this->operation->descriptor->safety->kind !== OperationSafetyKind::Unsafe) {
            [$request, $verifiedSize] = $this->replayableRequest($request);
            self::checkIdentity($this->operation, $request);
        }

        return new HttpPreparedAttempt($this->operation, $request, $this->send, $this->startAsync, $verifiedSize === null ? null : $this->reference, $verifiedSize);
    }

    /** @return array{RequestInterface, ?int} the request to send and the size of its body when its content is verified */
    private function replayableRequest(RequestInterface $request): array
    {
        $factory = $this->operation->bodyFactory;
        if ($factory !== null) {
            try {
                $stream = $factory();
            } catch (\Throwable $failure) {
                throw self::refuse('the body factory failed', $failure);
            }
            if ($stream === $this->lastFactoryStream) {
                if (!$stream->isSeekable()) {
                    throw self::refuse('the body factory returned the same stream that cannot be rewound');
                }
                try {
                    $stream->rewind();
                } catch (\Throwable $failure) {
                    throw self::refuse('the body could not be rewound', $failure);
                }
            }
            $this->lastFactoryStream = $stream;
            $size = $stream->getSize();
            if ($size === null) {
                throw self::refuse('a factory body must have a known size');
            }
            self::checkDeclaredLength($request, $size);
            $this->verifyStart($stream, $size);
            return [$request->withBody($stream), $this->verifyContent($stream, $size)];
        }

        $body = $request->getBody();
        $size = $body->getSize();
        self::checkDeclaredLength($request, $size);
        if ($size === 0) {
            $this->verifySize(0);
            return [$request, $this->verifyContent($body, 0)];
        }
        if (!$body->isSeekable()) {
            throw self::refuse('the body cannot be rewound and no body factory was given');
        }
        try {
            $body->rewind();
        } catch (\Throwable $failure) {
            throw self::refuse('the body could not be rewound', $failure);
        }
        $this->verifyStart($body, $size);
        return [$request, $this->verifyContent($body, $size)];
    }

    private function verifyStart(StreamInterface $stream, ?int $size): void
    {
        try {
            $readable = $stream->isReadable();
            $position = $readable ? $stream->tell() : 0;
        } catch (\Throwable $failure) {
            throw self::refuse('the body position cannot be read', $failure);
        }
        if (!$readable) {
            throw self::refuse('the body is not readable');
        }
        if ($position !== 0) {
            throw self::refuse('the body is not at its start');
        }
        $this->verifySize($size);
    }

    /**
     * A declared Content-Length must be one decimal number equal to the size of the body that is sent, whether the body
     * comes from a factory or from the request. A body of unknown size cannot be checked against it and is refused.
     */
    private static function checkDeclaredLength(RequestInterface $request, ?int $size): void
    {
        if (!$request->hasHeader('Content-Length')) {
            return;
        }
        $declared = $request->getHeaderLine('Content-Length');
        if ($size === null || !ctype_digit($declared) || (int) $declared !== $size) {
            throw self::refuse('the body differs from the declared Content-Length');
        }
    }

    /**
     * Checks that the content of the body can be verified and marks it for verification; no byte is read here. The
     * prepared attempt reads the body once at its final check when a reference exists or an earlier attempt sent a
     * body, and otherwise lets the transport compute the reference while it sends (BodyReference). A body of size 0
     * is checked at once, by one byte.
     */
    private function verifyContent(StreamInterface $stream, ?int $size): ?int
    {
        if ($this->operation->bodyIntegrity !== BodyIntegrity::Verified) {
            return null;
        }
        if ($size === null) {
            throw self::refuse('the body has no known size, so its content cannot be verified; use BodyIntegrity::CallerAsserted');
        }
        if ($size > $this->operation->maxVerifiedBodyBytes) {
            throw self::refuse('the body is larger than maxVerifiedBodyBytes; raise the limit or use BodyIntegrity::CallerAsserted');
        }
        if (!$stream->isSeekable()) {
            throw self::refuse('the body cannot be read twice, so its content cannot be verified; use a seekable stream or BodyIntegrity::CallerAsserted');
        }
        if ($size === 0) {
            $digest = BodyDigest::of($stream, 0);
            if ($this->reference->digest !== null && !hash_equals($this->reference->digest, $digest)) {
                throw self::refuse('the body content changed between attempts');
            }
            $this->reference->digest ??= $digest;
        }
        return $size;
    }

    private function verifySize(?int $size): void
    {
        if ($this->firstBodySize !== null && $size !== $this->firstBodySize) {
            throw self::refuse('the body size changed between attempts');
        }
        $this->firstBodySize ??= $size;
    }

    /** @internal */
    public static function checkIdentity(HttpOperation $operation, RequestInterface $request): void
    {
        $header = $operation->identityHeader;
        $contract = $operation->descriptor->safety->contract;
        if ($header === null || $contract === null) {
            return;
        }
        if ($request->getHeader($header) !== [$contract->identityValue]) {
            throw self::refuse('the identity header is missing or differs from the stated identity');
        }
    }

    /** @internal */
    public static function refuse(string $reason, ?\Throwable $previous = null): PreparationFailedException
    {
        return new PreparationFailedException(new \RuntimeException('Request is not replayable: ' . $reason . '.', 0, $previous));
    }
}
