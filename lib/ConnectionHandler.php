<?php

class ConnectionHandler
{
    protected Socket|bool $msgSock;
    protected Socket|bool $sockSend;
    protected bool $sockSendStatus = false;
    protected bool $msgSockStatus = false;
    private ?float $connectDeadline = null;

    public function __construct(
        protected Socket $sockListen,
        protected string $remoteAddress,
        protected int $dstPort,
        protected string $localAddress,
        protected int|NULL $sockSendSrcPort,
        private float $connectTimeout = 5.0 )
    {
      $this->startAcceptCnx();
    }

    public function isConnected()
    {
        return $this->sockSendStatus && $this->msgSockStatus;
    }

    public function isActive(): bool
    {
        return $this->isConnected() || $this->connectDeadline !== null;
    }

    public function isMsgSockStatusConnected() : bool
    {
        return $this->msgSockStatus;
    }

    public function isSockSendStatusConnected() : bool
    {
        return $this->sockSendStatus;
    }

    public function getSockSendSrcPort()
    {
        return $this->sockSendSrcPort;
    }

    public function startAcceptCnx()
    {
        // echo "waiting...\n";
        $this->msgSock = socket_accept($this->sockListen);
        if ($this->msgSock) {
           echo "new connection entered\n";
           socket_set_nonblock($this->msgSock);
           $this->msgSockStatus = true;
           $this->startConx2Server();
        }
    }

    public function startConx2Server()
    {
        $this->sockSend = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->sockSend === false) {
            $this->failConnect('socket_create() failed: ' . socket_strerror(socket_last_error()));
            return;
        }
        socket_set_option($this->sockSend, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_option($this->sockSend, SOL_SOCKET, SO_KEEPALIVE, 1);

        if ($this->sockSendSrcPort) {
           if (!@socket_bind($this->sockSend, $this->localAddress, $this->sockSendSrcPort)) {
               $this->failConnect('socket_bind() failed: ' . socket_strerror(socket_last_error($this->sockSend)));
               return;
           }
           echo "using srcPort as outbound connection: $this->sockSendSrcPort\n";
        }
        if (!@socket_set_nonblock($this->sockSend)) {
            $this->failConnect('socket_set_nonblock() failed: ' . socket_strerror(socket_last_error($this->sockSend)));
            return;
        }

        echo "Attempting to connect to '$this->remoteAddress' on port '$this->dstPort'...";
        if (@socket_connect($this->sockSend, $this->remoteAddress, $this->dstPort)) {
            $this->sockSendStatus = true;
            echo "OK.\n";
            return;
        }

        $error = socket_last_error($this->sockSend);
        // Winsock reports WSAEWOULDBLOCK (10035) for a pending connect.
        if ($error === SOCKET_EINPROGRESS || $error === SOCKET_EAGAIN ||
            (defined('SOCKET_EWOULDBLOCK') && $error === SOCKET_EWOULDBLOCK)) {
            $this->connectDeadline = hrtime(true) / 1e9 + $this->connectTimeout;
            return;
        }

        $this->failConnect('socket_connect() failed: ' . socket_strerror($error));
    }

    private function finishConnect(): bool
    {
        if (hrtime(true) / 1e9 >= $this->connectDeadline) {
            $this->failConnect('Outbound connection timed out');
            return false;
        }

        $read = null;
        $write = [$this->sockSend];
        $except = [$this->sockSend];
        $ready = @socket_select($read, $write, $except, 0);
        if ($ready === false) {
            $error = socket_last_error();
            if ($error !== SOCKET_EINTR) {
                $this->failConnect('socket_select() failed: ' . socket_strerror($error));
            }
            return false;
        }
        if ($ready === 0) {
            return false;
        }

        // A refused connection can also be reported as writable.
        $error = socket_get_option($this->sockSend, SOL_SOCKET, SO_ERROR);
        if ($error !== 0) {
            $this->failConnect('socket_connect() failed: ' .
                socket_strerror($error === false ? socket_last_error($this->sockSend) : $error));
            return false;
        }

        $this->connectDeadline = null;
        $this->sockSendStatus = true;
        echo "OK.\n";
        return true;
    }

    private function failConnect(string $message): void
    {
        $this->connectDeadline = null;
        $this->sockSendStatus = false;
        $this->msgSockStatus = false;
        if ($this->sockSend !== false) {
            socket_close($this->sockSend);
            $this->sockSend = false;
        }
        socket_close($this->msgSock);
        $this->msgSock = false;
        fwrite(STDERR, $message . PHP_EOL);
    }


    public function forward()
    {
       if ($this->connectDeadline !== null && !$this->finishConnect()) {
           return;
       }

       try {
            $clientBuffer = $this->listen($this->msgSock, "msgSock");
            if(!$this->isConnected()) {
                return;
            }
            if($clientBuffer != "") {
                $this->write($this->sockSend, $clientBuffer, "sockSend");
                if(!$this->isConnected()) {
                    return;
                }
            }
            $serverBuffer = $this->listen($this->sockSend, "sockSend");
            if(!$this->isConnected()) {
                return;
            }
            if($serverBuffer != "") {
                $this->write($this->msgSock,$serverBuffer, "msgSock");
                if(!$this->isConnected()) {
                    return;
                }
            }

       } catch(\Throwable $th) {
          echo $th;
          echo socket_strerror(socket_last_error($this->msgSock));
          echo socket_strerror(socket_last_error($this->sockSend));
       }
    }

    private function listen(Socket $sock, $process): string
    {
        // echo " handled by $process\n";
        socket_clear_error($sock);
        $read = socket_read($sock, 20480, PHP_BINARY_READ);
        // echo $read;
        if($read == "") {
            $errorCode = socket_last_error($sock);
            $msg = socket_strerror($errorCode);
            if($errorCode !== SOCKET_EAGAIN) {
                echo "closing $process\n";
                echo "[$errorCode] $msg\n";
                $this->closeSocketsSend();
                $this->closeMsgSock();
            }
        }
        return $read;
    }

    private function write(Socket $sock, $buf, $process): void
    {
        $status = socket_write($sock, $buf, strlen($buf));
        if($status === false) {
            $errorCode = socket_last_error($sock);
            $msg = socket_strerror($errorCode);
            echo "[$errorCode] $msg\n";
            if($msg != "") {
                echo "closing $process\n";
                $this->closeSocketsSend();
                $this->closeMsgSock();
            }
        }
    }


    private function closeSocketsSend()
    {
        $this->sockSendStatus = false;
        socket_shutdown($this->sockSend);
        socket_close($this->sockSend);
    }

    private function closeMsgSock()
    {
        $this->msgSockStatus = false;
        socket_shutdown($this->msgSock);
        socket_close($this->msgSock);
    }
}

