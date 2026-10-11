<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Error;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;

/**
 * One link of a problem's cause chain: the SHORT class name of the throwable and, for Reqtide's own
 * exceptions, its reason code. Messages, traces, files, lines and previous-chain details are never captured.
 */
final readonly class ProblemCause implements \JsonSerializable
{
    /** Longest chain a problem will carry. */
    public const MAX_DEPTH = 8;

    public const MAX_CLASS_LENGTH = 64;

    public function __construct(public string $class, public ?ReasonCode $code = null)
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/', $class) !== 1) {
            throw new InvalidConfigurationException('A cause class must be a short class name of at most 64 characters.');
        }
    }

    /** Summary of one throwable. The reason code is present only when it is a ProblemCarrier. */
    public static function of(\Throwable $throwable): self
    {
        return new self(self::shortName($throwable), $throwable instanceof ProblemCarrier ? $throwable->reason() : null);
    }

    /**
     * The `previous` chain starting at $first, in causal order, at most MAX_DEPTH long, visiting each object once
     * (a hostile `getPrevious()` that loops cannot hang or grow the result).
     *
     * @return list<self>
     */
    public static function chainFrom(?\Throwable $first): array
    {
        $chain = [];
        $seen = [];
        for ($current = $first; $current !== null && count($chain) < self::MAX_DEPTH; $current = $current->getPrevious()) {
            $id = spl_object_id($current);
            if (isset($seen[$id])) {
                break;
            }
            $seen[$id] = true;
            $chain[] = self::of($current);
        }

        return $chain;
    }

    /** @return array{class: string, code: string|null} */
    public function toArray(): array
    {
        return ['class' => $this->class, 'code' => $this->code?->value];
    }

    /** @return array{class: string, code: string|null} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function shortName(\Throwable $throwable): string
    {
        $name = $throwable::class;
        if (str_contains($name, '@anonymous')) {
            // An anonymous class name embeds a file path: keep only what precedes it.
            $name = strstr($name, '@', true);
            $name = $name === false || $name === '' || $name === 'class' ? 'AnonymousThrowable' : $name;
        }
        $separator = strrpos($name, '\\');
        $short = $separator === false ? $name : substr($name, $separator + 1);
        $short = substr($short, 0, self::MAX_CLASS_LENGTH);

        return preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $short) === 1 ? $short : 'Throwable';
    }
}
