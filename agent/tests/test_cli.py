import io
import os
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path
from unittest import mock

from bhabaghure_attendance import __main__ as cli
from bhabaghure_attendance import config
from bhabaghure_attendance.device import Device

from .fakes import FakeApi, FakeConnection

TOKEN = "bhatt_" + "0" * 64


class CommandLineTest(unittest.TestCase):
    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.home = Path(folder.name)
        for patch in (mock.patch.dict(os.environ, {"BHABAGHURE_AGENT_HOME": folder.name}), mock.patch.object(cli, "_logging")):
            patch.start()
            self.addCleanup(patch.stop)

    def test_agent_exe_test_prints_what_it_read_and_where(self):
        config.write(config.Settings("https://api.example.test"))
        api, connection = FakeApi(device_address="192.168.10.116"), FakeConnection(records=4)

        def device(address, port, comm_key, timeout):
            return Device(address, port, comm_key, timeout, connector=lambda *args: connection)

        with mock.patch.object(cli.token_store, "load", return_value=TOKEN), mock.patch.object(cli, "Api", return_value=api), \
                mock.patch.object(cli, "Device", side_effect=device), redirect_stdout(io.StringIO()) as out:
            self.assertEqual(0, cli.main(["test"]))

        printed = out.getvalue()
        for line in ("API check-in: ok", "Device address: 192.168.10.116:4370 (from the Attendance screen)", "Device read: yes",
                     "  Serial:      K40-TEST-0001", "  Model:       K40/ID", "  Firmware:    Ver 6.60 Apr 28 2018",
                     "  Users:       9, with 18 fingerprints", "  Records:     4", "Result: ok"):
            self.assertIn(line + "\n", printed)
        self.assertIn("  Device time: 2026-09-15 10:00:00; this PC: ", printed)
        # The fake device's clock is weeks off.
        self.assertIn("press Set device clock on the Attendance screen", printed)
        self.assertEqual([], api.batches)

    def test_text_a_console_cannot_show_is_printed_escaped_rather_than_stopping_the_agent(self):
        # stdout redirected to a file on Windows: a legacy code page, and strict about what it can't encode.
        strict = io.TextIOWrapper(io.BytesIO(), encoding="cp1252")
        with self.assertRaises(UnicodeEncodeError):
            print("টোকেন", file=strict)

        raw = io.BytesIO()
        redirected = io.TextIOWrapper(raw, encoding="cp1252")
        with mock.patch.object(cli.sys, "stdout", redirected):
            cli._console_safe()
            print("Result: rejected:token_refused - টোকেন")
            redirected.flush()
        self.assertIn(b"Result: rejected:token_refused - \\u099f", raw.getvalue())

    def test_configure_keeps_what_is_not_given_so_a_double_click_update_keeps_the_comm_key(self):
        with redirect_stdout(io.StringIO()):
            self.assertEqual(0, cli.main(["configure", "--api-url", "https://api.example.test/", "--device-address", "192.168.10.116",
                                          "--comm-key", "1234"]))
            # What install.cmd passes on when it is simply double-clicked: nothing.
            self.assertEqual(0, cli.main(["configure"]))

        settings = config.load()
        self.assertEqual(("https://api.example.test", "192.168.10.116", 4370, 1234),
                         (settings.api_url, settings.device_address, settings.device_port, settings.comm_key))

    def test_configure_on_a_new_pc_points_at_the_api_and_leaves_the_address_to_the_attendance_screen(self):
        with redirect_stdout(io.StringIO()) as out:
            self.assertEqual(0, cli.main(["configure"]))

        settings = config.load()
        self.assertEqual((config.DEFAULT_API_URL, None), (settings.api_url, settings.device_address))
        self.assertIn("the agent uses the one set on the Attendance screen", out.getvalue())
        self.assertIn("Comm Key:       none", out.getvalue())

    def test_a_broken_agent_ini_is_not_ready_rather_than_a_crash(self):
        (self.home / "agent.ini").write_bytes(b"\xff\xfe[api]\x00")

        with redirect_stderr(io.StringIO()) as err:
            self.assertEqual(2, cli.main(["run"]))
        self.assertIn("Not ready:", err.getvalue())

    def test_a_hand_run_while_another_run_holds_the_lock_says_so(self):
        config.write(config.Settings("https://api.example.test", "192.168.10.116"))
        lock = cli._Lock(self.home / "agent.lock")
        self.assertTrue(lock.acquire())
        try:
            with redirect_stdout(io.StringIO()) as out:
                self.assertEqual(1, cli.main(["test"]))
                # The scheduled task's run just skips.
                self.assertEqual(0, cli.main(["run"]))
        finally:
            lock.release()
        self.assertIn("Another run of the agent is in progress", out.getvalue())

    def test_status_shows_the_device_address(self):
        config.write(config.Settings("https://api.example.test", "192.168.10.116", 4370))

        with redirect_stdout(io.StringIO()) as out:
            self.assertEqual(0, cli.main(["status"]))
        self.assertIn("Device address:    192.168.10.116:4370\n", out.getvalue())
        self.assertIn("Last good pull:    never\n", out.getvalue())


if __name__ == "__main__":
    unittest.main()
