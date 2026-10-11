<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Error;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Internal\Codes;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\ProfileVersion;

/**
 * Immutable, redaction-safe error report in the member model of RFC 9457 problem details (no library import,
 * no HTTP status: Core knows no transport).
 *
 * Reading a problem
 * -----------------
 * `$exception->problem()` (any `ProblemCarrier`) and `ExecutionCompletion::problem()` return one. Branch on
 * `$problem->code` and its `category()`, `retryability()`; log or serialize with `toArray()` or
 * `json_encode($problem)`. `type()` is a stable tag URI (RFC 4151, `tag:enaloo.com,2026:reqtide:problem:<code>`), not a URL and not
 * dereferenceable. A caller may prefer its own base URI and can build one from `$problem->code->value`.
 *
 * Redaction by construction
 * -------------------------
 * The constructor accepts only registry enums, validated identities (OperationId, ProfileVersion, AttemptId),
 * a validated correlation id and cause summaries (short class name plus code). There is no parameter for a
 * message, URL, header, body, credential or free text, and `detail()` is composed from code metadata alone,
 * so such data cannot enter a problem. Foreign exception messages are never read.
 *
 * Correlation
 * -----------
 * `correlationId` is the caller-supplied id when given (`[A-Za-z0-9._-]{1,64}`), otherwise the operation id,
 * otherwise null. It is an identifier for log joining, not a secret.
 *
 * Stability
 * ---------
 * JSON member names and their order are API (see the golden tests); `title` and `detail` wording is not.
 * Consumers must ignore unknown members.
 */
final readonly class Problem implements \JsonSerializable
{
    public const TYPE_PREFIX = 'tag:enaloo.com,2026:reqtide:problem:';

    public ?Stage $stage;

    public ?string $correlationId;

    /** @var list<ProblemCause> */
    public array $causes;

    /**
     * @param Stage|null $stage defaults to the code's own stage
     * @param string|null $correlationId defaults to the operation id
     * @param list<ProblemCause> $causes at most ProblemCause::MAX_DEPTH
     */
    public function __construct(
        public ReasonCode $code,
        ?Stage $stage = null,
        public ?OperationId $operationId = null,
        public ?ProfileVersion $profileVersion = null,
        public ?AttemptId $attemptId = null,
        ?string $correlationId = null,
        array $causes = [],
    ) {
        if (!array_is_list($causes) || count($causes) > ProblemCause::MAX_DEPTH) {
            throw new InvalidConfigurationException('Causes must be a list of at most ' . ProblemCause::MAX_DEPTH . ' entries.');
        }
        foreach ($causes as $cause) {
            if (!$cause instanceof ProblemCause) {
                throw new InvalidConfigurationException('Every cause must be a ProblemCause.');
            }
        }
        $this->stage = $stage ?? $code->stage();
        $this->correlationId = $correlationId !== null ? Codes::code($correlationId, 'correlationId') : $operationId?->value;
        $this->causes = $causes;
    }

    /** Same problem with operation context attached; the correlation id is re-derived unless supplied. */
    public function withContext(OperationId $operationId, ProfileVersion $profileVersion, ?AttemptId $attemptId = null, ?string $correlationId = null): self
    {
        return new self($this->code, $this->stage, $operationId, $profileVersion, $attemptId, $correlationId, $this->causes);
    }

    public function type(): string
    {
        return self::TYPE_PREFIX . $this->code->value;
    }

    public function title(): string
    {
        return $this->code->title();
    }

    public function category(): Category
    {
        return $this->code->category();
    }

    public function severity(): Severity
    {
        return $this->code->severity();
    }

    public function retryability(): Retryability
    {
        return $this->code->retryability();
    }

    /** Bounded (at most 200 bytes), printable ASCII, composed only from code metadata. */
    public function detail(): string
    {
        $detail = $this->code->title() . '. Category: ' . $this->code->category()->value
            . '; retryability: ' . $this->code->retryability()->value
            . ($this->stage !== null ? '; stage: ' . $this->stage->value : '') . '.';

        return strlen($detail) > 200 ? substr($detail, 0, 200) : $detail;
    }

    /**
     * Stable key order: type, title, category, code, stage, severity, retryability, detail, operationId,
     * profileVersion, attemptId, correlationId, causes.
     *
     * @return array{
     *     type: string, title: string, category: string, code: string, stage: string|null, severity: string,
     *     retryability: string, detail: string, operationId: string|null, profileVersion: string|null,
     *     attemptId: string|null, correlationId: string|null, causes: list<array{class: string, code: string|null}>
     * }
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'title' => $this->title(),
            'category' => $this->category()->value,
            'code' => $this->code->value,
            'stage' => $this->stage?->value,
            'severity' => $this->severity()->value,
            'retryability' => $this->retryability()->value,
            'detail' => $this->detail(),
            'operationId' => $this->operationId?->value,
            'profileVersion' => $this->profileVersion?->value,
            'attemptId' => $this->attemptId?->lowerHex,
            'correlationId' => $this->correlationId,
            'causes' => array_map(static fn(ProblemCause $cause): array => $cause->toArray(), $this->causes),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
