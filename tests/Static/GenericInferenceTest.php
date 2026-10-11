<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Static;

use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Execution\ExecutionContext;
use Enaxon\Reqtide\Execution\OperationMetadata;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Fallback\FallbackOperation;
use Enaxon\Reqtide\Pipeline\CompiledPipeline;
use Enaxon\Reqtide\Time\Deadline;
use Enaxon\Reqtide\Time\NeverCancelled;
use Enaxon\Reqtide\Value\Duration;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\PartitionIdentity;

use function PHPStan\Testing\assertType;

use PHPUnit\Framework\TestCase;

/**
 * Static-analysis contract of the generic result type.
 *
 * PHPUnit cannot observe a type, so the assertions live in {@see inferenceFixture()} below. That function is never
 * called: `composer stan` (PHPStan level max, part of `composer check`) analyses it, and every
 * `assertType()` that disagrees with the inferred type is an analysis error that fails the build. The lines
 * marked with an argument.type ignore assert the opposite direction: PHPStan reports an unmatched ignore if
 * the mismatch they exercise stops being an error, so a type mismatch between callable and fallback stays
 * caught.
 *
 * What is guaranteed: `execute()` infers its return type from the callable alone when the context has no
 * fallback (the context is `ExecutionContext<never>`), and requires the fallback's type to equal the callable's
 * type when there is one.
 */
final class GenericInferenceTest extends TestCase
{
    public function testTheStaticFixtureIsPartOfTheAnalysedSourceAndNeverExecuted(): void
    {
        self::assertTrue(function_exists(__NAMESPACE__ . '\inferenceFixture'));
        $reflection = new \ReflectionFunction(__NAMESPACE__ . '\inferenceFixture');
        self::assertSame(__FILE__, $reflection->getFileName(), 'the fixture lives in this file so composer stan analyses it');
    }

    public function testExecutionContextDocumentsItsNeverDefault(): void
    {
        $doc = (new \ReflectionClass(ExecutionContext::class))->getDocComment();

        self::assertIsString($doc);
        self::assertStringContainsString('@template T = never', $doc);
    }
}

/**
 * @param FallbackOperation<string> $stringFallback
 * @param FallbackOperation<int> $intFallback
 * @param ExecutionContext<string> $stringContext
 * @param ExecutionContext<int> $intContext
 * @param ExecutionContext<mixed> $mixedContext
 */
function inferenceFixture(
    CompiledPipeline $pipeline,
    OperationId $id,
    PartitionIdentity $partition,
    Deadline $deadline,
    FallbackOperation $stringFallback,
    FallbackOperation $intFallback,
    ExecutionContext $stringContext,
    ExecutionContext $intContext,
    ExecutionContext $mixedContext,
): void {
    $text = static fn(AttemptContext $attempt): string => opaqueText();
    $maybeInt = static fn(AttemptContext $attempt): ?int => opaqueNullableInt();
    $metadata = new OperationMetadata(OperationSafety::unsafe(), BoundedMetadata::empty());

    // A context built by the pipeline has no fallback and therefore does not constrain the result.
    $plain = $pipeline->newContext(Duration::seconds(1));
    assertType('Enaxon\Reqtide\Execution\ExecutionContext<never>', $plain);
    assertType('string', $pipeline->execute($text, $plain));
    assertType('int|null', $pipeline->execute($maybeInt, $plain));
    assertType('Enaxon\Reqtide\Completion\ExecutionCompletion<string>', $pipeline->executeDetailed($text, $plain));

    // Constructing the context by hand without a fallback is the same.
    $manual = new ExecutionContext($id, $partition, $deadline, new NeverCancelled(), $metadata, null);
    assertType('Enaxon\Reqtide\Execution\ExecutionContext<never>', $manual);
    assertType('string', $pipeline->execute($text, $manual));

    // With a fallback the callable and the fallback must agree.
    $withFallback = $pipeline->newContext(Duration::seconds(1), fallback: $stringFallback);
    assertType('Enaxon\Reqtide\Execution\ExecutionContext<string>', $withFallback);
    assertType('string', $pipeline->execute($text, $withFallback));
    assertType('string', $pipeline->execute($text, $stringContext));
    $pipeline->execute($maybeInt, $stringContext); // @phpstan-ignore argument.type
    $pipeline->execute($text, $intContext); // @phpstan-ignore argument.type
    assertType('Enaxon\Reqtide\Execution\ExecutionContext<int>', $pipeline->newContext(Duration::seconds(1), fallback: $intFallback));

    // A context explicitly typed ExecutionContext<mixed> keeps working and yields mixed.
    assertType('mixed', $pipeline->execute($text, $mixedContext));

    // The detailed variant and typed operations preserve T in the same way.
    $completion = $pipeline->executeDetailed($maybeInt, $plain);
    assertType('Enaxon\Reqtide\Completion\ExecutionCompletion<int|null>', $completion);
    if ($completion->terminalOutcome !== null && $completion->terminalOutcome->kind === \Enaxon\Reqtide\Outcome\OutcomeKind::Value) {
        assertType('int|null', $completion->terminalOutcome->value());
    }
}

/** Declared return types are opaque to the analyser, so the fixture sees the declared type and not a literal. */
function opaqueText(): string
{
    return 'text';
}

function opaqueNullableInt(): ?int
{
    return random_int(0, 1) === 0 ? null : 1;
}
