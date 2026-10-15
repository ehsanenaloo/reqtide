<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle;

/**
 * How a replayable request body is protected from changing between attempts of one operation.
 *
 * `Verified` (the default) reads the body through a SHA-256 digest, without buffering it, while the first attempt sends it
 * and again before every later attempt and at the final check before dispatch. A body whose digest differs is refused before
 * anything is sent, so one identity never carries two payloads. The body must be seekable (a body factory must return a
 * seekable stream) and at most `maxVerifiedBodyBytes` long. When a transfer took only part of the body, the payload can no
 * longer be established and the operation is not sent again.
 *
 * `CallerAsserted` checks only that the body can be made ready again, its start position and its size. The caller states
 * that the bytes stay the same. Use it for a body that is too large to read again or that cannot be rewound.
 */
enum BodyIntegrity
{
    case Verified;
    case CallerAsserted;
}
