<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Error;

use Enaxon\Reqtide\Error\Problem;
use Enaxon\Reqtide\Error\ProblemCause;
use Enaxon\Reqtide\Error\ReasonCode;
use Enaxon\Reqtide\Error\Stage;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Exception\StateUnavailableException;
use Enaxon\Reqtide\Tests\Support\Fixtures;
use Enaxon\Reqtide\Value\AttemptId;
use Enaxon\Reqtide\Value\OperationId;
use Enaxon\Reqtide\Value\ProfileVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The Problem value: golden JSON, redaction by construction, bounded and cycle-safe cause chains. */
final class ProblemTest extends TestCase
{
    private const CANARY = 'CANARY-7f3a https://user:pass@internal.example.com/v1/pay?token=SECRET-TOKEN Bearer abc.def.ghi';

    public function testGoldenJsonForAnAdmissionRefusalWithContext(): void
    {
        $problem = new Problem(ReasonCode::CircuitOpen, null, new OperationId('op-1'), new ProfileVersion('v1'));

        self::assertSame(
            '{"type":"tag:enaloo.com,2026:reqtide:problem:circuit_open","title":"Circuit is open","category":"admission","code":"circuit_open",'
            . '"stage":"admission","severity":"info","retryability":"after_wait",'
            . '"detail":"Circuit is open. Category: admission; retryability: after_wait; stage: admission.",'
            . '"operationId":"op-1","profileVersion":"v1","attemptId":null,"correlationId":"op-1","causes":[]}',
            json_encode($problem, JSON_THROW_ON_ERROR),
        );
    }

    public function testGoldenJsonForAnIndeterminateInfrastructureFailureWithCausesAndASuppliedCorrelation(): void
    {
        $problem = new Problem(
            ReasonCode::StateIndeterminate,
            null,
            new OperationId('op-1'),
            new ProfileVersion('v1'),
            AttemptId::fromLowerHex(Fixtures::hex(2)),
            'corr-9',
            [new ProblemCause('RuntimeException'), new ProblemCause('StateUnavailableException', ReasonCode::StateUnavailable)],
        );

        self::assertSame(
            '{"type":"tag:enaloo.com,2026:reqtide:problem:indeterminate","title":"State backend outcome indeterminate","category":"infrastructure",'
            . '"code":"indeterminate","stage":null,"severity":"error","retryability":"unknown",'
            . '"detail":"State backend outcome indeterminate. Category: infrastructure; retryability: unknown.",'
            . '"operationId":"op-1","profileVersion":"v1","attemptId":"00000000000000000000000000000002","correlationId":"corr-9",'
            . '"causes":[{"class":"RuntimeException","code":null},{"class":"StateUnavailableException","code":"unavailable"}]}',
            json_encode($problem, JSON_THROW_ON_ERROR),
        );
    }

    public function testGoldenJsonForAConfigurationDefectWithoutContext(): void
    {
        self::assertSame(
            '{"type":"tag:enaloo.com,2026:reqtide:problem:invalid_configuration","title":"Invalid configuration","category":"configuration",'
            . '"code":"invalid_configuration","stage":null,"severity":"error","retryability":"never",'
            . '"detail":"Invalid configuration. Category: configuration; retryability: never.",'
            . '"operationId":null,"profileVersion":null,"attemptId":null,"correlationId":null,"causes":[]}',
            json_encode(new Problem(ReasonCode::InvalidConfiguration), JSON_THROW_ON_ERROR),
        );
    }

    public function testGoldenJsonForARetryStopAtAnExplicitStage(): void
    {
        self::assertSame(
            '{"type":"tag:enaloo.com,2026:reqtide:problem:replay_contract_not_valid","title":"Replay contract is not valid","category":"retry",'
            . '"code":"replay_contract_not_valid","stage":"preflight","severity":"warning","retryability":"never",'
            . '"detail":"Replay contract is not valid. Category: retry; retryability: never; stage: preflight.",'
            . '"operationId":null,"profileVersion":null,"attemptId":null,"correlationId":null,"causes":[]}',
            json_encode(new Problem(ReasonCode::ReplayContractNotValid, Stage::Preflight), JSON_THROW_ON_ERROR),
        );
    }

    public function testMemberOrderIsStable(): void
    {
        self::assertSame(
            ['type', 'title', 'category', 'code', 'stage', 'severity', 'retryability', 'detail', 'operationId', 'profileVersion', 'attemptId', 'correlationId', 'causes'],
            array_keys((new Problem(ReasonCode::CooldownActive))->toArray()),
        );
    }

    /** @return iterable<string, array{ReasonCode}> */
    public static function everyCode(): iterable
    {
        foreach (ReasonCode::cases() as $case) {
            yield $case->value => [$case];
        }
    }

    #[DataProvider('everyCode')]
    public function testTypeIsAStableUrnAndDetailIsBoundedSafeAscii(ReasonCode $code): void
    {
        $problem = new Problem($code);

        self::assertSame('tag:enaloo.com,2026:reqtide:problem:' . $code->value, $problem->type());
        self::assertStringNotContainsString('://', $problem->type(), 'a tag URI, not a fake URL');
        self::assertLessThanOrEqual(200, strlen($problem->detail()));
        self::assertMatchesRegularExpression('/\A[\x20-\x7e]+\z/', $problem->detail());
        self::assertSame($code->title(), $problem->title());
        self::assertSame($code->category(), $problem->category());
        self::assertSame($code->severity(), $problem->severity());
        self::assertSame($code->retryability(), $problem->retryability());
        self::assertSame($problem->toArray(), $problem->jsonSerialize());
    }

