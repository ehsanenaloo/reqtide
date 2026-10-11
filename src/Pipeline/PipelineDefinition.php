<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Pipeline;

use Enaxon\Reqtide\Exception\CapabilityMismatchException;
use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Fallback\FallbackScope;
use Enaxon\Reqtide\Internal\Identity\RandomIdentityGenerator;
use Enaxon\Reqtide\Internal\NumericLimits;
use Enaxon\Reqtide\Policy\AttemptOptions;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\CircuitPolicy;
use Enaxon\Reqtide\Policy\ConcurrencyPolicy;
use Enaxon\Reqtide\Policy\CooldownPolicy;
use Enaxon\Reqtide\Policy\RateLimitPolicy;
use Enaxon\Reqtide\Policy\RetryBudgetPolicy;
use Enaxon\Reqtide\Policy\RetryOptions;
use Enaxon\Reqtide\Runtime\AttemptTimeoutCapability;
use Enaxon\Reqtide\Runtime\QueueOwnership;
use Enaxon\Reqtide\Runtime\SchedulingCapability;
use Enaxon\Reqtide\State\StateBackend;
use Enaxon\Reqtide\State\StateScope;
use Enaxon\Reqtide\Value\PartitionIdentity;
use Enaxon\Reqtide\Value\PolicyIdentity;
use Enaxon\Reqtide\Value\PositiveInt;
use Enaxon\Reqtide\Value\ProfileId;
use Enaxon\Reqtide\Value\ProfileVersion;

/**
 * Immutable pipeline description. There is no deadline here: every operation supplies its own through
 * its ExecutionContext. Validation performs no I/O.
 */
