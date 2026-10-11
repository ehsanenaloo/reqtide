<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Support;

/**
 * Test vectors for the exact-integer arithmetic, generated from an independent
 * decimal big-integer oracle (bcmath) and never from the code under test. The same file is run against the Lua library
 * on a Redis server and against the Core PHP arithmetic, so both implementations meet one bar.
 *
 * Generation is deterministic and independent of the PHP version (own linear congruential generator, fixed seed).
 * `tools/generate-exact-int-vectors.php` writes tests/Vectors/exact_int.json; a unit test fails when the file is stale.
 */
final class ExactIntVectors
{
    public const SEED = 20261009;

    public const EXACT_MAX = '9007199254740991';

    private int $state = self::SEED;

    private function __construct() {}

    /**
     * @return array<string, array<mixed>>
     */
    public static function generate(): array
    {
        $generator = new self();

        return [
            'meta' => [
                'oracle' => 'bcmath',
                'seed' => self::SEED,
                'exactMax' => self::EXACT_MAX,
                'note' => 'All numbers are decimal strings. Groups: parse, format, mad (mulAddDivFloorCapped), cmp (compareProducts), msc (mulSubCeilDivCapped), cws (compareWeightedSum), big (limb operations beyond the exact range).',
            ],
            'parse' => $generator->parseCases(),
            'format' => $generator->formatCases(),
            'mad' => $generator->madCases(),
            'cmp' => $generator->cmpCases(),
            'msc' => $generator->mscCases(),
            'cws' => $generator->cwsCases(),
            'big' => $generator->bigCases(),
        ];
    }

