<?php

require_once __DIR__ . '/ConnectionHandler.php';

final class NonBlockingConnectionHandler extends ConnectionHandler
{
    private ?float $connectDeadline = null;

    public function __construct(
        Socket $sockListen,
        string $remoteAddress,
        int $dstPort,
        string $localAddress,
        ?int $sockSendSrcPort,
        private float $connectTimeout
    ) {
        parent::__construct($sockListen, $remoteAddress, $dstPort, $localAddress, $sockSendSrcPort);
    }

    public function isActive(): bool
    {
        return $this->isConnected() || $this->connectDeadline !== null;
    }

    public function startAcceptCnx(): void
    {
        if ($this->msgSockStatus) {
            return;
        }
        $client = @socket_accept($this->sockListen);
        if ($client === false) {
            return;
        }
        $this->msgSock = $client;
        if (!@socket_set_nonblock($client)) {
            $this->failConnect('socket_set_nonblock() failed: ' . socket_strerror(socket_last_error($client)));
            return;
        }
        $this->msgSockStatus = true;
        $this->startConx2Server();
    }

    public function startConx2Server(): void
    {
        if (!$this->msgSockStatus || $this->sockSendStatus || $this->connectDeadline !== null) {
            return;
        }
        $server = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($server === false) {
            $this->failConnect('socket_create() failed: ' . socket_strerror(socket_last_error()));
            return;
        }
        $this->sockSend = $server;
        socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_option($server, SOL_SOCKET, SO_KEEPALIVE, 1);

        if ($this->sockSendSrcPort && !@socket_bind($server, $this->localAddress, $this->sockSendSrcPort)) {
            $this->failConnect('socket_bind() failed: ' . socket_strerror(socket_last_error($server)));
            return;
        }
        if (!@socket_set_nonblock($server)) {
            $this->failConnect('socket_set_nonblock() failed: ' . socket_strerror(socket_last_error($server)));
            return;
        }

        echo "Attempting to connect to '$this->remoteAddress' on port '$this->dstPort'...\n";
        if (@socket_connect($server, $this->remoteAddress, $this->dstPort)) {
            $this->connected();
            return;
        }

        $error = socket_last_error($server);
        foreach (['SOCKET_EINPROGRESS', 'SOCKET_EAGAIN', 'SOCKET_EWOULDBLOCK'] as $name) {
            if (defined($name) && $error === constant($name)) {
                $this->connectDeadline = hrtime(true) / 1e9 + $this->connectTimeout;
                echo "Connection pending.\n";
                return;
            }
        }
        $this->failConnect('socket_connect() failed: ' . socket_strerror($error));
    }

    public function forward(): void
    {
        if ($this->connectDeadline !== null) {
            $this->finishConnect();
        }
        if ($this->isConnected()) {
            parent::forward();
        }
    }

    private function finishConnect(): void
    {
        if (hrtime(true) / 1e9 >= $this->connectDeadline) {
            $this->failConnect('Outbound connection timed out');
            return;
        }

        $read = null;
        $write = $except = [$this->sockSend];
        socket_clear_error();
        $ready = @socket_select($read, $write, $except, 0);
        if ($ready === false) {
            $error = socket_last_error();
            if (!defined('SOCKET_EINTR') || $error !== SOCKET_EINTR) {
                $this->failConnect('socket_select() failed: ' . socket_strerror($error));
            }
            return;
        }
        if ($ready === 0) {
            return;
        }

        // A refused connection can also be reported as writable.
        $error = socket_get_option($this->sockSend, SOL_SOCKET, SO_ERROR);
        if ($error !== 0) {
            $error = $error === false ? socket_last_error($this->sockSend) : $error;
            $this->failConnect('socket_connect() failed: ' . socket_strerror($error));
            return;
        }
        $this->connected();
    }

    private function connected(): void
    {
        $this->connectDeadline = null;
        $this->sockSendStatus = true;
        echo "OK.\n";
    }

    private function failConnect(string $message): void
    {
        $this->connectDeadline = null;
        $this->sockSendStatus = false;
        $this->msgSockStatus = false;
        if (isset($this->sockSend)) {
            socket_close($this->sockSend);
            unset($this->sockSend);
        }
        socket_close($this->msgSock);
        unset($this->msgSock);
        fwrite(STDERR, $message . PHP_EOL);
    }
}
