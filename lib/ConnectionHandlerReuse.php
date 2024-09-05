<?php



class ConnectionHandlerReuse
{
    protected Socket|bool $msgSock;
    protected Socket|bool $sockSend;
    // protected bool $cxnStatus = true;
    protected bool $sockSendStatus = false;
    protected bool $msgSockStatus = false;

    public function __construct(protected Socket $sockListen, protected string $remoteAddress, protected string $dstPort)
    {
      $this->startAcceptCnx();
    }

    public function isConnected()
    {
        return $this->sockSendStatus && $this->msgSockStatus;
    }

    public function isMsgSockStatusConnected() : bool
    {
        return $this->msgSockStatus;
    }

    public function isSockSendStatusConnected()
    {
        return $this->sockSendStatus;
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
    //  $this->sockSend = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        socket_get_option($this->sockSend, SOL_SOCKET, SO_REUSEADDR);
        // socket_get_option($sockSend, SOL_SOCKET, 15);
        socket_get_option($this->sockSend, SOL_SOCKET, SO_KEEPALIVE);
        if ($this->sockSend == false) {
            echo "socket_create() sock falló: razón: " . socket_strerror(socket_last_error()) . "\n";
        }

        // if (socket_bind($this->sockSend, $localAddress, $localPort) === false) {
        //     echo "socket_bind() falló: razón: " . socket_strerror(socket_last_error($this->sockSend)) . "\n";
        // }
        socket_set_block($this->sockSend);
        echo "Attempting to connect to '$this->remoteAddress' on port '$this->dstPort'...";
        $result = socket_connect($this->sockSend, $this->remoteAddress, $this->dstPort);
        if ($result === false) {
            echo "socket_connect() failed.\nReason: ($result) " . socket_strerror(socket_last_error($this->sockSend)) . "\n";
        } else {
            $this->sockSendStatus = true;
            echo "OK.\n";
        }
        socket_set_nonblock($this->sockSend);

    }




    public function forward()
    {
       try {
            $clientBuffer = $this->listen($this->msgSock, "msgSock");
            if(!$this->isMsgSockStatusConnected()) {
                return;
            }
            if($clientBuffer != "") {
                $this->write($this->sockSend, $clientBuffer, "sockSend");
                if(!$this->isSockSendStatusConnected()) {
                    return;
                }
            }
            $serverBuffer = $this->listen($this->sockSend, "sockSend");
            if(!$this->isSockSendStatusConnected()) {
                return;
            }
            if($serverBuffer != "") {
                $this->write($this->msgSock,$serverBuffer, "msgSock");
                if(!$this->isMsgSockStatusConnected()) {
                    return;
                }
            }
       } catch(\Throwable $th) {
          echo $th;
          echo socket_strerror(socket_last_error($this->msgSock));
          echo socket_strerror(socket_last_error($this->sockSend));
          echo "Thrwable";
       }
    }

    private function listen(Socket $sock, $process): string
    {
        // echo " handled by $process\n";
        socket_clear_error($sock);
        $read = socket_read($sock, 20480, PHP_BINARY_READ);
        // echo $read;
        if($read == "") {
            $msg = socket_strerror(socket_last_error($sock));
            // if($msg != "" && $this->checkMsg($msg, ["socket is not connected", "aborted"])) {
                if($msg != "" && !$this->checkMsg($msg, ["non-blocking", "desbloqueo"])) {
                echo " handled by $process\n";
                echo "$msg\n";
                if($process == 'sockSend') {
                    $this->sockSendStatus = false;
                    socket_shutdown($this->sockSend);
                    socket_close($this->sockSend);
                    // unset($this->sockSend);
                    // $this->sockSend = null;
                    $this->startConx2Server();
                } else {
                    $this->msgSockStatus = false;
                    socket_shutdown($this->msgSock);
                    socket_close($this->msgSock);
                    // unset($this->msgSock);
                    // $this->msgSock = null;
                }
            }
        }
        return $read;
    }

    private function write(Socket $sock, $buf, $process): void
    {
        $status = socket_write($sock, $buf, strlen($buf));
        if($status === false) {
            $msg = socket_strerror(socket_last_error($sock));
        // if($msg != "" && $this->checkMsg($msg, ["socket is not connected", "aborted"])) {
            if($msg != "") {
                echo " handled write by $process\n";
                echo "$msg\n";
                if($process == 'sockSend') {
                    $this->sockSendStatus = false;
                    socket_shutdown($this->sockSend);
                    socket_close($this->sockSend);
                    // unset($this->sockSend);
                    // $this->sockSend = null;
                    $this->startConx2Server();
                } else {
                    $this->msgSockStatus = false;
                    socket_shutdown($this->msgSock);
                    socket_close($this->msgSock);
                    // unset($this->msgSock);
                    // $this->msgSock = null;
                }
            }
        }
    }

    private function checkMsg($msg, $errorsMsgs)
    {
        foreach($errorsMsgs as $error) {
            if(str_contains($msg, $error)) {
                return true;
            }
        }
        return false;
    }
}