    /**
     * One case per line keeps the committed file diffable and compact.
     *
     * @param array<string, array<mixed>> $vectors
     */
    public static function encode(array $vectors): string
    {
        $parts = [];
        foreach ($vectors as $group => $cases) {
            if ($group === 'meta') {
                $parts[] = '"meta": ' . json_encode($cases, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                continue;
            }
            if ($group === 'big') {
                $inner = [];
                foreach ($cases as $name => $list) {
                    \assert(is_array($list));
                    $inner[] = '    ' . json_encode($name, JSON_THROW_ON_ERROR) . ": [\n" . self::lines($list, '      ') . "\n    ]";
                }
                $parts[] = '"big": {' . "\n" . implode(",\n", $inner) . "\n  }";
                continue;
            }
            $parts[] = json_encode($group, JSON_THROW_ON_ERROR) . ": [\n" . self::lines($cases, '    ') . "\n  ]";
        }

        return "{\n  " . implode(",\n  ", $parts) . "\n}\n";
    }

    /**
     * @param array<mixed> $cases
     */
    private static function lines(array $cases, string $indent): string
    {
        return implode(",\n", array_map(
            static fn(mixed $case): string => $indent . json_encode($case, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $cases,
        ));
    }

    /**
     * @return list<array{0: string, 1: string|null}> input and canonical value, or null when it must be rejected
     */
    private function parseCases(): array
    {
        $valid = ['0', '1', '9', '10', '8192', '8193', '4294967296', '9007199254740990', '9007199254740991'];
        $invalid = [
            '', '-0', '-1', '+1', '00', '01', ' 1', '1 ', "1\n", '1.0', '1e3', '0x10', '1,2', 'abc', "\u{FF11}",
            '9007199254740992', '9007199254740993', '9999999999999999', '10000000000000000', '12345678901234567890',
            '00000000000000001',
        ];
        $cases = [];
        foreach ($valid as $text) {
            $cases[] = [$text, $text];
        }
        foreach ($invalid as $text) {
            $cases[] = [$text, null];
        }

        return $cases;
    }

    /**
     * @return list<array{0: string, 1: string|null}>
     */
    private function formatCases(): array
    {
        $cases = [];
        foreach (['0', '1', '8192', '4294967296', '9007199254740990', '9007199254740991'] as $text) {
            $cases[] = [$text, $text];
        }
        foreach (['-1', '1.5', '0.5', '9007199254740992', '1e300', 'nan', 'inf', '-inf'] as $text) {
            $cases[] = [$text, null];
        }

        return $cases;
    }

    /**
     * @return list<string>
     */
    private function grid(): array
    {
        return [
            '0', '1', '2', '3', '8191', '8192', '8193', '67108863', '67108864', '94906265', '94906266',
            '2147483647', '4294967296', '1099511627776', '4503599627370496', '9007199254740990', '9007199254740991',
        ];
    }

    /**
     * mulAddDivFloorCapped cases: a, b, addend, divisor, cap, then quotient, remainder, capped ("0" or "1").
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: string}>
     */
    private function madCases(): array
    {
        $max = self::EXACT_MAX;
        $grid = $this->grid();
        $divisors = ['1', '2', '3', '8192', '1000000', '67108871', '4503599627370496', $max];
        $cases = [];
        foreach ($grid as $a) {
            foreach ($grid as $b) {
                foreach ($divisors as $d) {
                    foreach (['0', '1'] as $r) {
                        foreach ([$max, '12345'] as $cap) {
                            $cases[] = $this->mad($a, $b, $r, $d, $cap);
                        }
                    }
                }
            }
        }
        // Remainder, addend and cap edges.
        foreach ([['9007199254740990', '1', $max, '2'], ['94906265', '94906266', '1', '1'], ['3', '3', '0', '9'], ['3', '3', '8', '9'], ['3', '3', '9', '9']] as [$a, $b, $r, $d]) {
            foreach (['0', '1', '2', $max] as $cap) {
                $cases[] = $this->mad($a, $b, $r, $d, $cap);
            }
        }
        // Random operands of 1 to 53 bits.
        for ($i = 0; $i < 4000; ++$i) {
            $d = $this->randomBits(1, 53);
            $cap = $this->randomBits(1, 53);
            $cases[] = $this->mad($this->randomBits(0, 53), $this->randomBits(0, 53), $this->randomBits(0, 53), $d === '0' ? '1' : $d, $i % 5 === 0 ? $max : $cap);
        }

        return $cases;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: string}
     */
    private function mad(string $a, string $b, string $r, string $d, string $cap): array
    {
        $numerator = self::add(self::mul($a, $b, 0), $r, 0);
        $quotient = self::div($numerator, $d, 0);
        if (self::comp($quotient, $cap) >= 0) {
            return [$a, $b, $r, $d, $cap, $cap, '0', '1'];
        }

        return [$a, $b, $r, $d, $cap, $quotient, self::mod($numerator, $d, 0), '0'];
    }

    /**
     * compareProducts cases: a, b, c, d, then "lt", "eq" or "gt" for a*b versus c*d.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private function cmpCases(): array
    {
        $grid = $this->grid();
        $cases = [];
        foreach ($grid as $a) {
            foreach ($grid as $b) {
                for ($k = 0; $k < 6; ++$k) {
                    $cases[] = $this->cmp($a, $b, $grid[$this->next(count($grid))], $grid[$this->next(count($grid))]);
                }
            }
        }
        // Equal and near-equal products built from factors. Totals reach well above 2^53, where a double cannot tell
        // x*(y*z) from x*(y*z)+x or x*y*z-1 apart, so a native comparison would answer "equal" wrongly.
        for ($i = 0; $i < 2000; ++$i) {
            $x = $this->randomBits(18, 30);
            $y = $this->randomBits(18, 30);
            $z = $this->randomBits(18, 30);
            $yz = self::mul($y, $z, 0);
            $xy = self::mul($x, $y, 0);
            if (self::comp($yz, self::EXACT_MAX) > 0 || self::comp($xy, self::EXACT_MAX) > 0) {
                continue;
            }
            $cases[] = $this->cmp($x, $yz, $xy, $z);
            $cases[] = $this->cmp($x, self::add($yz, '1', 0), $xy, $z);
            $cases[] = $this->cmp($x, $yz, $xy, self::sub($z, '1', 0));
            $cases[] = $this->cmp(self::add($x, '1', 0), $yz, $xy, self::add($z, '1', 0));
        }
        // Products above 2^53 that differ by less than a double can resolve: a*b against (a+1)*floor(a*b/(a+1)),
        // which differ by at most a, and against the same with the second factor one larger.
        for ($i = 0; $i < 400; ++$i) {
            $a = (string) (2 + $this->next(6));
            $b = $this->randomBits(50, 53);
            $c = self::add($a, '1', 0);
            $d = self::div(self::mul($a, $b, 0), $c, 0);
            $cases[] = $this->cmp($a, $b, $c, $d);
            $cases[] = $this->cmp($a, $b, $c, self::add($d, '1', 0));
        }
        for ($i = 0; $i < 3000; ++$i) {
            $cases[] = $this->cmp($this->randomBits(0, 53), $this->randomBits(0, 53), $this->randomBits(0, 53), $this->randomBits(0, 53));
        }

        return $cases;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    private function cmp(string $a, string $b, string $c, string $d): array
    {
        $order = self::comp(self::mul($a, $b, 0), self::mul($c, $d, 0));

        return [$a, $b, $c, $d, $order < 0 ? 'lt' : ($order > 0 ? 'gt' : 'eq')];
    }

    /**
     * Limb operations on numbers beyond the exact range (up to about 160 bits), decimal in and out.
     *
     * @return array<string, list<list<string>>>
     */
    private function bigCases(): array
    {
        $mul = [];
        $add = [];
        $sub = [];
        $divmod = [];
        for ($i = 0; $i < 400; ++$i) {
            $a = $this->randomDecimal(1, 48);
            $b = $this->randomDecimal(1, 48);
            $mul[] = [$a, $b, self::mul($a, $b, 0)];
            $add[] = [$a, $b, self::add($a, $b, 0)];
            [$hi, $lo] = self::comp($a, $b) >= 0 ? [$a, $b] : [$b, $a];
            $sub[] = [$hi, $lo, self::sub($hi, $lo, 0)];
            $n = $this->randomDecimal(1, 48);
            $d = $this->randomDecimal(1, 34);
            $divmod[] = [$n, $d, self::div($n, $d, 0), self::mod($n, $d, 0)];
        }
        $mul[] = ['0', '123456789', '0'];
        $add[] = ['0', '0', '0'];
        $sub[] = ['123456789', '123456789', '0'];
        $divmod[] = ['0', '7', '0', '0'];
        $divmod[] = ['6', '7', '0', '6'];
        $divmod[] = ['7', '7', '1', '0'];

        return ['mul' => $mul, 'add' => $add, 'sub' => $sub, 'divmod' => $divmod];
    }

    /**
     * mulSubCeilDivCapped cases: a, b, subtrahend, divisor, cap, then quotient and capped ("0" or "1"); the quotient is
     * min(ceil(max(a*b - subtrahend, 0) / divisor), cap).
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}>
     */
    private function mscCases(): array
    {
        $max = self::EXACT_MAX;
        $grid = $this->grid();
        $cases = [];
        foreach ($grid as $a) {
            foreach ($grid as $b) {
                foreach (['0', '1', $max] as $c) {
                    foreach (['1', '3', '1000000', '4503599627370496', $max] as $d) {
                        foreach ([$max, '12345'] as $cap) {
                            $cases[] = $this->msc($a, $b, $c, $d, $cap);
                        }
                    }
                }
            }
        }
        // The subtrahend equals, is one below and one above the product; exact multiples of the divisor.
        for ($i = 0; $i < 600; ++$i) {
            $a = $this->randomBits(1, 26);
            $b = $this->randomBits(1, 26);
            $p = self::mul($a, $b, 0);
            $d = $this->randomBits(1, 40);
            foreach ([$p, self::add($p, '1', 0), self::comp($p, '0') > 0 ? self::sub($p, '1', 0) : '0'] as $c) {
                $cases[] = $this->msc($a, $b, $c, $d, $max);
            }
            $multiple = self::mul($d, (string) (1 + $this->next(1000)), 0);
            if (self::comp($multiple, $max) <= 0) {
                $cases[] = $this->msc($multiple, '1', '0', $d, $max);
                $cases[] = $this->msc(self::add($multiple, '1', 0), '1', '0', $d, $max);
            }
        }
        for ($i = 0; $i < 4000; ++$i) {
            $d = $this->randomBits(1, 53);
            $cases[] = $this->msc($this->randomBits(0, 53), $this->randomBits(0, 53), $this->randomBits(0, 53), $d === '0' ? '1' : $d, $i % 5 === 0 ? $max : $this->randomBits(1, 53));
        }

        return $cases;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}
     */
    private function msc(string $a, string $b, string $c, string $d, string $cap): array
    {
        $product = self::mul($a, $b, 0);
        $numerator = self::comp($product, $c) > 0 ? self::sub($product, $c, 0) : '0';
        $quotient = self::div($numerator, $d, 0);
        if (self::comp(self::mod($numerator, $d, 0), '0') > 0) {
            $quotient = self::add($quotient, '1', 0);
        }
        if (self::comp($quotient, $cap) >= 0) {
            return [$a, $b, $c, $d, $cap, $cap, '1'];
        }

        return [$a, $b, $c, $d, $cap, $quotient, '0'];
    }

    /**
     * compareWeightedSum cases: a, b, c, d, e, f, then "lt", "eq" or "gt" for a*b + c*d versus e*f.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}>
     */
    private function cwsCases(): array
    {
        $grid = $this->grid();
        $cases = [];
        foreach ($grid as $a) {
            foreach ($grid as $b) {
                for ($k = 0; $k < 4; ++$k) {
                    $cases[] = $this->cws($a, $b, $grid[$this->next(count($grid))], $grid[$this->next(count($grid))], $grid[$this->next(count($grid))], $grid[$this->next(count($grid))]);
                }
            }
        }
        // Equal and neighbouring sums: e*f is chosen first, then a*b + 1*d is built to equal it exactly and moved by one.
        // Totals reach above 2^53, where a double cannot tell a sum from its neighbour.
        for ($i = 0; $i < 2500; ++$i) {
            $e = $this->randomBits(18, 30);
            $f = $this->randomBits(18, 30);
            $a = $this->randomBits(10, 30);
            $b = $this->randomBits(10, 30);
            $total = self::mul($e, $f, 0);
            $first = self::mul($a, $b, 0);
            if (self::comp($total, $first) < 0 || self::comp(self::sub($total, $first, 0), self::EXACT_MAX) > 0) {
                continue;
            }
            $d = self::sub($total, $first, 0);
            $cases[] = $this->cws($a, $b, '1', $d, $e, $f);
            $cases[] = $this->cws($a, $b, '1', self::add($d, '1', 0), $e, $f);
            if (self::comp($d, '0') > 0) {
                $cases[] = $this->cws($a, $b, '1', self::sub($d, '1', 0), $e, $f);
            }
        }
        for ($i = 0; $i < 3000; ++$i) {
            $cases[] = $this->cws($this->randomBits(0, 53), $this->randomBits(0, 53), $this->randomBits(0, 53), $this->randomBits(0, 53), $this->randomBits(0, 53), $this->randomBits(0, 53));
        }

        return $cases;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}
     */
    private function cws(string $a, string $b, string $c, string $d, string $e, string $f): array
    {
        $order = self::comp(self::add(self::mul($a, $b, 0), self::mul($c, $d, 0), 0), self::mul($e, $f, 0));

        return [$a, $b, $c, $d, $e, $f, $order < 0 ? 'lt' : ($order > 0 ? 'gt' : 'eq')];
    }

    /** @return numeric-string */
    private static function numeric(string $value): string
    {
        if (!is_numeric($value) || preg_match('~^[0-9]+$~', $value) !== 1) {
            throw new \InvalidArgumentException('Not a non-negative decimal integer: ' . $value);
        }

        return $value;
    }

    /** Decimal oracle wrappers: bcmath on validated decimal strings (the scale argument is kept for call-site clarity). */
    public static function add(string $a, string $b, int $scale = 0): string
    {
        return bcadd(self::numeric($a), self::numeric($b), $scale);
    }

    public static function sub(string $a, string $b, int $scale = 0): string
    {
        return bcsub(self::numeric($a), self::numeric($b), $scale);
    }

    public static function mul(string $a, string $b, int $scale = 0): string
    {
        return bcmul(self::numeric($a), self::numeric($b), $scale);
    }

    public static function div(string $a, string $b, int $scale = 0): string
    {
        return bcdiv(self::numeric($a), self::numeric($b), $scale);
    }

    public static function mod(string $a, string $b, int $scale = 0): string
    {
        return bcmod(self::numeric($a), self::numeric($b), $scale);
    }

    public static function comp(string $a, string $b): int
    {
        return bccomp(self::numeric($a), self::numeric($b));
    }

    /** Next pseudo-random value in [0, 2^31). */
    private function nextRaw(): int
    {
        $this->state = ($this->state * 1103515245 + 12345) & 0x7FFFFFFF;

        return $this->state >> 4;
    }

    private function next(int $bound): int
    {
        return $this->nextRaw() % $bound;
    }

    /** A decimal string of a random bit length in [minBits, maxBits] (at most 53 bits); 0 is possible when minBits is 0. */
    private function randomBits(int $minBits, int $maxBits): string
    {
        $bits = $minBits + $this->next($maxBits - $minBits + 1);
        if ($bits === 0) {
            return '0';
        }
        $value = 1;
        for ($i = 1; $i < $bits; ++$i) {
            $value = ($value << 1) | ($this->nextRaw() & 1);
        }

        return (string) $value;
    }

    /** A decimal string of between minDigits and maxDigits digits without a leading zero. */
    private function randomDecimal(int $minDigits, int $maxDigits): string
    {
        $digits = $minDigits + $this->next($maxDigits - $minDigits + 1);
        $text = (string) (1 + $this->next(9));
        for ($i = 1; $i < $digits; ++$i) {
            $text .= (string) $this->next(10);
        }

        return $text;
    }
}
