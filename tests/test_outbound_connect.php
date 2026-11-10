<?php

// Run with: php tests/test_outbound_connect.php [path to proxy checkout]
// The backlog cases use Linux's loopback TCP listen queue behavior.
if (PHP_OS_FAMILY !== 'Linux') {
    fwrite(STDOUT, "Skipped: the test needs Linux's TCP backlog behavior.\n");
    exit(0);
}
if (!extension_loaded('sockets')) {
    fwrite(STDERR, "Enable the PHP sockets extension first.\n");
    exit(1);
}

function localSocket(bool $listen = false)
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
    if ($server === false) {
        throw new RuntimeException("Could not bind: $error");
    }
    if ($listen && !socket_listen(socket_import_stream($server), 1)) {
        throw new RuntimeException('Could not start listening');
    }
    return $server;
}

function portOf($stream, bool $peer = false): int
{
    $address = stream_socket_get_name($stream, $peer);
    if ($address === false) {
        throw new RuntimeException('Could not read socket address');
    }
    return (int) substr(strrchr($address, ':'), 1);
}

function freePort(): int
{
    $socket = localSocket();
    return portOf($socket);
}

function connectTo(int $port)
{
    $client = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $error, 1);
    if ($client === false) {
        throw new RuntimeException("Could not connect to port $port: $error");
    }
    return $client;
}

function acceptClient($server, int $timeout = 1)
{
    $peer = @stream_socket_accept($server, $timeout);
    if ($peer === false) {
        throw new RuntimeException('Timed out accepting a connection');
    }
    return $peer;
}

function expectRead($stream, string $expected, string $message): void
{
    stream_set_timeout($stream, 0, 750000);
    $actual = fread($stream, 1);
    if ($actual !== $expected) {
        $reason = stream_get_meta_data($stream)['timed_out'] ? 'read timed out' : 'unexpected data';
        throw new RuntimeException("$message: $reason");
    }
}

function waitForLog(string $log, string $message, int $count = 1): void
{
    $deadline = hrtime(true) + 3_000_000_000;
    do {
        if (substr_count((string) file_get_contents($log), $message) >= $count) {
            return;
        }
        usleep(10000);
    } while (hrtime(true) < $deadline);
    throw new RuntimeException("Proxy did not log: $message");
}

function withProxy(string $root, int $destination, array $sourcePorts, float $timeout, callable $run): void
{
    $directory = sys_get_temp_dir() . '/port-forward-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Could not create the proxy test directory');
    }
    $process = null;
    $log = "$directory/proxy.log";
    try {
        do {
            $port = freePort();
        } while (in_array($port, $sourcePorts, true));

        if (!copy("$root/portForward.php", "$directory/portForward.php") ||
            !symlink("$root/lib", "$directory/lib")) {
            throw new RuntimeException('Could not stage the proxy for the test');
        }
        $config = [
            'localAddress' => '127.0.0.1',
            'remoteAddress' => '127.0.0.1',
            'lclPort' => $port,
            'dstPort' => $destination,
            'socketSendSrcPorts' => $sourcePorts,
            'connectTimeout' => $timeout,
        ];
        if (file_put_contents("$directory/config.php", '<?php return ' . var_export($config, true) . ';') === false) {
            throw new RuntimeException('Could not write the proxy test config');
        }

        // Load sockets in the child even if the test was started with -d extension=sockets.
        $command = [PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir'),
            '-d', 'extension=sockets', 'portForward.php'];
        $process = proc_open($command, [1 => ['file', $log, 'w'], 2 => ['redirect', 1]], $pipes, $directory);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the proxy');
        }
        waitForLog($log, "Listen on 127.0.0.1:$port");
        $run($port, $log);
    } catch (Throwable $error) {
        if (is_file($log)) {
            fwrite(STDERR, substr((string) file_get_contents($log), -1800));
        }
        throw $error;
    } finally {
        if (is_resource($process)) {
            proc_terminate($process, 9);
            proc_close($process);
        }
        foreach (['lib', 'portForward.php', 'config.php', 'proxy.log'] as $entry) {
            if (file_exists("$directory/$entry") || is_link("$directory/$entry")) {
                unlink("$directory/$entry");
            }
        }
        rmdir($directory);
    }
}

function fillBacklog($upstream): array
{
    // Linux queues two completed handshakes for listen(1).
    $port = portOf($upstream);
    return [connectTo($port), connectTo($port)];
}

$root = realpath($argv[1] ?? dirname(__DIR__));
if ($root === false || !is_file("$root/portForward.php")) {
    fwrite(STDERR, "Expected a checkout containing portForward.php.\n");
    exit(1);
}

$tests = [
    'an established session keeps relaying while a new connection waits' => function () use ($root) {
        $upstream = localSocket(true);
        withProxy($root, portOf($upstream), [], 5, function ($port, $log) use ($upstream) {
            $client = connectTo($port);
            $peer = acceptClient($upstream);
            waitForLog($log, 'OK.');

            $queued = fillBacklog($upstream);
            $waiting = connectTo($port);
            waitForLog($log, 'Attempting to connect', 2);

            fwrite($client, 'x');
            expectRead($peer, 'x', 'Existing client could not send');
            fwrite($peer, 'y');
            expectRead($client, 'y', 'Existing client could not receive');
        });
    },
    'a timed-out connection releases its source port' => function () use ($root) {
        $upstream = localSocket(true);
        $source = freePort();
        $queued = fillBacklog($upstream);
        withProxy($root, portOf($upstream), [$source], 0.25, function ($port, $log) use ($upstream, $source) {
            $client = connectTo($port);
            expectRead($client, '', 'Timed-out client was not closed');
            waitForLog($log, 'Outbound connection timed out');
            fclose(acceptClient($upstream));
            fclose(acceptClient($upstream));

            $next = connectTo($port);
            $peer = acceptClient($upstream);
            if (portOf($peer, true) !== $source) {
                throw new RuntimeException('The next client did not get the configured source port');
            }
        });
    },
    'a pending connection starts relaying once the destination is ready' => function () use ($root) {
        $upstream = localSocket(true);
        $queued = fillBacklog($upstream);
        withProxy($root, portOf($upstream), [], 5, function ($port, $log) use ($upstream) {
            $client = connectTo($port);
            waitForLog($log, 'Connection pending.');
            // The backlog is still full, so this byte must wait for the connection.
            fwrite($client, 'x');
            fclose(acceptClient($upstream));
            fclose(acceptClient($upstream));

            $peer = acceptClient($upstream, 4);
            expectRead($peer, 'x', 'Pending client did not start sending');
            fwrite($peer, 'y');
            expectRead($client, 'y', 'Pending client did not start receiving');
        });
    },
    'a refused connection releases its source port' => function () use ($root) {
        $upstream = localSocket();
        $source = freePort();
        withProxy($root, portOf($upstream), [$source], 5, function ($port, $log) use ($upstream, $source) {
            $client = connectTo($port);
            expectRead($client, '', 'Refused client was not closed');
            waitForLog($log, 'socket_connect() failed');
            if (!socket_listen(socket_import_stream($upstream), 1)) {
                throw new RuntimeException('Could not start listening');
            }

            $next = connectTo($port);
            $peer = acceptClient($upstream);
            if (portOf($peer, true) !== $source) {
                throw new RuntimeException('The next client did not get the configured source port');
            }
        });
    },
];

$failed = 0;
foreach ($tests as $description => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS: $description\n");
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, "FAIL: $description: {$error->getMessage()}\n");
    }
}
exit($failed === 0 ? 0 : 1);
