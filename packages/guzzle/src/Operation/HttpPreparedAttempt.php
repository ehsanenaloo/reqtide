<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Operation;

use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\OperationSafetyKind;
use Enaxon\Reqtide\Guzzle\HttpOperation;
use Enaxon\Reqtide\Outcome\Outcome;
use Enaxon\Reqtide\Outcome\OutcomeKind;
use Enaxon\Reqtide\Runtime\AttemptControls;
use Enaxon\Reqtide\Runtime\PreparedAttempt;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * One prepared transfer. `verifyReplay()` is the last check before dispatch (the body is still at its start and the
 * identity header is intact); `invoke()` performs the transfer through the injected sender; `discardOutcome()` closes
 * the response body of an outcome that retry or fallback replaces, so a connection is not kept open for a response
 * nobody will read. The terminal outcome is never given to it.
 *
 * @internal
 * @implements PreparedAttempt<ResponseInterface>
 */
final class HttpPreparedAttempt implements PreparedAttempt
{
    private ?AttemptControls $controls = null;

    /**
     * @param \Closure(RequestInterface, AttemptContext, ?AttemptControls, bool): ResponseInterface $send
     * @param ?\Closure(RequestInterface, AttemptContext, ?AttemptControls, bool): PromiseInterface $startAsync
     */
    public function __construct(private readonly HttpOperation $operation, private readonly RequestInterface $request, private readonly \Closure $send, private readonly ?\Closure $startAsync = null, private readonly ?BodyReference $reference = null, private readonly ?int $verifiedSize = null) {}

    /** Called by the runtime adapter, which alone receives the clock and deadline of the attempt. */
    public function bindControls(AttemptControls $controls): void
    {
        $this->controls = $controls;
    }

    public function verifyReplay(AttemptContext $context): void
    {
        if ($this->operation->descriptor->safety->kind === OperationSafetyKind::Unsafe) {
            return;
        }
        HttpAttemptOperation::checkIdentity($this->operation, $this->request);
        $body = $this->request->getBody();
        try {
            $moved = $body->getSize() !== 0 && $body->tell() !== 0;
        } catch (\Throwable $failure) {
            throw HttpAttemptOperation::refuse('the body position cannot be read', $failure);
        }
        if ($moved) {
            throw HttpAttemptOperation::refuse('the body is no longer at its start');
        }
        $reference = $this->reference;
        if ($reference !== null && $this->verifiedSize !== null && ($reference->sent || $reference->digest !== null)) {
            // A retry: read the body once, bounded by the size checked at preparation, and compare it with the reference.
            try {
                $sizeNow = $body->getSize();
            } catch (\Throwable $failure) {
                throw HttpAttemptOperation::refuse('the body size cannot be read', $failure);
            }
            if ($sizeNow !== $this->verifiedSize) {
                throw HttpAttemptOperation::refuse('the body content changed between attempts');
            }
            $digest = BodyDigest::of($body, $this->verifiedSize);
            if ($reference->digest !== null && !hash_equals($reference->digest, $digest)) {
                throw HttpAttemptOperation::refuse('the body content changed between attempts');
            }
            $reference->digest ??= $digest;
        }
    }

    /**
     * Starts the transfer and returns its pending promise, for the promise runtime, which then drives it. Nothing is
     * sent until the promise is driven.
     */
    public function start(AttemptContext $context): PromiseInterface
    {
        if ($this->startAsync === null) {
            throw new \LogicException('This operation was built for the synchronous runtime.');
        }

        return ($this->startAsync)($this->outgoing(), $context, $this->controls, $this->mayReuseConnection());
    }

    public function invoke(AttemptContext $context): ResponseInterface
    {
        return ($this->send)($this->outgoing(), $context, $this->controls, $this->mayReuseConnection());
    }

    /**
     * The request handed to the transport. Without a reference yet, its body computes the reference digest while the
     * transport reads it, so the first attempt costs no extra read. With a reference, the body is verified against it
     * while the transport reads it, so a change after the final check stops the transfer.
     */
    private function outgoing(): RequestInterface
    {
        $reference = $this->reference;
        // A body of size 0 was proven empty at preparation; there is nothing to verify while sending.
        if ($reference === null || $this->verifiedSize === null || $this->verifiedSize === 0) {
            return $this->request;
        }
        $body = new DigestingStream($this->request->getBody(), $this->verifiedSize, $reference->digest);
        if ($reference->digest === null) {
            $reference->track($body);
        }

        return $this->request->withBody($body);
    }

    private function mayReuseConnection(): bool
    {
        return $this->operation->descriptor->safety->kind !== OperationSafetyKind::Unsafe;
    }

    public function discardOutcome(Outcome $outcome): void
    {
        if ($outcome->kind === OutcomeKind::Value) {
            $value = $outcome->value();
            if ($value instanceof ResponseInterface) {
                $value->getBody()->close();
            }

            return;
        }
        $exception = $outcome->exception();
        if ($exception instanceof BadResponseException) {
            $exception->getResponse()->getBody()->close();
        }
    }
}
