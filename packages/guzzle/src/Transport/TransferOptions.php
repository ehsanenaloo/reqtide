<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Transport;

use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Runtime\AttemptControls;
use Psr\Http\Message\RequestInterface;

/**
 * The per-attempt transfer policy shared by the synchronous and the promise transfer:
 *
 *  - the timeout is the smaller of the attempt timeout and the time left before the deadline, given as both `timeout`
 *    and `connect_timeout`; never `read_timeout`, which the cURL handlers ignore, and never zero, which
 *    would mean "wait forever". The deadline can only be read with the clock that created it, which only the runtime
 *    adapter has (in AttemptControls); without controls the attempt timeout alone applies;
 *  - `http_errors` is off and redirects are not followed, so a response is a value for the classifier and
 *    the client's own defaults cannot change what an outcome means;
 *  - a request with a known body size gets an empty `Expect` header, so cURL does not wait for `100 Continue`;
 *  - an operation without a safety statement (`single()`) neither reuses a connection nor leaves one open (FRESH_CONNECT
 *    and FORBID_REUSE). libcurl resends a request on a new connection, whatever its method, when a reused connection
 *    yields no bytes at all (Curl_retry_request); such a request must not be dispatched a second time without Reqtide
 *    counting it. Reads and mutations the server deduplicates by an identity may reuse connections: a repeat is safe.
 *    The per-request `curl` option replaces any `curl` client default.
 *
 * @internal
 */
final class TransferOptions
{
    private function __construct() {}

    /**
     * @return array{0: RequestInterface, 1: array<string, mixed>} the request to send and the Guzzle options
     */
    public static function forAttempt(RequestInterface $request, AttemptContext $context, ?AttemptControls $controls, bool $mayReuseConnection = false): array
    {
        $timeout = self::timeoutSeconds($context, $controls);
        if ($request->getBody()->getSize() !== null && !$request->hasHeader('Expect')) {
            $request = $request->withHeader('Expect', '');
        }

        $options = [
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'http_errors' => false,
            'allow_redirects' => false,
        ];
        if (!$mayReuseConnection) {
            $options['curl'] = [CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true];
        }

        return [$request, $options];
    }

    public static function timeoutSeconds(AttemptContext $context, ?AttemptControls $controls): float
    {
        $effective = $context->attemptTimeout;
        if ($controls !== null) {
            $effective = $effective->min($controls->deadline->remaining($controls->clock));
        }

        return max(0.001, $effective->nanoseconds / 1e9);
    }
}
