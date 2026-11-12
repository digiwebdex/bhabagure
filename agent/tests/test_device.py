import importlib.util
import socket
import struct
import unittest

from bhabaghure_attendance import pyzk_fixes
from bhabaghure_attendance.device import BUSY, COMM_KEY, DeviceUnavailable, _pyzk_connector, open_connection

PYZK = importlib.util.find_spec("zk") is not None


class Spinning(BaseException):
    """Not an Exception, so pyzk's own `except Exception` can't swallow it."""


class ClosedSocket:
    """Hands out the given chunks, then b'' — what recv() returns, at once and every time, after the other end has
    closed the connection. A loop that keeps reading anyway is stopped, failing the test, instead of spinning for ever."""

    def __init__(self, *chunks: bytes):
        self.chunks = list(chunks)
        self.empty_reads = 0

    def recv(self, size):
        if self.chunks:
            return self.chunks.pop(0)
        self.empty_reads += 1
        if self.empty_reads > 50:
            raise Spinning("recv() kept being called on a closed connection")
        return b""

    def send(self, data):
        return len(data)

    def settimeout(self, timeout):
        pass

    def close(self):
        pass


class StandInNetworkError(Exception):
    pass


def stand_in_zk():
    """A class shaped like pyzk's ZK, with its loop exactly as zk/base.py has it."""

    class ZK:
        def _ZK__recieve_raw_data(self, size):
            data = []
            while size > 0:
                data_recv = self._ZK__sock.recv(size)
                recieved = len(data_recv)
                data.append(data_recv)
                size -= recieved
            return b''.join(data)

    return ZK


class PyzkFixTest(unittest.TestCase):
    def test_the_fix_ends_the_loop_when_the_device_hangs_up_mid_transfer(self):
        zk_class = stand_in_zk()
        shipped = zk_class()
        shipped._ZK__sock = ClosedSocket(b"abc")
        with self.assertRaises(Spinning):  # pyzk as shipped
            shipped._ZK__recieve_raw_data(10)

        pyzk_fixes.apply(zk_class, StandInNetworkError)
        fixed = zk_class()
        fixed._ZK__sock = ClosedSocket(b"abc")
        with self.assertRaises(StandInNetworkError) as caught:
            fixed._ZK__recieve_raw_data(10)
        self.assertEqual("the device closed the connection", str(caught.exception))
        self.assertEqual(1, fixed._ZK__sock.empty_reads)

    def test_the_fixed_loop_still_joins_a_transfer_that_arrives_in_pieces(self):
        zk_class = stand_in_zk()
        pyzk_fixes.apply(zk_class, StandInNetworkError)
        pyzk_fixes.apply(zk_class, StandInNetworkError)  # once is enough; twice changes nothing
        device = zk_class()
        device._ZK__sock = ClosedSocket(b"ab", b"cd", b"e")

        self.assertEqual(b"abcde", device._ZK__recieve_raw_data(5))
        self.assertEqual(0, device._ZK__sock.empty_reads)


@unittest.skipUnless(PYZK, "pyzk isn't installed here; build.ps1 runs these in .venv-build, where it is")
class PinnedPyzkTest(unittest.TestCase):
    def test_a_device_hanging_up_mid_transfer_fails_the_read_instead_of_spinning(self):
        from zk import ZK, const
        from zk.exception import ZKNetworkError

        pyzk_fixes.apply()
        zk = ZK("192.0.2.10", port=4370, timeout=1, ommit_ping=True)
        zk._ZK__sock.close()  # the UDP socket ZK() opens; the fake takes its place
        # The device announces a 100-byte answer, sends 10 bytes of it, and closes the connection.
        top = struct.pack("<HHI", const.MACHINE_PREPARE_DATA_1, const.MACHINE_PREPARE_DATA_2, 8 + 100)
        header = struct.pack("<4H", const.CMD_DATA, 0, 0, 0)
        zk._ZK__sock = ClosedSocket(top + header + b"x" * 10)
        zk.is_connect = True

        with self.assertRaises(ZKNetworkError) as caught:
            zk.read_with_buffer(const.CMD_ATTLOG_RRQ)
        self.assertEqual("the device closed the connection", str(caught.exception))

    def test_a_device_that_takes_the_connection_but_never_answers_is_reported_busy(self):
        # Something listening that never says a word, like a K40 while ZKTeco's program holds it.
        with socket.create_server(("127.0.0.1", 0)) as silent:
            port = silent.getsockname()[1]
            with self.assertRaises(DeviceUnavailable) as caught:
                _pyzk_connector("127.0.0.1", port, 0, 1)
        self.assertEqual(BUSY, str(caught.exception))


class ConnectionErrorsTest(unittest.TestCase):
    def test_no_network_path_to_the_device_says_what_to_check(self):
        def no_route(*args):
            raise TimeoutError("timed out")

        def pyzk_connect():
            raise AssertionError("pyzk must not be tried without a TCP connection")

        with self.assertRaises(DeviceUnavailable) as caught:
            open_connection(pyzk_connect, "192.168.10.116", 4370, 5, probe=no_route)
        self.assertEqual("no network connection to 192.168.10.116:4370: check the device is on, its cable, its address, and "
                         "that this PC is on the device's network", str(caught.exception))

    def test_a_device_that_accepts_but_never_answers_is_busy_with_another_program(self):
        def pyzk_connect():
            # As pyzk's __send_command does it: the socket timeout, raised again as its own error.
            try:
                raise TimeoutError("timed out")
            except Exception as error:
                raise StandInNetworkError(str(error))

        with self.assertRaises(DeviceUnavailable) as caught:
            open_connection(pyzk_connect, "192.168.10.116", 4370, 5, probe=lambda *args: None)
        self.assertEqual(BUSY, str(caught.exception))
        self.assertIn("another program (such as ZKTeco Attendance Management)", BUSY)

    def test_a_wrong_comm_key_says_where_to_find_the_right_one(self):
        def pyzk_connect():
            raise StandInNetworkError("Unauthenticated")

        with self.assertRaises(DeviceUnavailable) as caught:
            open_connection(pyzk_connect, "192.168.10.116", 4370, 5, probe=lambda *args: None)
        self.assertEqual(COMM_KEY, str(caught.exception))
        self.assertIn("Menu > Comm. > Comm Key", COMM_KEY)

    def test_the_tcp_check_connects_for_real_and_a_closed_port_is_no_network(self):
        with socket.create_server(("127.0.0.1", 0)) as listening:
            port = listening.getsockname()[1]
            self.assertEqual("connected", open_connection(lambda: "connected", "127.0.0.1", port, 2))

        # The same port, closed now.
        with self.assertRaises(DeviceUnavailable) as caught:
            open_connection(lambda: "connected", "127.0.0.1", port, 0.5)
        self.assertTrue(str(caught.exception).startswith(f"no network connection to 127.0.0.1:{port}: "))


if __name__ == "__main__":
    unittest.main()
