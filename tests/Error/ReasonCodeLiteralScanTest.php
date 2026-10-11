<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Error;

use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Error\Stage;
use PHPUnit\Framework\TestCase;

/**
 * No production file outside Error/ may write a registry value as a string literal: codes come from the enum,
 * so the registry stays the single source of truth.
 */
final class ReasonCodeLiteralScanTest extends TestCase
{
    /**
     * Structural exceptions for reason codes, as `relative path => [literal, ...]`. A literal listed here is
     * not a reason code at that site.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_REASON_LITERALS = [];

    /**
     * Component labels (`policyKind`, `backendKind`, definition keys) that happen to equal a stage value.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_STAGE_LITERALS = [
        'Pipeline/Internal/OperationRun.php' => ['retry', 'retry_budget'],
        'Pipeline/CompiledPipeline.php' => ['retry_budget'],
    ];

    private static function root(): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', dirname(__DIR__, 2)) . '/src';
    }

    /** @return list<string> absolute paths of Core production files outside Error/ (src/Testing is test tooling and was never part of this scan; it builds Diagnostic values from plain strings) */
    private static function productionFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root(), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
            if (!str_contains($path, '/src/Error/') && !str_contains($path, '/src/Testing/')) {
                $files[] = $path;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * String literals of a file; enum `case X = '...'` declarations (definitions, not uses) are skipped.
     *
     * @return list<string>
     */
    private static function literals(string $path): array
    {
        $source = file_get_contents($path);
        self::assertIsString($source);
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn(mixed $t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $found = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $declaration = isset($tokens[$i - 3]) && is_array($tokens[$i - 3]) && $tokens[$i - 3][0] === T_CASE && $tokens[$i - 1] === '=';
            if (!$declaration) {
                $found[] = substr($token[1], 1, -1);
            }
        }

        return $found;
    }

    private static function relative(string $path): string
    {
        return substr($path, strlen(self::root()) + 1);
    }

    /**
     * @param list<string> $values
     * @param array<string, list<string>> $allowed
     * @return list<string>
     */
    private static function offences(array $values, array $allowed): array
    {
        $offences = [];
        foreach (self::productionFiles() as $path) {
            $relative = self::relative($path);
            foreach (self::literals($path) as $literal) {
                if (in_array($literal, $values, true) && !in_array($literal, $allowed[$relative] ?? [], true)) {
                    $offences[] = $relative . ': ' . $literal;
                }
            }
        }

        return $offences;
    }

    public function testNoProductionFileWritesARegistryValueAsALiteral(): void
    {
        $values = array_map(static fn(ReasonCode $c): string => $c->value, ReasonCode::cases());

        self::assertSame([], self::offences($values, self::ALLOWED_REASON_LITERALS), 'use ReasonCode::<Case>->value instead of a literal');
    }

    public function testNoProductionFileWritesAStageValueAsALiteral(): void
    {
        $values = array_map(static fn(Stage $s): string => $s->value, Stage::cases());

        self::assertSame([], self::offences($values, self::ALLOWED_STAGE_LITERALS), 'use Stage::<Case> instead of a literal');
    }

    public function testTheScannerSeesProductionLiteralsAndEnumDeclarations(): void
    {
        $all = [];
        foreach (self::productionFiles() as $path) {
            array_push($all, ...self::literals($path));
        }

        self::assertContains('rate_limit', $all, 'component labels are literals the scanner sees');
        self::assertNotContains('fallback_failed', $all, 'enum case declarations are definitions, not uses');
        self::assertGreaterThan(10, count($all));
    }

    public function testWireValuesStayIdenticalToTheirHistoricalLiterals(): void
    {
        self::assertSame('circuit_open', ReasonCode::CircuitOpen->value);
        self::assertSame('rate.exhausted', ReasonCode::RateExhausted->value);
        self::assertSame('circuit.threshold_exceeded', ReasonCode::CircuitThresholdExceeded->value);
        self::assertSame('unavailable', ReasonCode::StateUnavailable->value);
        self::assertSame('attempts_exhausted', ReasonCode::AttemptsExhausted->value);
    }
}
