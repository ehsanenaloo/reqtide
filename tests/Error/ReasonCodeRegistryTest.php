<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Error;

use Enaxon\Reqtide\Error\Category;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Error\Retryability;
use Enaxon\Reqtide\Error\Severity;
use Enaxon\Reqtide\Error\Stage;
use Enaxon\Reqtide\Internal\Codes;
use PHPUnit\Framework\TestCase;

/** Registry completeness and the append-only policy of the reason-code enum. */
final class ReasonCodeRegistryTest extends TestCase
{
    /**
     * Every wire value published so far. Removing or renaming one fails here (append-only policy); a new case
     * must be appended to this list in the same change.
     */
    private const PUBLISHED = [
        'circuit_open', 'circuit_probe_capacity_exhausted', 'concurrency_capacity_exhausted', 'cooldown_active',
        'cooldown_exceeds_deadline', 'rate.accepted', 'rate.cost_exceeds_capacity', 'rate.exhausted',
        'rate.wait_unrepresentable', 'admission_rejected', 'admission_deferred', 'admission_impossible',
        'circuit.threshold_exceeded', 'circuit.probe_failed', 'circuit.probes_succeeded', 'circuit.open_elapsed',
        'retry_budget_exhausted', 'retry_budget_commit_refused', 'attempts_exhausted', 'outcome_not_retryable',
        'retry_delay_exceeds_deadline', 'attempt_allowance_unavailable', 'replay_contract_not_valid',
        'replay_horizon_inadequate', 'replay_horizon_saturated', 'retry_deadline_reached', 'retry_cancelled',
        'retry_after_exceeds_maximum_delay', 'retry_after_exceeds_deadline', 'delay_failed', 'operation_cancelled',
        'deadline_exceeded', 'unavailable', 'corrupt', 'indeterminate', 'capacity_exceeded', 'clock_failure',
        'unexpected', 'runtime_adapter_failure', 'rate_consume_indeterminate', 'commit_not_confirmed',
        'clock_unavailable', 'retry_clock_unavailable', 'fallback_clock_unavailable', 'timing_unavailable',
        'fallback_timing_unavailable', 'elapsed_unavailable', 'cancellation_sample_failed', 'random_out_of_range',
        'concurrency_release_failed', 'concurrency_release_stale', 'circuit_abandon_failed', 'circuit_abandon_stale',
        'circuit_record_failed', 'circuit_record_stale', 'retry_reservation_release_failed', 'cooldown_extend_failed', 'cooldown_extension_capped',
        'settlement_accounting', 'completion_sink_failed', 'event_sink_failed', 'classifier_failed',
        'late_signal_extraction_failed', 'fallback_failed', 'discard_failed', 'preparation_failed',
        'lifecycle_failure', 'unexpected_failure', 'invalid_configuration', 'capability_mismatch',
        'value_accepted', 'value_unacceptable', 'exception_retryable', 'exception_failure', 'exception_ignored',
    ];

    public function testEveryCaseHasCompleteMetadata(): void
    {
        foreach (ReasonCode::cases() as $case) {
            self::assertInstanceOf(Category::class, $case->category(), $case->value);
            self::assertInstanceOf(Severity::class, $case->severity(), $case->value);
            self::assertInstanceOf(Retryability::class, $case->retryability(), $case->value);
            if ($case->stage() !== null) {
                self::assertNotNull(Stage::tryFrom($case->stage()->value), $case->value);
            }
            self::assertNotSame('', $case->title(), $case->value);
            self::assertSame($case->title(), $case->title(), 'the title is constant per code');
            self::assertLessThanOrEqual(120, strlen($case->title()), $case->value);
            self::assertMatchesRegularExpression('/\A[\x20-\x7e]+\z/', $case->title(), $case->value);
        }
    }

    public function testValuesAreUniqueBoundedAndAcceptedByTheDiagnosticContract(): void
    {
        $values = array_map(static fn(ReasonCode $c): string => $c->value, ReasonCode::cases());

        self::assertSame($values, array_values(array_unique($values)), 'values are globally unique');
        foreach ($values as $value) {
            self::assertSame($value, Codes::code($value, 'reason'));
        }
    }

    public function testTitlesAreUniquePerCode(): void
    {
        $titles = array_map(static fn(ReasonCode $c): string => $c->title(), ReasonCode::cases());

        self::assertSame($titles, array_values(array_unique($titles)), 'two codes never share a title');
    }

    public function testTheRegistryIsAppendOnly(): void
    {
        $current = array_map(static fn(ReasonCode $c): string => $c->value, ReasonCode::cases());

        self::assertSame([], array_values(array_diff(self::PUBLISHED, $current)), 'a published value was removed or renamed');
        self::assertSame([], array_values(array_diff($current, self::PUBLISHED)), 'a new case must be appended to PUBLISHED in the same change');
    }

    public function testMetadataInvariants(): void
    {
        foreach (ReasonCode::cases() as $case) {
            if ($case->category() === Category::Configuration) {
                self::assertSame(Retryability::Never, $case->retryability(), $case->value . ': configuration defects are never retryable');
            }
            if ($case->retryability() === Retryability::Unknown) {
                self::assertSame(Category::Infrastructure, $case->category(), $case->value . ': uncertainty originates in infrastructure');
            }
            if ($case->retryability() === Retryability::AfterWait) {
                self::assertContains($case->category(), [Category::Admission, Category::Retry], $case->value);
            }
            if ($case->category() === Category::Admission && $case !== ReasonCode::RateAccepted) {
                self::assertSame(Severity::Info, $case->severity(), $case->value . ': admission refusals are expected control flow');
            }
            if ($case->category() === Category::Accounting) {
                self::assertSame(Retryability::Never, $case->retryability(), $case->value);
            }
        }
    }

    public function testEveryCategoryAndStageIsUsed(): void
    {
        $categories = array_map(static fn(ReasonCode $c): Category => $c->category(), ReasonCode::cases());
        foreach (Category::cases() as $category) {
            self::assertContains($category, $categories, $category->value);
        }
        $stages = array_map(static fn(ReasonCode $c): ?Stage => $c->stage(), ReasonCode::cases());
        foreach (Stage::cases() as $stage) {
            if ($stage === Stage::Barrier) {
                continue; // reserved: the barrier reports through the generic lifecycle codes
            }
            self::assertContains($stage, $stages, $stage->value);
        }
    }

    public function testSeverityMapsToPlainPsr3LevelStrings(): void
    {
        self::assertSame(
            ['debug', 'info', 'notice', 'warning', 'error', 'critical'],
            array_map(static fn(Severity $s): string => $s->psr3Level(), Severity::cases()),
        );
        self::assertTrue(Severity::Error->isAtLeast(Severity::Warning));
        self::assertFalse(Severity::Info->isAtLeast(Severity::Notice));
        self::assertTrue(Severity::Critical->isAtLeast(Severity::Critical));
        self::assertFalse(interface_exists('Psr\Log\LoggerInterface', false), 'Core imports no PSR package');
    }

    public function testTheClassDocumentsTheAppendOnlyPolicy(): void
    {
        $doc = (new \ReflectionClass(ReasonCode::class))->getDocComment();

        self::assertIsString($doc);
        self::assertStringContainsString('Append-only policy', $doc);
        self::assertStringContainsString('never changes meaning', $doc);
    }

    public function testForeignCodesAreNotRegistryMembers(): void
    {
        self::assertNull(ReasonCode::tryFrom('http_503'));
        self::assertSame(ReasonCode::CircuitOpen, ReasonCode::tryFrom('circuit_open'));
    }
}
