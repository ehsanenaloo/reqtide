<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Runtime;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;

/**
 * Combination rules:
 *  - Synchronous  <=> InlineReturnOrThrow; a synchronous adapter owns no queue and, because a blocked
 *    call cannot sample cancellation, offers checkpoint-only cancellation.
 *  - ObservedPromise <=> TerminalPromiseSettlement and requires an adapter-owned queue.
 *  - AdapterOwned queue <=> AdapterDrivenQueue scheduling.
 */
final readonly class RuntimeCapabilities
{
    public function __construct(
        public InvocationForm $invocation,
        public AttemptTimeoutCapability $attemptTimeout,
        public SchedulingCapability $scheduling,
        public QueueOwnership $queueOwnership,
        public CancellationCapability $cancellation,
        public SettlementCapability $settlement,
        public DetachedCompletionCapability $detachedCompletion,
        public NonblockingCapability $nonblocking,
        public PhysicalStopCapability $physicalStop,
    ) {
        $synchronous = $invocation === InvocationForm::Synchronous;

        if ($synchronous !== ($settlement === SettlementCapability::InlineReturnOrThrow)) {
            throw new InvalidConfigurationException('Synchronous invocation requires inline settlement, and promise observation requires terminal promise settlement.');
        }
        if (($queueOwnership === QueueOwnership::AdapterOwned) !== ($scheduling === SchedulingCapability::AdapterDrivenQueue)) {
            throw new InvalidConfigurationException('An adapter-owned queue and adapter-driven-queue scheduling require each other.');
        }
        if ($synchronous && $queueOwnership !== QueueOwnership::None) {
            throw new InvalidConfigurationException('A synchronous runtime cannot own a queue.');
        }
        if (!$synchronous && $queueOwnership !== QueueOwnership::AdapterOwned) {
            throw new InvalidConfigurationException('An observed promise requires an adapter-owned queue.');
        }
        if ($synchronous && $cancellation !== CancellationCapability::CheckpointOnly) {
            throw new InvalidConfigurationException('A synchronous runtime cannot claim transport cancellation.');
        }
    }
}
