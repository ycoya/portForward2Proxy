import hashlib
import os
from concurrent.futures import ThreadPoolExecutor
from contextlib import contextmanager
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
from threading import Event
import time
import unittest


ROOT = Path(os.environ.get("PROXY_ROOT", Path(__file__).resolve().parents[1]))
LOOPBACK = "127.0.0.1"
TIMEOUT = 8


def php_command():
    command = [os.environ.get("PHP_BINARY", "php")]
    check = ["-r", "exit(extension_loaded('sockets') ? 0 : 1);"]
    if subprocess.run(command + check, timeout=TIMEOUT).returncode != 0:
        command += ["-d", "extension=sockets"]
        subprocess.run(command + check, check=True, timeout=TIMEOUT)
    return command


def unused_port():
    with socket.socket() as reservation:
        reservation.bind((LOOPBACK, 0))
        return reservation.getsockname()[1]


@contextmanager
def running_proxy(php, *, source_port=None, slow_receiver=False):
    with socket.socket() as upstream, tempfile.TemporaryDirectory() as temp:
        if slow_receiver:
            upstream.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, 64 * 1024)
        upstream.bind((LOOPBACK, 0))
        upstream.listen()
        upstream.settimeout(TIMEOUT)
        port = unused_port()
        directory = Path(temp)
        shutil.copy2(ROOT / "portForward.php", directory)
        shutil.copytree(ROOT / "lib", directory / "lib")
        ports = "" if source_port is None else str(source_port)
        (directory / "config.php").write_text(
            "<?php\nreturn ["
            f"'localAddress' => '{LOOPBACK}', 'remoteAddress' => '{LOOPBACK}', "
            f"'lclPort' => {port}, 'dstPort' => {upstream.getsockname()[1]}, "
            f"'socketSendSrcPorts' => [{ports}]];\n",
            encoding="utf-8",
        )

        with tempfile.TemporaryFile() as log:
            proxy = subprocess.Popen(
                php + ["portForward.php"], cwd=directory, stdout=log, stderr=log
            )
            try:
                yield port, proxy, upstream
            except Exception:
                log.seek(0, os.SEEK_END)
                log.seek(max(0, log.tell() - 2000))
                sys.stderr.write(log.read().decode("utf-8", errors="replace"))
                raise
            finally:
                proxy.terminate()
                try:
                    proxy.wait(timeout=2)
                except subprocess.TimeoutExpired:
                    proxy.kill()
                    proxy.wait(timeout=2)


def connect(port, proxy):
    deadline = time.monotonic() + TIMEOUT
    while proxy.poll() is None and time.monotonic() < deadline:
        try:
            return socket.create_connection((LOOPBACK, port), timeout=TIMEOUT)
        except ConnectionRefusedError:
            time.sleep(0.02)
    raise RuntimeError("Proxy did not start listening")


def accept(upstream):
    server, peer = upstream.accept()
    server.settimeout(TIMEOUT)
    return server, peer


def read_exact(sock, size):
    data = bytearray()
    while len(data) < size:
        chunk = sock.recv(size - len(data))
        if not chunk:
            break
        data.extend(chunk)
    return bytes(data)


def chunks_until_eof(sock):
    deadline = time.monotonic() + TIMEOUT
    while True:
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise TimeoutError("Timed out waiting for end of stream")
        sock.settimeout(remaining)
        data = sock.recv(65536)
        if not data:
            return
        yield data


class PortForwardTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.php = php_command()

    def test_client_can_finish_sending_and_still_receive_a_response(self):
        with running_proxy(self.php) as (port, proxy, upstream):
            with connect(port, proxy) as client, accept(upstream)[0] as server:
                client.sendall(b"request")
                client.shutdown(socket.SHUT_WR)
                self.assertEqual(b"".join(chunks_until_eof(server)), b"request")
                server.sendall(b"response after EOF")
                server.shutdown(socket.SHUT_WR)
                self.assertEqual(b"".join(chunks_until_eof(client)), b"response after EOF")

    def test_server_can_finish_sending_and_still_receive_data(self):
        with running_proxy(self.php) as (port, proxy, upstream):
            with connect(port, proxy) as client, accept(upstream)[0] as server:
                server.sendall(b"ready")
                server.shutdown(socket.SHUT_WR)
                self.assertEqual(b"".join(chunks_until_eof(client)), b"ready")
                client.sendall(b"upload after EOF")
                client.shutdown(socket.SHUT_WR)
                self.assertEqual(b"".join(chunks_until_eof(server)), b"upload after EOF")

    def test_slow_receiver_gets_all_bytes_while_another_client_can_transfer(self):
        payload = hashlib.shake_256(b"port-forward backpressure").digest(8 * 1024 * 1024)
        with running_proxy(self.php, slow_receiver=True) as (port, proxy, upstream):
            with connect(port, proxy) as client, accept(upstream)[0] as server:
                sending = Event()

                def upload():
                    sending.set()
                    client.sendall(payload)
                    client.shutdown(socket.SHUT_WR)

                with ThreadPoolExecutor(max_workers=1) as executor:
                    sent = executor.submit(upload)
                    self.assertTrue(sending.wait(TIMEOUT))
                    # Leave the first socket unread while traffic reaches its buffers.
                    time.sleep(0.2)
                    with connect(port, proxy) as other, accept(upstream)[0] as peer:
                        other.sendall(b"\x00ping")
                        self.assertEqual(read_exact(peer, 5), b"\x00ping")
                        peer.sendall(b"pong")
                        self.assertEqual(read_exact(other, 4), b"pong")

                    received = 0
                    digest = hashlib.sha256()
                    for data in chunks_until_eof(server):
                        received += len(data)
                        digest.update(data)
                    self.assertEqual(received, len(payload))
                    self.assertEqual(digest.digest(), hashlib.sha256(payload).digest())
                    sent.result(timeout=TIMEOUT)

    def test_uses_configured_outbound_source_port(self):
        source_port = unused_port()
        with running_proxy(self.php, source_port=source_port) as (port, proxy, upstream):
            with connect(port, proxy) as client:
                server, peer = accept(upstream)
                with server:
                    self.assertEqual(peer[1], source_port)
                    client.sendall(b"x")
                    self.assertEqual(read_exact(server, 1), b"x")


if __name__ == "__main__":
    unittest.main(verbosity=2)