final readonly class PipelineDefinition
{
    private const MAX_EXTENSIONS = 32;

    /**
     * Cleanup margin in nanoseconds (1 s). Every configured lease (circuit permitLease, concurrency lease,
     * retry-budget reservationLease) must exceed attempt.maximumDuration + this margin strictly, so a permit
     * cannot expire while its attempt is still inside its own timeout and cleanup still has room to run.
     * A violation is an InvalidConfigurationException when the definition is constructed (before compile and
     * before any I/O).
     */
    private const LEASE_MARGIN_NANOSECONDS = 1_000_000_000;

    /**
     * The partition this definition serves: the one given explicitly (the builder always passes it) or, when
     * omitted, the one every configured policy shares; null only when neither exists.
     */
    public ?PartitionIdentity $partition;

    /**
     * @param list<Strategy> $extensions
     * @param PartitionIdentity|null $partition Optional trailing parameter (additive, 2026-10-09). When given, every
     *        configured policy identity must carry an equal partition. When omitted it is derived from the policies.
     */
    public function __construct(
        public ProfileId $profileId,
        public ProfileVersion $profileVersion,
        public RetryOptions $retry,
        public AttemptOptions $attempt,
        public ?RateLimitPolicy $rateLimit,
        public ?PositiveInt $rateCost,
        public ?CircuitPolicy $circuit,
        public ?ConcurrencyPolicy $concurrency,
        public ?RetryBudgetPolicy $retryBudget,
        public ?CooldownPolicy $cooldown,
        public bool $requireFiniteBackendIo,
        public array $extensions = [],
        public FallbackScope $fallbackScope = FallbackScope::BusinessOutcomesOnly,
        ?PartitionIdentity $partition = null,
    ) {
        if (($rateLimit === null) !== ($rateCost === null)) {
            throw new InvalidConfigurationException('rateCost must be present if and only if rateLimit is present.');
        }
        if ($rateCost !== null && $rateCost->value > NumericLimits::MAX_PORTABLE_TOKENS) {
            throw new InvalidConfigurationException('rateCost must not exceed 9007199254 tokens.');
        }
        if ($retry->maxAttempts->value > 1 && $retryBudget === null) {
            throw new InvalidConfigurationException('maxAttempts greater than one requires a retry budget policy.');
        }
        if (!array_is_list($extensions) || count($extensions) > self::MAX_EXTENSIONS) {
            throw new InvalidConfigurationException('extensions must be a list of at most 32 strategies.');
        }
        foreach ($extensions as $extension) {
            if (!$extension instanceof Strategy) {
                throw new InvalidConfigurationException('Every extension must implement Strategy.');
            }
        }
        $shared = $partition;
        foreach ($this->policyIdentities() as $identity) {
            if (!$identity->profileVersion->equals($profileVersion)) {
                throw new InvalidConfigurationException('Every policy identity must carry the definition profile version.');
            }
            if ($shared !== null && !$identity->partition->equals($shared)) {
                throw new InvalidConfigurationException('Every policy of one definition must share one partition identity.');
            }
            $shared = $identity->partition;
        }
        $this->partition = $shared;
        $this->assertLeasesOutlastAttempts();
    }

    /**
     * Validates capabilities and backends without I/O, mutation or dispatch.
     *
     * @throws InvalidConfigurationException
     * @throws CapabilityMismatchException
     */
    public function compile(RuntimeEnvironment $runtime): CompiledPipeline
    {
        $capabilities = $runtime->runtime->capabilities();

        if (
            $this->attempt->timeoutRequirement === AttemptTimeoutRequirement::TransportEnforced
            && $capabilities->attemptTimeout !== AttemptTimeoutCapability::TransportEnforced
        ) {
            throw new CapabilityMismatchException('A transport-enforced attempt timeout is not supported by the selected runtime.');
        }

        $scheduling = $runtime->scheduler->capability();
        if ($scheduling === SchedulingCapability::AdapterDrivenQueue && $capabilities->queueOwnership !== QueueOwnership::AdapterOwned) {
            throw new CapabilityMismatchException('An adapter-driven queue scheduler requires an adapter-owned runtime queue.');
        }
        $delaysPossible = $this->retry->maxAttempts->value > 1 && $this->retry->maximumDelay->isPositive();
        if ($delaysPossible) {
            if ($scheduling === SchedulingCapability::None) {
                throw new CapabilityMismatchException('Retry delays require a delay scheduler that can wait.');
            }
            if ($scheduling !== $capabilities->scheduling) {
                throw new CapabilityMismatchException('The delay scheduler capability must equal the runtime scheduling capability.');
            }
        }

        $backends = [
            'rate limit' => [$this->rateLimit, $runtime->rateLimit],
            'circuit' => [$this->circuit, $runtime->circuit],
            'concurrency' => [$this->concurrency, $runtime->concurrency],
            'retry budget' => [$this->retryBudget, $runtime->retryBudget],
            'cooldown' => [$this->cooldown, $runtime->cooldown],
        ];
        foreach ($backends as $label => [$policy, $backend]) {
            if ($policy === null) {
                continue;
            }
            if (!$backend instanceof StateBackend) {
                throw new CapabilityMismatchException('The ' . $label . ' policy is configured but its backend is not installed.');
            }
            // A finite I/O timeout bounds a remote call: it is required only of shared-authority backends.
            if ($this->requireFiniteBackendIo
                && $backend->capabilities()->scope === StateScope::SharedAuthority
                && $backend->capabilities()->ioTimeout === null) {
                throw new CapabilityMismatchException('The ' . $label . ' backend declares no finite I/O timeout.');
            }
        }

        $context = new DefinitionValidationContext($capabilities);
        foreach ($this->extensions as $extension) {
            $extension->validate($context);
        }

        return new CompiledPipeline($this, $runtime, new RandomIdentityGenerator());
    }

    private function assertLeasesOutlastAttempts(): void
    {
        $maximum = $this->attempt->maximumDuration->nanoseconds;
        $leases = [
            'circuit permitLease' => $this->circuit?->permitLease,
            'concurrency lease' => $this->concurrency?->lease,
            'retry budget reservationLease' => $this->retryBudget?->reservationLease,
        ];
        foreach ($leases as $label => $lease) {
            if ($lease === null) {
                continue;
            }
            // maximum + margin < lease, written without overflow.
            if ($lease->nanoseconds <= $maximum || $lease->nanoseconds - $maximum <= self::LEASE_MARGIN_NANOSECONDS) {
                throw new InvalidConfigurationException('The ' . $label . ' must exceed attempt.maximumDuration plus a 1 second cleanup margin.');
            }
        }
    }

    /** @return list<PolicyIdentity> */
    private function policyIdentities(): array
    {
        $identities = [];
        foreach ([$this->rateLimit, $this->circuit, $this->concurrency, $this->retryBudget, $this->cooldown] as $policy) {
            if ($policy !== null) {
                $identities[] = $policy->identity;
            }
        }

        return $identities;
    }
}
