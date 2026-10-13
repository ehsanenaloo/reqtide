<?php

declare(strict_types=1);

/**
 * The application of the PHP-FPM lane (tools/check-fpm.sh). Every request builds its pipeline from nothing, as an FPM
 * request does, and answers one line of JSON. The driver (tools/fpm-lane/drive.php) sends requests to it through nginx.
 *
 *   /memory            one call under a process-local rate limit of one token per hour
 *   /redis?run=ID      the same limit in a Redis registry shared by all workers
 *   /bulkhead?run=ID   a 600 ms call under a Redis bulkhead of two permits
 *   /deadline          a callable that runs past a 300 ms deadline
 *   /retry?run=ID      a callable that fails once, retried once, under a Redis retry budget
 *   /info              SAPI, PHP version, worker pid
 */

use Enaxon\Reqtide\Exception\AdmissionDeferredException;
use Enaxon\Reqtide\Exception\AdmissionRejectedException;
use Enaxon\Reqtide\Exception\DeadlineExceededException;
use Enaxon\Reqtide\Execution\AttemptContext;
use Enaxon\Reqtide\Execution\OperationSafety;
use Enaxon\Reqtide\Execution\ReplaySafetyContract;
use Enaxon\Reqtide\Outcome\ExceptionClassifier;
use Enaxon\Reqtide\Pipeline\PipelineBuilder;
use Enaxon\Reqtide\Policy\AttemptTimeoutRequirement;
use Enaxon\Reqtide\Policy\BackoffMode;
use Enaxon\Reqtide\Policy\JitterMode;
use Enaxon\Reqtide\Redis\RedisConnectionOptions;
use Enaxon\Reqtide\Redis\RedisEnvironment;
use Enaxon\Reqtide\Redis\RedisRegistry;
use Enaxon\Reqtide\Value\Duration;

require '/opt/reqtide-vendor/autoload.php';

header('Content-Type: application/json');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY), $query);
$run = is_string($query['run'] ?? null) && preg_match('~^[a-z0-9]{1,32}$~', $query['run']) === 1 ? $query['run'] : 'none';

$answer = static function (array $data): never {
    $data['pid'] = getmypid();
    $data['sapi'] = PHP_SAPI;
    echo json_encode($data), "\n";
    exit;
};

$connection = static fn(): RedisConnectionOptions => new RedisConnectionOptions((string) getenv('RT_REDIS_HOST'), 2.0, 10.0, (int) (getenv('RT_REDIS_PORT') ?: 6379));
// The registry is created by the host (the driver); a request only uses it.
$registry = static fn(): RedisRegistry => new RedisRegistry('fpm_' . $run, 'fpm-lane', 'a-host-managed-secret-of-32-bytes!!');

$builder = static fn(): PipelineBuilder => PipelineBuilder::profile('fpm-lane', 'v1')
    ->partition('fpm', 'lane', 'subject')
    ->attemptTimeout(Duration::seconds(5), AttemptTimeoutRequirement::Cooperative);

switch ($path) {
    case '/info':
        $answer(['ok' => true, 'php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'uname' => php_uname('s') . ' ' . php_uname('r')]);

        // no break
    case '/memory':
        $b = $builder()->rateLimit(PipelineBuilder::tokenBucket(1, 1, Duration::seconds(3600)));
        $pipeline = $b->compileInMemory(ExceptionClassifier::create());
        break;
    case '/redis':
        $b = $builder()->rateLimit(PipelineBuilder::tokenBucket(1, 1, Duration::seconds(3600)));
        $pipeline = $b->compile(RedisEnvironment::for($b->definition(), ExceptionClassifier::create(), $connection(), $registry()));
        break;
    case '/bulkhead':
        $b = $builder()->bulkhead(2, Duration::seconds(30));
        $pipeline = $b->compile(RedisEnvironment::for($b->definition(), ExceptionClassifier::create(), $connection(), $registry()));
        break;
    case '/deadline':
        $pipeline = $builder()->compileInMemory(ExceptionClassifier::create());
        break;
    case '/retry':
        $b = $builder()
            ->retry(2, BackoffMode::Constant, Duration::milliseconds(20), Duration::milliseconds(20), JitterMode::None)
            ->retryBudget(5, 5, Duration::seconds(60), Duration::seconds(10));
        $pipeline = $b->compile(RedisEnvironment::for($b->definition(), ExceptionClassifier::create()->retryOn(\RuntimeException::class), $connection(), $registry()));
        break;
    default:
        http_response_code(404);
        $answer(['ok' => false, 'error' => 'unknown path']);
}

$started = microtime(true);
try {
    $work = static function (AttemptContext $attempt) use ($path): string {
        if ($path === '/bulkhead') {
            usleep(600_000);
        }
        if ($path === '/deadline') {
            usleep(500_000);
        }
        if ($path === '/retry' && $attempt->dispatchedAttemptNumber === 1) {
            throw new \RuntimeException('first attempt fails');
        }

        return 'served';
    };
    if ($path === '/retry') {
        $contract = ReplaySafetyContract::forReadOnlyCallable('fpm.retry', Duration::seconds(30));
        $context = $pipeline->newContext(Duration::seconds(10), OperationSafety::replayableRead($contract));
    } else {
        $context = $pipeline->newContext(Duration::milliseconds($path === '/deadline' ? 300 : 10_000));
    }
    $pipeline->execute($work, $context);
    $answer(['ok' => true, 'result' => 'served', 'ms' => (int) round((microtime(true) - $started) * 1000)]);
} catch (AdmissionDeferredException|AdmissionRejectedException $refusal) {
    $answer(['ok' => true, 'result' => 'refused', 'reason' => $refusal->problem()->code->value]);
} catch (DeadlineExceededException) {
    $answer(['ok' => true, 'result' => 'deadline', 'ms' => (int) round((microtime(true) - $started) * 1000)]);
} catch (\Throwable $failure) {
    http_response_code(500);
    $answer(['ok' => false, 'error' => get_class($failure)]);
}
