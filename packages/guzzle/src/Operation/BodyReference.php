<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Operation;

/**
 * The payload one logical operation is bound to under `BodyIntegrity::Verified`. The reference digest is taken from the
 * first body that was read completely: by the transport while it sent the first attempt (no extra read), or, when that
 * transfer did not read the whole body, at the final check of the next attempt. Every later attempt must match it.
 *
 * @internal
 */
final class BodyReference
{
    public ?string $digest = null;

    /** True once an attempt handed its body to the transport. */
    public bool $sent = false;

    private ?DigestingStream $pending = null;

    public function track(DigestingStream $body): void
    {
        $this->pending = $body;
        $this->sent = true;
    }

    /** Takes the digest of the body the last transfer read completely, if there was one and no reference exists yet. */
    public function harvest(): void
    {
        if ($this->digest === null && $this->pending !== null) {
            $this->digest = $this->pending->completeDigest();
        }
        $this->pending = null;
    }
}
