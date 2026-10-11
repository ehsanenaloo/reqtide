<?php

declare(strict_types=1);

namespace Comparison;

final class Result implements \JsonSerializable
{
    /**
     * @param string|null $gap  For FAIL only: 'missing-capability' (library has no such feature by design),
     *                          'behaviour-defect' (feature exists and misbehaves), 'composition-dependent'.
     * @param array<string,mixed> $data Raw measurements behind the observation.
     */
    public function __construct(
        public readonly Status $status,
        public readonly string $observation,
        public readonly ?string $gap = null,
        public readonly array $data = [],
    ) {
    }

    public static function pass(string $obs, array $data = []): self
    {
        return new self(Status::Pass, $obs, null, $data);
    }

    public static function fail(string $obs, string $gap, array $data = []): self
    {
        return new self(Status::Fail, $obs, $gap, $data);
    }

    public static function na(string $why): self
    {
        return new self(Status::NotApplicable, $why);
    }

    public static function notExpressible(string $why): self
    {
        return new self(Status::NotExpressible, $why);
    }

    public static function info(string $obs, array $data = []): self
    {
        return new self(Status::Info, $obs, null, $data);
    }

    public function jsonSerialize(): array
    {
        return ['status' => $this->status->value, 'gap' => $this->gap, 'observation' => $this->observation, 'data' => $this->data];
    }
}
