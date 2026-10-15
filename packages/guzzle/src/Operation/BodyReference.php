<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Operation;

/**
 * The payload one logical operation is bound to under `BodyIntegrity::Verified`. The reference digest is taken from the
 * first body that was read completely by the transport while it sent an attempt (no extra read). When a transfer read
 * only part of the body, the payload of the operation can no longer be established: {@see $partial} is set and the
 * operation is not sent again. When a transfer read no byte at all, nothing of the body was transmitted and the next
 * attempt takes the reference. Every later attempt must match the reference.
 *
 * @internal
 */
final class BodyReference
{
    public ?string $digest = null;

    /** True once an attempt handed its body to the transport. */
    public bool $sent = false;

    /** True when a transfer took part of the body but not all of it, so no digest of the whole payload exists. */
    public bool $partial = false;

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
            if ($this->digest === null && $this->pending->partiallyRead()) {
                $this->partial = true;
            }
        }
        $this->pending = null;
    }
}
