<?php

declare(strict_types=1);

/**
 * A TCP proxy between a Redis client and a server that places a fault at a chosen point. It starts as
 *
 *     php fault-proxy.php --upstream=HOST:PORT --mode-file=FILE
 *
 * prints "LISTENING <port>" on standard output and runs until it is terminated. The mode is read from the file before
 * every client chunk, so a test changes it at any time:
 *
 *   pass          forward everything
 *   drop-request  a chunk that carries an EVALSHA is not forwarded; both sides are closed (nothing is applied)
 *   drop-reply    the EVALSHA chunk is forwarded, the server executes it, the reply is discarded and both sides are closed
 *   stall         the EVALSHA chunk is forwarded, the reply is discarded and the connection stays open (the client times out)
 *   <fault>@N     the same fault for the N-th EVALSHA chunk after the mode was written, once; then the mode returns to pass
 *
 * Only a chunk with EVALSHA is affected, so the handshake, SCRIPT LOAD and every other command pass.
 */

$options = getopt('', ['upstream:', 'mode-file:']);
$upstream = is_string($options['upstream'] ?? null) ? $options['upstream'] : '';
$modeFile = is_string($options['mode-file'] ?? null) ? $options['mode-file'] : '';
if (!str_contains($upstream, ':') || $modeFile === '') {
    fwrite(STDERR, "usage: fault-proxy.php --upstream=HOST:PORT --mode-file=FILE\n");
    exit(2);
}

$listener = stream_socket_server('tcp://0.0.0.0:0', $errno, $error);
if ($listener === false) {
    fwrite(STDERR, 'cannot listen: ' . $error . "\n");
    exit(1);
}
$name = (string) stream_socket_get_name($listener, false);
echo 'LISTENING ' . substr($name, (int) strrpos($name, ':') + 1) . "\n";
fflush(STDOUT);

/** @var array<int, array{client: resource, server: resource, discard: false|string}> $pairs */
$pairs = [];

$lastMode = '';
$seen = 0;

/** The fault for this EVALSHA chunk, or 'pass'. A `<fault>@N` mode counts chunks and rewrites the file when it fires. */
$mode = static function () use ($modeFile, &$lastMode, &$seen): string {
    $text = @file_get_contents($modeFile);
    $text = is_string($text) && $text !== '' ? trim($text) : 'pass';
    if ($text !== $lastMode) {
        $lastMode = $text;
        $seen = 0;
    }
    if (preg_match('~^([a-z-]+)@([0-9]+)$~', $text, $m) === 1) {
        if (++$seen < (int) $m[2]) {
            return 'pass';
        }
        file_put_contents($modeFile, 'pass');
        $lastMode = 'pass';

        return $m[1];
    }

    return $text;
};

$close = static function (int $id) use (&$pairs): void {
    if (isset($pairs[$id])) {
        @fclose($pairs[$id]['client']);
        @fclose($pairs[$id]['server']);
        unset($pairs[$id]);
    }
};

while (true) { // @phpstan-ignore while.alwaysTrue
    $read = [$listener];
    foreach ($pairs as $pair) {
        $read[] = $pair['client'];
        $read[] = $pair['server'];
    }
    $write = $except = null;
    if (stream_select($read, $write, $except, 1) === false) {
        continue;
    }
    foreach ($read as $stream) {
        if ($stream === $listener) {
            $client = @stream_socket_accept($listener, 0);
            if ($client === false) {
                continue;
            }
            $server = @stream_socket_client('tcp://' . $upstream, $errno, $error, 2.0);
            if ($server === false) {
                fclose($client);
                continue;
            }
            $pairs[(int) $client] = ['client' => $client, 'server' => $server, 'discard' => false];
            continue;
        }
        foreach ($pairs as $id => $pair) {
            if ($stream === $pair['client']) {
                $data = fread($stream, 65536);
                if ($data === '' || $data === false) {
                    $close($id);
                    break;
                }
                if (str_contains($data, 'EVALSHA')) {
                    $current = $mode();
                    if ($current === 'drop-request') {
                        $close($id);
                        break;
                    }
                    if ($current === 'drop-reply' || $current === 'stall') {
                        $pairs[$id]['discard'] = $current;
                    }
                }
                fwrite($pair['server'], $data);
                break;
            }
            if ($stream === $pair['server']) {
                $data = fread($stream, 65536);
                if ($data === '' || $data === false) {
                    $close($id);
                    break;
                }
                if ($pair['discard'] === 'drop-reply') {
                    $close($id);
                    break;
                }
                if ($pair['discard'] === 'stall') {
                    break;
                }
                fwrite($pair['client'], $data);
                break;
            }
        }
    }
}
