<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Operation;

use Enaxon\Reqtide\Exception\PreparationFailedException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\OperationSafetyKind;
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

    /**
     * @param \Closure(RequestInterface, AttemptContext, ?AttemptControls, bool): ResponseInterface $send
     * @param ?\Closure(RequestInterface, AttemptContext, ?AttemptControls, bool): PromiseInterface $startAsync
     */
    public function __construct(private readonly HttpOperation $operation, private readonly \Closure $send, private readonly ?\Closure $startAsync = null) {}

    public function prepare(AttemptContext $context): PreparedAttempt
    {
        $request = $this->operation->request;
        if ($this->operation->descriptor->safety->kind !== OperationSafetyKind::Unsafe) {
            $request = $this->replayableRequest($request);
            self::checkIdentity($this->operation, $request);
        }

        return new HttpPreparedAttempt($this->operation, $request, $this->send, $this->startAsync);
    }

    private function replayableRequest(RequestInterface $request): RequestInterface
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
            $declared = $request->getHeaderLine('Content-Length');
            if ($declared !== '' && (!ctype_digit($declared) || (int) $declared !== $size)) {
                throw self::refuse('the factory body differs from the declared Content-Length');
            }
            $this->verifyStart($stream, $size);

            return $request->withBody($stream);
        }

        $body = $request->getBody();
        $size = $body->getSize();
        if ($size === 0) {
            return $request;
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

        return $request;
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