    public function testDetailIsBuiltFromCodeMetadataOnlyNeverFromThrowables(): void
    {
        $failure = new StateUnavailableException(self::CANARY, 0, new \RuntimeException(self::CANARY));
        $json = json_encode($failure->problem(), JSON_THROW_ON_ERROR);

        foreach (['CANARY', 'SECRET', 'internal.example.com', 'Bearer', 'pass@', 'token='] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        self::assertStringContainsString('State backend unavailable', $json);
    }

    public function testNoMemberCanCarryFreeText(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new Problem(ReasonCode::CircuitOpen, null, null, null, null, self::CANARY);
    }

    public function testACorrelationIdIsBoundedAndCharsetRestricted(): void
    {
        foreach (['', str_repeat('a', 65), 'has space', 'a/b', 'a?b=c'] as $bad) {
            $rejected = false;
            try {
                new Problem(ReasonCode::CircuitOpen, null, null, null, null, $bad);
            } catch (InvalidConfigurationException) {
                $rejected = true;
            }
            self::assertTrue($rejected, 'Expected rejection of ' . $bad);
        }
        self::assertSame('A.b_c-9', (new Problem(ReasonCode::CircuitOpen, null, null, null, null, 'A.b_c-9'))->correlationId);
    }

    public function testTheCorrelationIdIsDerivedFromTheOperationIdUnlessSupplied(): void
    {
        $operation = new OperationId(str_repeat('o', 128));

        $derived = new Problem(ReasonCode::CircuitOpen, null, $operation, new ProfileVersion('v1'));
        $supplied = new Problem(ReasonCode::CircuitOpen, null, $operation, new ProfileVersion('v1'), null, 'caller-1');

        self::assertSame(str_repeat('o', 128), $derived->correlationId);
        self::assertSame('caller-1', $supplied->correlationId);
        self::assertNull((new Problem(ReasonCode::CircuitOpen))->correlationId);
    }

    public function testWithContextReturnsANewProblemAndKeepsTheOriginalUntouched(): void
    {
        $base = new Problem(ReasonCode::RateExhausted, null, null, null, null, null, [new ProblemCause('RuntimeException')]);

        $with = $base->withContext(new OperationId('op-7'), new ProfileVersion('v2'), AttemptId::fromLowerHex(Fixtures::hex(3)));

        self::assertNull($base->operationId);
        self::assertSame('op-7', $with->operationId?->value);
        self::assertSame('op-7', $with->correlationId);
        self::assertSame($base->causes, $with->causes);
        self::assertSame(ReasonCode::RateExhausted, $with->code);
    }

    public function testACauseSummaryCarriesOnlyAShortClassNameAndACode(): void
    {
        $cause = ProblemCause::of(new StateUnavailableException(self::CANARY));
        $foreign = ProblemCause::of(new \DomainException(self::CANARY));

        self::assertSame(['class' => 'StateUnavailableException', 'code' => 'unavailable'], $cause->toArray());
        self::assertSame(['class' => 'DomainException', 'code' => null], $foreign->toArray());
        $this->expectException(InvalidConfigurationException::class);
        new ProblemCause('Vendor\\Secret\\Gateway');
    }

    public function testAnAnonymousThrowableNeverLeaksItsFilePath(): void
    {
        $anonymous = new class (self::CANARY) extends \RuntimeException {};
        $bare = new class extends \Exception {};

        self::assertSame('RuntimeException', ProblemCause::of($anonymous)->class);
        self::assertSame('Exception', ProblemCause::of($bare)->class);
        self::assertStringNotContainsString('.php', json_encode([ProblemCause::of($anonymous), ProblemCause::of($bare)], JSON_THROW_ON_ERROR));
    }

    public function testChainsAreBoundedAtEightCauses(): void
    {
        $chain = new \RuntimeException('leaf');
        for ($i = 0; $i < 11; ++$i) {
            $chain = new \LogicException(self::CANARY . $i, 0, $chain);
        }

        $causes = ProblemCause::chainFrom($chain);

        self::assertCount(ProblemCause::MAX_DEPTH, $causes);
        self::assertSame(8, ProblemCause::MAX_DEPTH);
        self::assertSame(ProblemCause::MAX_DEPTH, count((new StateUnavailableException('x', 0, $chain))->problem()->causes));
        self::assertSame([], ProblemCause::chainFrom(null));
    }

    public function testACyclicPreviousChainTerminates(): void
    {
        $a = new \RuntimeException('a');
        $b = new \LogicException('b', 0, $a);
        (new \ReflectionProperty(\Exception::class, 'previous'))->setValue($a, $b);

        $causes = ProblemCause::chainFrom($b);

        self::assertSame(['LogicException', 'RuntimeException'], array_map(static fn(ProblemCause $c): string => $c->class, $causes));
        self::assertCount(2, (new StateUnavailableException('x', 0, $a))->problem()->causes);
    }

    public function testVeryLongMessagesProduceBoundedOutput(): void
    {
        $failure = new StateUnavailableException(str_repeat(self::CANARY, 5000), 0, new \RuntimeException(str_repeat('x', 1_000_000)));

        self::assertLessThan(1500, strlen(json_encode($failure->problem(), JSON_THROW_ON_ERROR)));
    }

    public function testTooManyOrMalformedCausesAreRejected(): void
    {
        $nine = array_fill(0, 9, new ProblemCause('RuntimeException'));
        $rejected = false;
        try {
            new Problem(ReasonCode::CircuitOpen, null, null, null, null, null, $nine);
        } catch (InvalidConfigurationException) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'nine causes exceed the bound');
        $this->expectException(InvalidConfigurationException::class);
        new Problem(ReasonCode::CircuitOpen, null, null, null, null, null, ['not a cause']); // @phpstan-ignore argument.type
    }
}
