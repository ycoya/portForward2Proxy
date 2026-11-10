<?php

final class RelayDirection
{
    private const CHUNK_BYTES = 20 * 1024;
    private const MAX_PENDING_BYTES = 256 * 1024;

    private string $pending = '';
    private bool $readEnded = false;
    private bool $writeEnded = false;

    public function __construct(private Socket $source, private Socket $destination)
    {
    }

    public function pump(): void
    {
        if ($this->writeEnded) {
            return;
        }

        // Stop reading while the destination's queue is full.
        $available = self::MAX_PENDING_BYTES - strlen($this->pending);
        if (!$this->readEnded && $available > 0) {
            $this->read(min(self::CHUNK_BYTES, $available));
        }

        if ($this->pending !== '') {
            $this->write();
        }

        if ($this->readEnded && $this->pending === '') {
            // Forward EOF only after all queued bytes have reached the socket.
            socket_clear_error($this->destination);
            if (@socket_shutdown($this->destination, 1)) {
                $this->writeEnded = true;
            } else {
                self::retryOrFail($this->destination, 'shutdown write');
            }
        }
    }

    public function isFinished(): bool
    {
        return $this->writeEnded;
    }

    private function read(int $length): void
    {
        socket_clear_error($this->source);
        $data = @socket_read($this->source, $length, PHP_BINARY_READ);
        if ($data === false) {
            self::retryOrFail($this->source, 'read');
        } elseif ($data === '') {
            $this->readEnded = true;
        } else {
            $this->pending .= $data;
        }
    }

    private function write(): void
    {
        socket_clear_error($this->destination);
        $written = @socket_write($this->destination, $this->pending, strlen($this->pending));
        if ($written === false) {
            self::retryOrFail($this->destination, 'write');
        } elseif ($written > 0) {
            $this->pending = substr($this->pending, $written);
        }
    }

    private static function retryOrFail(Socket $socket, string $operation): void
    {
        $error = socket_last_error($socket);
        foreach (['SOCKET_EAGAIN', 'SOCKET_EWOULDBLOCK', 'SOCKET_EINTR'] as $name) {
            if (defined($name) && $error === constant($name)) {
                return;
            }
        }
        if (PHP_OS_FAMILY === 'Windows' && $error === 10035) { // WSAEWOULDBLOCK
            return;
        }

        throw new RuntimeException("$operation: " . socket_strerror($error));
    }
}
