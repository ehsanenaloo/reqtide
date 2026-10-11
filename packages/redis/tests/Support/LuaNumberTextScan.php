<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Support;

/**
 * Static scan of shipped Lua for number-to-text and text-to-number conversions.
 *
 * Redis Lua converts numbers to text with a 14-digit format ("9.007199254741e+15"), so any implicit or explicit
 * conversion near the exact range corrupts a value silently. The rules, applied to code with comments and string
 * literals removed:
 *
 *  - `tostring`, `concat` (table.concat converts numbers too) and the `..` operator are banned everywhere, except between `-- BEGIN join_text` and `-- END join_text`, the one audited string join, which accepts only values of type string;
 *  - `format` (string.format, `:format`) is banned except between `-- BEGIN format_int` and `-- END format_int`;
 *  - `tonumber` is banned except between `-- BEGIN parse_int` and `-- END parse_int`.
 *
 * Messages are string literals passed whole; building one with `..` is a violation by design.
 */
final class LuaNumberTextScan
{
    /**
     * @return list<array{line: int, rule: string}>
     */
    public static function scan(string $source): array
    {
        $violations = [];
        $region = null;
        $lineNumber = 0;

        foreach (explode("\n", self::stripped($source)) as $code) {
            ++$lineNumber;
            if (preg_match('~^\s*--\s*BEGIN\s+(format_int|parse_int|join_text)\b~', $code, $m) === 1) {
                $region = $m[1];
                continue;
            }
            if (preg_match('~^\s*--\s*END\s+(format_int|parse_int|join_text)\b~', $code) === 1) {
                $region = null;
                continue;
            }
            if (preg_match('~\btostring\b~', $code) === 1) {
                $violations[] = ['line' => $lineNumber, 'rule' => 'tostring'];
            }
            if ($region !== 'join_text' && preg_match('~\bconcat\b~', $code) === 1) {
                $violations[] = ['line' => $lineNumber, 'rule' => 'concat'];
            }
            if ($region !== 'join_text' && str_contains($code, '..')) {
                $violations[] = ['line' => $lineNumber, 'rule' => 'concatenation operator'];
            }
            if ($region !== 'format_int' && preg_match('~\bformat\b~', $code) === 1) {
                $violations[] = ['line' => $lineNumber, 'rule' => 'format outside format_int'];
            }
            if ($region !== 'parse_int' && preg_match('~\btonumber\b~', $code) === 1) {
                $violations[] = ['line' => $lineNumber, 'rule' => 'tonumber outside parse_int'];
            }
        }

        return $violations;
    }

    /**
     * The source with comments and string literals blanked, newlines kept so line numbers stay valid. The two region
     * markers are comments, so they are re-emitted as plain lines before the comment is removed.
     */
    private static function stripped(string $source): string
    {
        $out = '';
        $length = strlen($source);
        $i = 0;
        while ($i < $length) {
            $char = $source[$i];
            $next = $source[$i + 1] ?? '';

            if ($char === '-' && $next === '-') {
                if (preg_match('~\G--\[(=*)\[~', $source, $m, 0, $i) === 1) {
                    $close = ']' . $m[1] . ']';
                    $end = strpos($source, $close, $i);
                    $end = $end === false ? $length : $end + strlen($close);
                    $out .= str_repeat("\n", substr_count($source, "\n", $i, $end - $i));
                    $i = $end;
                    continue;
                }
                $end = strpos($source, "\n", $i);
                $end = $end === false ? $length : $end;
                $comment = substr($source, $i, $end - $i);
                if (preg_match('~^--\s*(BEGIN|END)\s+(format_int|parse_int|join_text)\b~', $comment, $marker) === 1) {
                    $out .= '-- ' . $marker[1] . ' ' . $marker[2];
                }
                $i = $end;
                continue;
            }
            if ($char === '[' && preg_match('~\G\[(=*)\[~', $source, $m, 0, $i) === 1) {
                $close = ']' . $m[1] . ']';
                $end = strpos($source, $close, $i);
                $end = $end === false ? $length : $end + strlen($close);
                $out .= str_repeat("\n", substr_count($source, "\n", $i, $end - $i));
                $i = $end;
                continue;
            }
            if ($char === "'" || $char === '"') {
                $j = $i + 1;
                while ($j < $length && $source[$j] !== $char && $source[$j] !== "\n") {
                    $j += $source[$j] === '\\' ? 2 : 1;
                }
                $out .= $char . $char;
                $i = $j + 1;
                continue;
            }
            $out .= $char;
            ++$i;
        }

        return $out;
    }
}
