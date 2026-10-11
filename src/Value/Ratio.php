<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Value;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;

/** A reduced rational in (0, 1]; compared with integer products, never with floats. */
final readonly class Ratio
{
    public const MAX_COMPONENT = 9_007_199_254_740_991;

    public function __construct(public PositiveInt $numerator, public PositiveInt $denominator)
    {
        $n = $numerator->value;
        $d = $denominator->value;
        if ($n > $d) {
            throw new InvalidConfigurationException('Ratio numerator must not exceed its denominator.');
        }
        if ($d > self::MAX_COMPONENT) {
            throw new InvalidConfigurationException('Ratio components must not exceed 9007199254740991.');
        }
        if (self::gcd($n, $d) !== 1) {
            throw new InvalidConfigurationException('Ratio must be given in lowest terms.');
        }
    }

    /** Builds a reduced ratio from any positive pair. */
    public static function of(int $numerator, int $denominator): self
    {
        if ($numerator <= 0 || $denominator <= 0) {
            throw new InvalidConfigurationException('Ratio components must be positive.');
        }
        $g = self::gcd($numerator, $denominator);

        return new self(new PositiveInt(intdiv($numerator, $g)), new PositiveInt(intdiv($denominator, $g)));
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }
}
