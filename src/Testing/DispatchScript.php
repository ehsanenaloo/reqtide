<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Testing;

use Enaxon\Reqtide\Time\CancellationToken;
use Enaxon\Reqtide\Value\Duration;

/**
 * Immutable description of what one ControlledRuntimeAdapter dispatch does.
 *
 * Within a dispatch the order is fixed: request cancellation (if scripted), advance the virtual
 * clock (if scripted), then produce the result. The result is a returned value, a thrown exception,
 * or the real PreparedAttempt::invoke() call-through.
 *
 * @api-for-tests
 */
final readonly class DispatchScript
{
    private function __construct(
        public bool $callsThrough,
        public mixed $value,
        public ?\Throwable $exception,
        public Duration $advance,
        public ?CancellationToken $cancelToken,
    ) {}

    public static function returning(mixed $value): self
    {
        return new self(false, $value, null, Duration::zero(), null);
    }

    public static function throwing(\Throwable $exception): self
    {
        return new self(false, null, $exception, Duration::zero(), null);
    }

    /** Invoke the prepared operation for real and capture its original return or throw. */
    public static function callingOperation(): self
    {
        return new self(true, null, null, Duration::zero(), null);
    }

    /** Advance the shared VirtualClock by $duration while the call is "blocked", before it settles. */
    public function advancing(Duration $duration): self
    {
        return new self($this->callsThrough, $this->value, $this->exception, $duration, $this->cancelToken);
    }

    /** Request cancellation on $token during the call. The call still settles; nothing is interrupted. */
    public function requestingCancellation(CancellationToken $token): self
    {
        return new self($this->callsThrough, $this->value, $this->exception, $this->advance, $token);
    }
}
