<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis;

use Enaxon\Reqtide\Exception\CorruptStateException;
use Enaxon\Reqtide\Exception\InfrastructureException;
use Enaxon\Reqtide\Redis\Client\CommandEffect;
use Enaxon\Reqtide\Redis\Client\RedisBridge;
use Enaxon\Reqtide\Redis\Script\ScriptLibrary;
use Enaxon\Reqtide\Redis\State\ScriptReply;

/**
 * Bounded maintenance of a registry, run by the host (a scheduler, a worker loop or a command). One step looks at no more
 * than 128 terminal records at the head of the registry queue and no more than 128 candidates of the registry index in
 * one atomic script call. It removes:
 *
 *  - terminal records (finalization tombstones, terminal budget and circuit records) whose retention of 120 seconds has
 *    passed;
 *  - rate-limit and retry-budget partitions that are equivalent to fresh state after 120 seconds without a write;
 *  - expired leases of concurrency partitions, and the partition when nothing is left in it;
 *  - cooldowns whose not-before instant and cleanup grace have passed;
 *  - closed circuits without history, permits or terminal records after 120 seconds without a write.
 *
 * It never touches an unexpired lease, a pending reservation, a retry budget balance, an Open or HalfOpen circuit, a
 * closed circuit's count history or a cooldown that is still in force. The queue head and the score order of the index
 * are the persisted cursor: each step continues where the last one stopped, on any machine and after a restart. A step
 * with `more` set found a full batch; call again until it is false. One step never scans the whole registry.
 *
 * It is neutral: it writes no event and does not change a decision. It does not create a missing registry.
 */
final class RedisRegistryMaintenance
{
    private readonly RedisBridge $bridge;

    public function __construct(RedisConnectionOptions $connection, private readonly RedisRegistry $registry, ?RedisBridge $bridge = null)
    {
        $this->bridge = $bridge ?? RedisBridge::forOptions($connection);
    }

    /**
     * @return array{terminalRecords: int, partitions: int, more: bool} what this step removed and whether another step may find more
     * @throws InfrastructureException
     */
    public function step(): array
    {
        $layout = $this->registry->keys();
        $reply = $this->bridge->execute(
            ScriptLibrary::maintain(),
            [$layout->metadata(), $layout->tombstoneQueue(), $layout->rateIndex()],
            [$this->registry->identity, (string) RedisRegistry::SCHEMA_VERSION],
            CommandEffect::Mutating,
        );
        $items = ScriptReply::strings($reply, 3, 3);

        if ($items[2] !== '0' && $items[2] !== '1') {
            throw new CorruptStateException('A Redis state reply has an unexpected shape.');
        }

        return ['terminalRecords' => ScriptReply::integer($items[0]), 'partitions' => ScriptReply::integer($items[1]), 'more' => $items[2] === '1'];
    }
}
