import os
from contextlib import ExitStack
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import unittest


ROOT = Path(os.environ.get("PROXY_ROOT", Path(__file__).resolve().parents[1]))
LOOPBACK = "127.0.0.1"


def unused_ports(count):
    with ExitStack() as stack:
        sockets = [stack.enter_context(socket.socket()) for _ in range(count)]
        for sock in sockets:
            sock.bind((LOOPBACK, 0))
        return [sock.getsockname()[1] for sock in sockets]


@unittest.skipUnless(sys.platform.startswith("linux"), "requires the Linux TCP backlog behavior")
class OutboundConnectTests(unittest.TestCase):
    def wait_for_log(self, text, count=1):
        deadline = time.monotonic() + 3
        while time.monotonic() < deadline and self.proxy.poll() is None:
            log = self.log_path.read_text(errors="replace")
            if log.count(text) >= count:
                return
            time.sleep(0.01)
        self.fail(f"Did not see {text!r} {count} time(s):\n{self.log_path.read_text(errors='replace')}")

    def start_proxy(self, destination, *, source_ports=(), timeout=5):
        directory = Path(self.stack.enter_context(tempfile.TemporaryDirectory()))
        shutil.copy2(ROOT / "portForward.php", directory)
        shutil.copytree(ROOT / "lib", directory / "lib")
        (port,) = unused_ports(1)
        (directory / "config.php").write_text(
            "<?php return ["
            f"'localAddress' => '{LOOPBACK}', 'remoteAddress' => '{LOOPBACK}', "
            f"'lclPort' => {port}, 'dstPort' => {destination}, "
            f"'socketSendSrcPorts' => {list(source_ports)}, 'connectTimeout' => {timeout}];"
        )
        self.log_path = directory / "proxy.log"
        log = self.stack.enter_context(self.log_path.open("wb"))
        php = [os.environ.get("PHP_BINARY", "php")]
        check = ["-r", "exit(extension_loaded('sockets') ? 0 : 1);"]
        if subprocess.run(php + check, timeout=3).returncode:
            php += ["-d", "extension=sockets"]
            subprocess.run(php + check, check=True, timeout=3)
        self.proxy = subprocess.Popen(php + ["portForward.php"], cwd=directory, stdout=log, stderr=log)
        self.stack.callback(self.stop_proxy)
        self.wait_for_log("Listen on")
        return port

    def stop_proxy(self):
        if self.proxy.poll() is None:
            self.proxy.terminate()
        try:
            self.proxy.wait(timeout=2)
        except subprocess.TimeoutExpired:
            self.proxy.kill()
            self.proxy.wait(timeout=2)

    def setUp(self):
        self.stack = ExitStack()
        self.addCleanup(self.stack.close)
        self.upstream = self.stack.enter_context(socket.socket())
        self.upstream.bind((LOOPBACK, 0))
        self.upstream.settimeout(3)
        self.destination = self.upstream.getsockname()[1]

    def connect(self, port):
        return self.stack.enter_context(socket.create_connection((LOOPBACK, port), timeout=1))

    def accept(self):
        peer, address = self.upstream.accept()
        peer.settimeout(1)
        return self.stack.enter_context(peer), address

    def fill_backlog(self):
        # On Linux listen(1) accepts two queued connections. Do not drain
        # them until after checking that the original session still works.
        self.connect(self.destination)
        self.connect(self.destination)

    def test_established_connection_keeps_forwarding(self):
        self.upstream.listen(1)
        port = self.start_proxy(self.destination)
        client = self.connect(port)
        peer, _ = self.accept()
        self.wait_for_log("OK.")
        self.fill_backlog()
        self.connect(port)
        self.wait_for_log("Attempting to connect", count=2)

        # The blocked connect on the old proxy takes longer than this.
        peer.settimeout(0.5)
        client.sendall(b"x")
        self.assertEqual(peer.recv(1), b"x")
        peer.sendall(b"y")
        self.assertEqual(client.recv(1), b"y")

    def test_timeout_releases_configured_source_port(self):
        (source_port,) = unused_ports(1)
        self.upstream.listen(1)
        self.fill_backlog()
        port = self.start_proxy(self.destination, source_ports=[source_port], timeout=0.25)
        client = self.connect(port)
        self.assertEqual(client.recv(1), b"")
        self.wait_for_log("Outbound connection timed out")
        for _ in range(2):
            peer, _ = self.upstream.accept()
            peer.close()

        self.connect(port)
        _, address = self.accept()
        self.assertEqual(address[1], source_port)

    def test_refused_connection_closes_client_and_releases_source_port(self):
        (source_port,) = unused_ports(1)
        port = self.start_proxy(self.destination, source_ports=[source_port])
        client = self.connect(port)
        self.assertEqual(client.recv(1), b"")
        self.wait_for_log("socket_connect() failed")

        self.upstream.listen(1)
        self.connect(port)
        _, address = self.accept()
        self.assertEqual(address[1], source_port)


if __name__ == "__main__":
    unittest.main()
