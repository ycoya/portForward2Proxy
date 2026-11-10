<?php

// Run with: php tests/test_port_forward.php [path to proxy checkout]
const TIMEOUT = 8;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function localSocket()
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
    check($server !== false, "Could not bind: $error");
    return $server;
}

function portOf($stream, bool $peer = false): int
{
    $address = stream_socket_get_name($stream, $peer);
    check($address !== false, 'Could not read socket address');
    return (int) substr(strrchr($address, ':'), 1);
}

function connectTo(int $port)
{
    $client = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $error, TIMEOUT);
    check($client !== false, "Could not connect to port $port: $error");
    stream_set_timeout($client, TIMEOUT);
    return $client;
}

function acceptClient($server)
{
    $peer = @stream_socket_accept($server, TIMEOUT);
    check($peer !== false, 'Timed out accepting a connection');
    stream_set_timeout($peer, TIMEOUT);
    return $peer;
}

function sendBytes($socket, string $data): void
{
    $offset = 0;
    while ($offset < strlen($data)) {
        $written = @fwrite($socket, substr($data, $offset));
        check($written !== false && $written > 0, 'Could not write to peer');
        $offset += $written;
    }
}

function readBytes($socket, int $length = 0): string
{
    $data = '';
    while (!feof($socket) && ($length === 0 || strlen($data) < $length)) {
        $chunk = @fread($socket, $length === 0 ? 65536 : $length - strlen($data));
        check($chunk !== false && !stream_get_meta_data($socket)['timed_out'], 'Could not read from peer');
        $data .= $chunk;
    }
    return $data;
}

function halfClose($socket): void
{
    check(stream_socket_shutdown($socket, STREAM_SHUT_WR), 'Could not close the write side');
}

function withProxy(string $root, callable $test, array $sourcePorts = [], bool $slowReader = false): void
{
    $upstream = localSocket();
    $socket = socket_import_stream($upstream);
    if ($slowReader) {
        check(socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, 65536), 'Could not limit the receive buffer');
    }
    check(socket_listen($socket, 5), 'Could not start listening');

    $reservation = localSocket();
    $port = portOf($reservation);
    fclose($reservation);
    $dir = sys_get_temp_dir() . '/port-forward-' . bin2hex(random_bytes(8));
    check(mkdir($dir, 0700), 'Could not create a temporary directory');
    $log = "$dir/proxy.log";
    $proxy = null;
    try {
        check(copy("$root/portForward.php", "$dir/portForward.php") && symlink("$root/lib", "$dir/lib"),
            'Could not stage the proxy');
        $config = [
            'localAddress' => '127.0.0.1',
            'remoteAddress' => '127.0.0.1',
            'lclPort' => $port,
            'dstPort' => portOf($upstream),
            'socketSendSrcPorts' => $sourcePorts,
        ];
        check(file_put_contents("$dir/config.php", '<?php return ' . var_export($config, true) . ';') !== false,
            'Could not write the proxy config');
        // Carry over the extension path for PHP installations that load sockets separately.
        $command = [PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir'),
            '-d', 'extension=sockets', 'portForward.php'];
        $proxy = proc_open($command, [1 => ['file', $log, 'w'], 2 => ['redirect', 1]], $pipes, $dir);
        check(is_resource($proxy), 'Could not start the proxy');
        $deadline = hrtime(true) + 3_000_000_000;
        while (!str_contains((string) file_get_contents($log), "Listen on 127.0.0.1:$port")) {
            check(proc_get_status($proxy)['running'] && hrtime(true) < $deadline, 'Proxy did not start');
            usleep(10000);
        }
        $test($port, $upstream);
    } finally {
        if (is_resource($proxy)) {
            proc_terminate($proxy, 9);
            proc_close($proxy);
        }
        foreach (['lib', 'portForward.php', 'config.php', 'proxy.log'] as $name) {
            if (is_link("$dir/$name") || file_exists("$dir/$name")) {
                unlink("$dir/$name");
            }
        }
        rmdir($dir);
    }
}

