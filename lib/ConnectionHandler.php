<?php

require_once __DIR__ . '/RelayDirection.php';

class ConnectionHandler
{
    protected ?Socket $msgSock = null;
    protected ?Socket $sockSend = null;
    protected bool $msgSockStatus = false;
    protected bool $sockSendStatus = false;
    private ?RelayDirection $toServer = null;
    private ?RelayDirection $toClient = null;

    public function __construct(
        protected Socket $sockListen,
        protected string $remoteAddress,
        protected int $dstPort,
        protected string $localAddress,
        protected ?int $sockSendSrcPort
    ) {
        $this->startAcceptCnx();
    }

    public function isConnected(): bool
    {
        return $this->msgSockStatus && $this->sockSendStatus;
    }

    public function isMsgSockStatusConnected(): bool
    {
        return $this->msgSockStatus;
    }

    public function isSockSendStatusConnected(): bool
    {
        return $this->sockSendStatus;
    }

    public function getSockSendSrcPort(): ?int
    {
        return $this->sockSendSrcPort;
    }

    public function startAcceptCnx(): void
    {
        if ($this->msgSock !== null) {
            return;
        }

        $client = @socket_accept($this->sockListen);
        if ($client !== false) {
            $this->msgSock = $client;
            $this->msgSockStatus = true;
            $this->startConx2Server();
        }
    }

    public function startConx2Server(): void
    {
        $client = $this->msgSock;
        if ($client === null || $this->sockSend !== null) {
            return;
        }

        try {
            $server = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($server === false) {
                throw self::socketFailure('Creating outbound socket');
            }
            $this->sockSend = $server;
            socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
            socket_set_option($server, SOL_SOCKET, SO_KEEPALIVE, 1);

            if ($this->sockSendSrcPort && !@socket_bind($server, $this->localAddress, $this->sockSendSrcPort)) {
                throw self::socketFailure("Binding source port $this->sockSendSrcPort", $server);
            }
            if (!@socket_connect($server, $this->remoteAddress, $this->dstPort)) {
                throw self::socketFailure('Connecting to the remote server', $server);
            }

            foreach ([$client, $server] as $socket) {
                if (!@socket_set_nonblock($socket)) {
                    throw self::socketFailure('Setting non-blocking mode', $socket);
                }
            }
            $this->sockSendStatus = true;
        } catch (RuntimeException $error) {
            $this->closeWithError($error);
        }
    }

    public function forward(): void
    {
        if (!$this->isConnected()) {
            return;
        }

        // Start the relay only after both sockets are connected.
        $this->toServer ??= new RelayDirection($this->msgSock, $this->sockSend);
        $this->toClient ??= new RelayDirection($this->sockSend, $this->msgSock);

        try {
            $this->toServer->pump();
            $this->toClient->pump();
            if ($this->toServer->isFinished() && $this->toClient->isFinished()) {
                $this->close();
            }
        } catch (RuntimeException $error) {
            $this->closeWithError($error);
        }
    }

    private function close(): void
    {
        $this->toServer = null;
        $this->toClient = null;
        $this->msgSockStatus = false;
        $this->sockSendStatus = false;
        if ($this->msgSock !== null) {
            socket_close($this->msgSock);
            $this->msgSock = null;
        }
        if ($this->sockSend !== null) {
            socket_close($this->sockSend);
            $this->sockSend = null;
        }
    }

    private function closeWithError(RuntimeException $error): void
    {
        $this->close();
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
    }

    private static function socketFailure(string $operation, ?Socket $socket = null): RuntimeException
    {
        return new RuntimeException("$operation: " . socket_strerror(socket_last_error($socket)));
    }
}
