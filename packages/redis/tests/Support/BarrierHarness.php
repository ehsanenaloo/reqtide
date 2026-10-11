<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Support;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Assert;
use Predis\Client;

/**
 * Runs one scenario in N independent PHP processes against one registry. The coordinator starts the workers,
 * waits until each prints READY, publishes a start instant 600 ms ahead on the server clock, and collects one JSON line
 * per worker. A worker that does not finish within the limit is killed and the test fails. Nothing here sleeps to hide a
 * race: the only waits are process start-up, the barrier and the limit.
 */
final class BarrierHarness
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $spec scenario and its parameters (workerId, prefix and connection data are added)
     * @param ?\Closure(): void $tick called about every 2 ms while the workers run (for sampling)
     * @param ?string $worker path of another worker script that follows the same protocol (READY, start key, one JSON line)
     * @return list<array<string, mixed>> the result of each worker, in worker order
     */
    public static function run(RedisEndpoint $endpoint, string $prefix, string $identity, string $secret, int $workers, array $spec, int $maxPartitions = 10_000, int $maxRecords = 20_000, float $limitSeconds = 60.0, ?\Closure $tick = null, ?string $worker = null): array
    {
        $client = new Client($endpoint->parameters(10.0));
        $loader = (new \ReflectionClass(ClassLoader::class))->getFileName();
        Assert::assertIsString($loader);
        $autoload = dirname($loader, 2) . '/autoload.php';

        $processes = [];
        $pipes = [];
        for ($i = 0; $i < $workers; ++$i) {
            $argument = json_encode($spec + [
                'autoload' => $autoload,
                'workerId' => $i,
                'prefix' => $prefix,
                'identity' => $identity,
                'secret' => $secret,
                'maxPartitions' => $maxPartitions,
                'maxRecords' => $maxRecords,
                'host' => $endpoint->host,
                'port' => $endpoint->port,
                'seed' => 1_234_567,
            ], JSON_THROW_ON_ERROR);
            $process = proc_open([PHP_BINARY, $worker ?? __DIR__ . '/barrier-worker.php', $argument], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $workerPipes);
            Assert::assertIsResource($process, 'a worker did not start');
            fclose($workerPipes[0]);
            stream_set_blocking($workerPipes[1], false);
            stream_set_blocking($workerPipes[2], false);
            $processes[$i] = $process;
            $pipes[$i] = $workerPipes;
        }

        try {
            $deadline = hrtime(true) + (int) ($limitSeconds * 1e9);
            $lines = array_fill(0, $workers, '');
            $ready = array_fill(0, $workers, false);
            while (in_array(false, $ready, true)) {
                self::pump($pipes, $lines, $deadline, 'start-up');
                foreach ($lines as $i => $buffer) {
                    $ready[$i] = str_contains($buffer, "READY\n");
                }
                usleep(2_000);
            }
            /** @var array{0: numeric-string, 1: numeric-string} $time */
            $time = $client->time();
            $client->set('start:' . $prefix, sprintf('%.6f', (float) $time[0] + (float) $time[1] / 1e6 + 0.6));

            $finished = array_fill(0, $workers, false);
            while (in_array(false, $finished, true)) {
                self::pump($pipes, $lines, $deadline, 'run');
                if ($tick !== null) {
                    $tick();
                }
                foreach ($lines as $i => $buffer) {
                    $finished[$i] = substr_count($buffer, "\n") >= 2 || !(proc_get_status($processes[$i])['running']);
                }
                usleep(2_000);
            }
            self::pump($pipes, $lines, $deadline, 'drain');
        } finally {
            foreach ($processes as $i => $process) {
                proc_terminate($process, 9);
                foreach ([1, 2] as $k) {
                    @fclose($pipes[$i][$k]);
                }
                proc_close($process);
            }
        }

        $results = [];
        foreach ($lines as $i => $buffer) {
            $rows = array_values(array_filter(explode("\n", $buffer), static fn(string $line): bool => $line !== '' && $line !== 'READY'));
            Assert::assertNotSame([], $rows, 'worker ' . $i . ' printed no result');
            $decoded = json_decode($rows[0], true, 16, JSON_THROW_ON_ERROR);
            Assert::assertIsArray($decoded);
            /** @var array<string, mixed> $decoded */
            $results[] = $decoded;
        }

        return $results;
    }

    /**
     * @param array<int, array<int, resource>> $pipes
     * @param array<int, string> $lines
     */
    private static function pump(array $pipes, array &$lines, int $deadline, string $phase): void
    {
        if (hrtime(true) > $deadline) {
            Assert::fail('the workers did not finish in time (' . $phase . ')');
        }
        foreach ($pipes as $i => $workerPipes) {
            $chunk = stream_get_contents($workerPipes[1]);
            if (is_string($chunk) && $chunk !== '') {
                $lines[$i] .= $chunk;
            }
        }
    }
}
