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
    public function __construct(private readonly HttpOperation $operation, private readonly RequestInterface $request, private readonly \Closure $send, private readonly ?\Closure $startAsync = null) {}

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

        return ($this->startAsync)($this->request, $context, $this->controls, $this->mayReuseConnection());
    }

    public function invoke(AttemptContext $context): ResponseInterface
    {
        return ($this->send)($this->request, $context, $this->controls, $this->mayReuseConnection());
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
