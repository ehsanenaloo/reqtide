<?php

declare(strict_types=1);

/**
 * Child process of TruncatedReplyBoundedTimeTest: connects with Predis to a peer that closes in the middle of a
 * bulk reply and reports how the client ended and how long it took.
 *
 *   php truncated-reply-client.php <autoload.php> <port>
 *
 * Prints one line: "<exception class>\t<elapsed milliseconds>". Predis 3.6.1 never returns from this call (a busy loop
 * that ignores read_write_timeout); the parent kills the process after a deadline.
 */

use Predis\Client;

$arguments = $_SERVER['argv'] ?? [];
$autoload = is_array($arguments) ? ($arguments[1] ?? null) : null;
$port = is_array($arguments) ? ($arguments[2] ?? null) : null;
if (!is_string($autoload) || !is_string($port) || !ctype_digit($port)) {
    fwrite(STDERR, "usage: truncated-reply-client.php <autoload.php> <port>\n");
    exit(2);
}

require $autoload;

$started = hrtime(true);
$class = 'none';
try {
    $client = new Client(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => (int) $port, 'timeout' => 1.0, 'read_write_timeout' => 1.0]);
    $client->ping();
} catch (Throwable $exception) {
    $class = $exception::class;
}

echo $class, "\t", intdiv(hrtime(true) - $started, 1_000_000), "\n";