function checkSlowReader(int $port, $upstream): void
{
    $payload = str_repeat(hash('sha256', 'port-forward backpressure', true), 262144);
    $client = connectTo($port);
    $server = acceptClient($upstream);
    check(socket_set_option(socket_import_stream($client), SOL_SOCKET, SO_SNDBUF, 65536),
        'Could not limit the send buffer');
    stream_set_blocking($client, false);
    stream_set_blocking($server, false);
    $size = strlen($payload);
    $sent = 0;
    $closed = false;

    // Keep the receiver unread long enough for the proxy's write buffers to fill.
    $pauseUntil = hrtime(true) + 250_000_000;
    while ($sent < $size && hrtime(true) < $pauseUntil) {
        $written = @fwrite($client, substr($payload, $sent, 65536));
        if ($written === false) {
            $closed = true;
            break;
        }
        $sent += $written;
        if ($written === 0) {
            usleep(1000);
        }
    }
    check($sent > 0 && $sent < $size, 'The upload did not hit backpressure');

    $other = connectTo($port);
    $peer = acceptClient($upstream);
    sendBytes($other, "\0ping");
    check(readBytes($peer, 5) === "\0ping", 'Second client could not send');
    sendBytes($peer, 'pong');
    check(readBytes($other, 4) === 'pong', 'Second client could not receive');

    $hash = hash_init('sha256');
    $received = 0;
    $deadline = hrtime(true) + TIMEOUT * 1_000_000_000;
    while (!feof($server)) {
        $remaining = $deadline - hrtime(true);
        check($remaining > 0, 'Timed out waiting for the upload to finish');
        $read = [$server];
        $write = $closed ? [] : [$client];
        $except = null;
        $ready = stream_select($read, $write, $except, intdiv($remaining, 1_000_000_000),
            intdiv($remaining % 1_000_000_000, 1000));
        check($ready !== false, 'Could not wait for upload sockets');

        if ($write) {
            $written = @fwrite($client, substr($payload, $sent, 65536));
            $closed = $written === false;
            $sent += $written === false ? 0 : $written;
            if ($sent === $size) {
                halfClose($client);
                $closed = true;
            }
        }
        if ($read) {
            $chunk = @fread($server, 65536);
            check($chunk !== false, 'Could not read the upload');
            $received += strlen($chunk);
            hash_update($hash, $chunk);
        }
    }
    check($received === $size, "Received $received of $size bytes");
    check(hash_final($hash) === hash('sha256', $payload), 'The SHA-256 digest did not match');
}

if (PHP_OS_FAMILY !== 'Linux' || !extension_loaded('sockets')) {
    fwrite(STDERR, "Run on Linux with the PHP sockets extension enabled.\n");
    exit(1);
}
$root = realpath($argv[1] ?? dirname(__DIR__));
if ($root === false || !is_file("$root/portForward.php")) {
    fwrite(STDERR, "Expected a checkout containing portForward.php.\n");
    exit(1);
}

$tests = [
    'client can finish sending and still receive a reply' => function () use ($root) {
        withProxy($root, function ($port, $upstream) {
            $client = connectTo($port);
            $server = acceptClient($upstream);
            sendBytes($client, 'request');
            halfClose($client);
            check(readBytes($server) === 'request', 'Upstream did not receive the request');
            sendBytes($server, 'response after EOF');
            halfClose($server);
            check(readBytes($client) === 'response after EOF', 'Client lost the reply after half-close');
        });
    },
    'server can finish sending and still receive data' => function () use ($root) {
        withProxy($root, function ($port, $upstream) {
            $client = connectTo($port);
            $server = acceptClient($upstream);
            sendBytes($server, 'ready');
            halfClose($server);
            check(readBytes($client) === 'ready', 'Client did not receive the server message');
            sendBytes($client, 'upload after EOF');
            halfClose($client);
            check(readBytes($server) === 'upload after EOF', 'Server lost the upload after half-close');
        });
    },
    'slow receiver gets all bytes while another client transfers' => function () use ($root) {
        withProxy($root, 'checkSlowReader', [], true);
    },
    'uses the configured outbound source port' => function () use ($root) {
        $reservation = localSocket();
        $sourcePort = portOf($reservation);
        fclose($reservation);
        withProxy($root, function ($port, $upstream) use ($sourcePort) {
            $client = connectTo($port);
            $server = acceptClient($upstream);
            check(portOf($server, true) === $sourcePort, 'Proxy did not use the configured source port');
            sendBytes($client, 'x');
            check(readBytes($server, 1) === 'x', 'Upstream did not receive the byte');
        }, [$sourcePort]);
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
