<?php

declare(strict_types=1);

/**
 * 12 - Fencing downstream: the store you write to refuses a write from a holder that has been replaced.
 * This is the code shown in https://enaloo.com/apps/reqtide/docs/fencing-downstream/.
 *
 * Reqtide offers no fencing token. A bulkhead lease can expire while the first callback still runs, so the system that
 * receives the write must be the one that tells a current holder from a stale one. The store here is SQLite in memory.
 *
 * Process-local, in-memory state only. Needs the pdo_sqlite extension.
 *
 * Run:  php examples/12-fencing-downstream.php
 */

namespace Reqtide\Examples\FencingDownstream;

use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Value\Duration;

require __DIR__ . '/autoload.php';

$store = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
$store->exec('CREATE TABLE jobs (id TEXT PRIMARY KEY, fence INTEGER NOT NULL, result TEXT)');
$store->exec("INSERT INTO jobs (id, fence, result) VALUES ('invoice-7', 0, NULL)");

/** Takes the job and returns its new fence. The number only goes up, and only the store hands it out. */
$claim = static function (string $job) use ($store): int {
    $store->beginTransaction();
    $store->prepare('UPDATE jobs SET fence = fence + 1 WHERE id = ?')->execute([$job]);
    $select = $store->prepare('SELECT fence FROM jobs WHERE id = ?');
    $select->execute([$job]);
    $fence = (int) $select->fetchColumn();
    $store->commit();

    return $fence;
};

/** Writes the result only if the fence is still the newest one. Returns false for a stale holder. */
$write = static function (string $job, int $fence, string $result) use ($store): bool {
    $update = $store->prepare('UPDATE jobs SET result = ? WHERE id = ? AND fence = ?');
    $update->execute([$result, $job, $fence]);

    return $update->rowCount() === 1;
};

$pipeline = PipelineBuilder::profile('invoices', 'v1')
    ->partition('shop', 'prod', 'invoice-writer')
    ->attemptTimeout(Duration::seconds(2), AttemptTimeoutRequirement::Cooperative)
    ->bulkhead(1, Duration::seconds(10))
    ->compileInMemory(ExceptionClassifier::create());

// Worker A takes the job and gets fence 1. Then it stalls: a slow call, a paused process, a long garbage collection.
$staleFence = $claim('invoice-7');

echo "-- 1. The current holder writes\n";
$outcome = $pipeline->execute(
    static function (AttemptContext $attempt) use ($claim, $write): string {
        $fence = $claim('invoice-7');

        return $write('invoice-7', $fence, 'written by ' . $attempt->operationId->value) ? "accepted with fence {$fence}" : "refused with fence {$fence}";
    },
    $pipeline->newContext(Duration::seconds(30), operationId: 'worker-b'),
);
echo $outcome, "\n";

echo "-- 2. A holder that lost its slot writes late\n";
// Worker A wakes up. Its lease ran out long ago, and worker B has taken the job with a newer fence.
echo $write('invoice-7', $staleFence, 'written by worker-a') ? "accepted with fence {$staleFence}" : "refused with fence {$staleFence}", "\n";

$row = $store->query("SELECT fence, result FROM jobs WHERE id = 'invoice-7'")->fetch(\PDO::FETCH_ASSOC);
echo 'stored: fence ', $row['fence'], ', ', $row['result'], "\n";
