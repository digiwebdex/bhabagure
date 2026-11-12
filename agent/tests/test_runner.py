import os
import tempfile
import threading
import time
import unittest
from pathlib import Path
from unittest import mock

from bhabaghure_attendance import config
from bhabaghure_attendance.api import ApiBlocked, ApiRejected, ApiUnavailable
from bhabaghure_attendance.config import Settings
from bhabaghure_attendance.device import ALLOWED_CALLS, Device, DeviceUnavailable, ForbiddenDeviceCall, GuardedConnection
from bhabaghure_attendance.runner import BATCH, NO_ADDRESS, run_once
from bhabaghure_attendance.store import Store

from .fakes import FakeApi, FakeConnection


class Clock:
    def __init__(self):
        self.now = 1_000_000.0

    def __call__(self):
        return self.now


class AgentCase(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.store = Store(Path(self.folder.name) / "agent.db")
        self.clock = Clock()
        # agent.ini as install.cmd -DeviceAddress 192.0.2.10 leaves it.
        self.settings = Settings("https://api.example.test", "192.0.2.10", 4370, 0, 5)
        self.saved: list[Settings] = []
        self.opened: list[str] = []

    def tearDown(self):
        self.store.close()
        self.folder.cleanup()

    def device(self, connector):
        def open_device(settings):
            self.opened.append(f"{settings.device_address}:{settings.device_port}")
            return Device(settings.device_address, settings.device_port, settings.comm_key, settings.device_timeout, connector=connector)
        return open_device

    def run_agent(self, api, connection, settings=None, **options):
        options.setdefault("save_settings", self.saved.append)
        return run_once(api, self.store, settings or self.settings, self.device(lambda *args: connection), now=self.clock, **options)


class RunnerTest(AgentCase):
    def test_a_pull_reports_the_device_then_sends_unacknowledged_punches_in_batches(self):
        api, connection = FakeApi(), FakeConnection(records=1203)

        outcome = self.run_agent(api, connection)

        self.assertEqual("ok", outcome.status)
        self.assertEqual(1, len(api.reports))
        report = api.reports[0]
        self.assertEqual("K40-TEST-0001", report["serial"])
        self.assertEqual(1203, report["records_count"])
        self.assertEqual("192.0.2.10:4370", report["address"])
        # IDs and names only: nothing else about a user leaves the device.
        self.assertEqual({"id", "name"}, set(report["users"][0]))
        self.assertEqual([BATCH, BATCH, 203], [len(batch) for batch in api.batches])
        self.assertEqual((1203, 1203, 0), (outcome.sent, outcome.stored, outcome.duplicates))
        self.assertEqual(1203, self.store.count_acknowledged())

    def test_between_pulls_only_the_check_in_happens_and_the_next_pull_sends_only_new_punches(self):
        api, connection = FakeApi(), FakeConnection(records=40)
        self.run_agent(api, connection)

        self.clock.now += 60
        outcome = self.run_agent(api, connection)
        self.assertFalse(outcome.pulled)
        self.assertEqual(2, api.check_ins)
        self.assertEqual(1, len(api.reports))

        self.clock.now += 15 * 60
        connection._attendance.extend(FakeConnection(records=45)._attendance[40:])
        outcome = self.run_agent(api, connection)
        self.assertTrue(outcome.pulled)
        self.assertEqual(5, outcome.sent)
        self.assertEqual(5, outcome.stored)

    def test_a_pc_clock_put_back_doesnt_stop_the_pulls_until_it_catches_up(self):
        api, connection = FakeApi(), FakeConnection(records=3)
        self.assertTrue(self.run_agent(api, connection).pulled)

        # Someone puts the clock back two hours: the next run still pulls instead of waiting two hours and a quarter.
        self.clock.now -= 2 * 3600
        self.assertTrue(self.run_agent(api, connection).pulled)
        self.clock.now += 60
        self.assertFalse(self.run_agent(api, connection).pulled)

    def test_losing_the_local_store_resends_everything_and_the_server_keeps_no_duplicates(self):
        api, connection = FakeApi(), FakeConnection(records=700)
        self.run_agent(api, connection)
        self.store.forget_acknowledged()

        self.clock.now += 16 * 60
        outcome = self.run_agent(api, connection)

        self.assertEqual((700, 0, 700), (outcome.sent, outcome.stored, outcome.duplicates))
        self.assertEqual(700, len(api.server_keys))

    def test_a_failed_batch_is_not_acknowledged_and_goes_again_the_next_minute(self):
        api, connection = FakeApi(), FakeConnection(records=1100)
        api.fail_batch_numbers = {2}

        outcome = self.run_agent(api, connection)
        self.assertEqual("api_unavailable", outcome.status)
        self.assertEqual(BATCH, self.store.count_acknowledged())

        # Not a good pull, so the next run is due at once.
        self.clock.now += 60
        outcome = self.run_agent(api, connection)
        self.assertEqual("ok", outcome.status)
        self.assertEqual(600, outcome.sent)
        self.assertEqual(1100, len(api.server_keys))
        self.assertEqual(1100, self.store.count_acknowledged())

    def test_a_failed_read_is_tried_again_the_next_minute_and_then_waits_for_the_interval(self):
        api, connection = FakeApi(), FakeConnection(records=5)
        down = [True]

        def connector(*args):
            if down[0]:
                raise OSError("timed out")
            return connection

        outcome = run_once(api, self.store, self.settings, self.device(connector), save_settings=self.saved.append, now=self.clock)
        self.assertEqual("device_unreachable", outcome.status)
        self.assertIsNone(self.store.get("last_pull_ok_at"))

        down[0] = False
        self.clock.now += 60
        outcome = run_once(api, self.store, self.settings, self.device(connector), save_settings=self.saved.append, now=self.clock)
        self.assertTrue(outcome.pulled)
        self.assertEqual(("ok", 5), (outcome.status, outcome.sent))

        # 15 minutes count from that good pull.
        self.clock.now += 14 * 60
        outcome = run_once(api, self.store, self.settings, self.device(connector), save_settings=self.saved.append, now=self.clock)
        self.assertFalse(outcome.pulled)

    def test_a_device_that_stays_down_is_tried_less_and_less_often_until_once_an_interval(self):
        api, connection = FakeApi(), FakeConnection(records=3)
        down = [True]

        def connector(*args):
            if down[0]:
                raise OSError("timed out")
            return connection

        def minute(seconds=60):
            self.clock.now += seconds
            return run_once(api, self.store, self.settings, self.device(connector), save_settings=self.saved.append, now=self.clock).pulled

        self.assertEqual("device_unreachable", run_once(api, self.store, self.settings, self.device(connector), save_settings=self.saved.append, now=self.clock).status)
        # Every run starts once a minute, give or take a few seconds: one a little early still counts.
        tries = [minute(55 if run == 0 else 60) for run in range(60)]
        # Each try waits 1, 2, 4 and 8 minutes after the one before, then 15: 8 reports in the first hour, not 60.
        self.assertEqual([0, 2, 6, 14, 29, 44, 59], [run for run, tried in enumerate(tries) if tried])
        self.assertEqual(8, len(api.reports))

        # Sync now doesn't wait for the next try, and a good pull resets the count.
        down[0] = False
        api.command = "pull"
        self.assertTrue(minute())
        self.assertEqual(("ok", "0"), (self.store.get("last_pull_status"), self.store.get("failed_pulls")))
        api.command = None
        self.assertFalse(minute(14 * 60))
        self.assertTrue(minute())

    def test_refused_punches_are_acknowledged_so_they_are_never_retried(self):
        api, connection = FakeApi(), FakeConnection(records=10)
        api.reject_before = "2026-09-01 12:00:00"

        outcome = self.run_agent(api, connection)
        self.assertGreater(outcome.rejected, 0)
        self.assertEqual(10, self.store.count_acknowledged())

        self.clock.now += 16 * 60
        self.assertEqual(0, self.run_agent(api, connection).sent)

    def test_an_unreachable_device_is_reported_and_nothing_is_sent(self):
        api = FakeApi()

        def refuse(*args):
            raise OSError("timed out")

        outcome = run_once(api, self.store, self.settings, self.device(refuse), save_settings=self.saved.append, now=self.clock)

        self.assertEqual("device_unreachable", outcome.status)
        self.assertEqual("device_unreachable", api.reports[0]["status"])
        self.assertIn("timed out", api.reports[0]["error"])
        self.assertEqual([], api.batches)
        # `agent.exe status` shows it.
        self.assertEqual("device_unreachable", self.store.get("last_pull_status"))

    def test_a_device_read_that_never_ends_fails_at_the_deadline(self):
        api, connection = FakeApi(), FakeConnection(records=3)
        connection.hang = threading.Event()
        started = time.monotonic()
        try:
            outcome = self.run_agent(api, connection, read_seconds=0.3)
        finally:
            connection.hang.set()

        self.assertLess(time.monotonic() - started, 5)
        self.assertEqual("device_unreachable", outcome.status)
        self.assertIn("didn't finish within 0.3 seconds", api.reports[0]["error"])
        self.assertEqual([], api.batches)
        self.assertEqual(0, self.store.count_acknowledged())

    def test_no_check_in_means_nothing_else_happens(self):
        api, connection = FakeApi(), FakeConnection(records=5)
        api.fail_check_in = ApiUnavailable("offline")

        outcome = self.run_agent(api, connection)

        self.assertEqual("api_unavailable", outcome.status)
        self.assertEqual([], connection.calls)
        self.assertEqual([], api.reports)

    def test_a_block_in_front_of_the_api_is_told_apart_from_an_outage_and_a_refusal(self):
        api, connection = FakeApi(), FakeConnection(records=5)
        api.fail_check_in = ApiBlocked("HTTP 403 from check-in, and not from the API")

        self.assertEqual("api_blocked", self.run_agent(api, connection).status)

        api.fail_check_in = ApiRejected(401, "token_refused", "This device token isn't valid.")
        self.assertEqual("rejected:token_refused", self.run_agent(api, connection).status)
        self.assertEqual([], connection.calls)

    def test_a_replaced_device_stops_before_any_punch_is_sent(self):
        api, connection = FakeApi(), FakeConnection(records=5)
        api.fail_report = ApiRejected(409, "device_changed", "This token belongs to another device.")

        outcome = self.run_agent(api, connection)

        self.assertEqual("rejected:device_changed", outcome.status)
        self.assertEqual([], api.batches)

    def test_test_command_reads_the_device_without_sending_punches(self):
        api, connection = FakeApi(command="test"), FakeConnection(records=30)

        outcome = self.run_agent(api, connection)

        self.assertEqual({"type": "test", "result": "ok"}, api.reports[0]["command"])
        self.assertNotIn("get_attendance", connection.calls)
        self.assertEqual([], api.batches)
        self.assertEqual("ok", outcome.status)
        # Not a pull: the punches are still due, so the next minute's run sends them.
        self.assertIsNone(self.store.get("last_pull_ok_at"))
        self.clock.now += 60
        self.assertEqual(30, self.run_agent(api, connection).sent)

    def test_a_hand_run_test_leaves_sync_now_pending_for_the_scheduled_run(self):
        api, connection = FakeApi(command="pull"), FakeConnection(records=30)

        outcome = self.run_agent(api, connection, test_only=True)

        self.assertEqual("ok", outcome.status)
        self.assertIsNone(api.reports[0]["command"])
        self.assertEqual("pull", api.command)
        self.assertIn("Sync now was left pending for the scheduled run.", outcome.notes)
        self.assertNotIn("get_attendance", connection.calls)
        self.assertEqual([], api.batches)
        # Neither an attempt nor a good pull is recorded.
        self.assertIsNone(self.store.get("last_pull_attempt_at"))
        self.assertIsNone(self.store.get("last_pull_ok_at"))
        self.assertIsNone(self.store.get("last_pull_status"))

        # The server hands the command out again; the scheduled run does it.
        outcome = self.run_agent(api, connection)
        self.assertEqual({"type": "pull", "result": "ok"}, api.reports[-1]["command"])
        self.assertEqual(30, outcome.sent)
        self.assertIsNone(api.command)

    def test_a_hand_run_test_leaves_set_device_clock_pending_for_the_scheduled_run(self):
        api, connection = FakeApi(command="set_clock"), FakeConnection(records=3)

        self.run_agent(api, connection, test_only=True)

        self.assertNotIn("set_time", connection.calls)
        self.assertIsNone(api.reports[0]["command"])
        self.assertEqual("set_clock", api.command)

        self.run_agent(api, connection)
        self.assertEqual(1, connection.calls.count("set_time"))
        self.assertEqual("ok", api.reports[-1]["command"]["result"])
        self.assertIsNone(api.command)

    def test_a_hand_run_test_answers_test_link(self):
        api, connection = FakeApi(command="test"), FakeConnection(records=3)

        self.run_agent(api, connection, test_only=True)

        self.assertEqual({"type": "test", "result": "ok"}, api.reports[0]["command"])
        self.assertIsNone(api.command)

    def test_a_hand_run_pull_sends_punches_even_with_test_link_pending(self):
        api, connection = FakeApi(command="test"), FakeConnection(records=30)

        outcome = self.run_agent(api, connection, force_pull=True)

        self.assertEqual(("ok", 30), (outcome.status, outcome.sent))
        self.assertEqual({"type": "test", "result": "ok"}, api.reports[0]["command"])
        self.assertIsNotNone(self.store.get("last_pull_ok_at"))

    def test_set_clock_is_the_only_write_and_happens_only_when_asked(self):
        api, connection = FakeApi(), FakeConnection(records=3)
        self.run_agent(api, connection)
        self.assertNotIn("set_time", connection.calls)

        api.command = "set_clock"
        self.clock.now += 60
        self.run_agent(api, connection)
        self.assertEqual(1, connection.calls.count("set_time"))
        self.assertEqual("ok", api.reports[-1]["command"]["result"])

    def test_a_full_pull_uses_only_the_allowed_device_calls(self):
        api, connection = FakeApi(command="set_clock"), FakeConnection(records=12)
        self.run_agent(api, connection)
        self.clock.now += 16 * 60
        self.run_agent(api, connection)

        self.assertTrue(set(connection.calls) <= ALLOWED_CALLS, set(connection.calls) - ALLOWED_CALLS)
        for forbidden in ("disable_device", "clear_attendance", "get_templates", "add_punch"):
            self.assertNotIn(forbidden, connection.calls)


class DeviceAddressTest(AgentCase):
    """The address set on the Attendance screen reaches the agent with every check-in."""

    def test_a_new_address_from_the_attendance_screen_is_written_to_agent_ini_and_used_at_once(self):
        with mock.patch.dict(os.environ, {"BHABAGHURE_AGENT_HOME": self.folder.name}):
            config.write(self.settings)
            api, connection = FakeApi(device_address="192.168.10.116", device_port=4371), FakeConnection(records=3)
            # A good pull a minute ago: nothing is due but the new address.
            self.store.set("last_pull_ok_at", self.clock.now - 60)

            outcome = run_once(api, self.store, config.load(), self.device(lambda *args: connection), now=self.clock)

            self.assertTrue(outcome.pulled)
            self.assertEqual(["192.168.10.116:4371"], self.opened)
            self.assertEqual("192.168.10.116:4371", api.reports[0]["address"])
            self.assertEqual("the Attendance screen", outcome.address_from)
            written = config.load()
            self.assertEqual(("192.168.10.116", 4371, 5), (written.device_address, written.device_port, written.device_timeout))
            ini = Path(self.folder.name) / "agent.ini"
            self.assertFalse(ini.read_bytes().startswith(b"\xef\xbb\xbf"))
            self.assertFalse(ini.with_name("agent.ini.new").exists())

            # The same address next time: agent.ini is left alone, and no extra pull happens.
            before = ini.stat().st_mtime_ns
            self.clock.now += 60
            outcome = run_once(api, self.store, config.load(), self.device(lambda *args: connection), now=self.clock)
            self.assertFalse(outcome.pulled)
            self.assertEqual(before, ini.stat().st_mtime_ns)

    def test_with_no_address_from_the_server_agent_ini_is_used(self):
        api, connection = FakeApi(device_address=None), FakeConnection(records=3)

        outcome = self.run_agent(api, connection)

        self.assertEqual("ok", outcome.status)
        self.assertEqual(["192.0.2.10:4370"], self.opened)
        self.assertEqual("agent.ini", outcome.address_from)
        self.assertEqual([], self.saved)

    def test_a_hand_run_test_doesnt_use_up_a_new_address_the_next_scheduled_run_pulls_at_it(self):
        api, connection = FakeApi(device_address="192.168.10.116"), FakeConnection(records=3)
        self.store.set("last_pull_ok_at", self.clock.now - 60)

        test = self.run_agent(api, connection, test_only=True)
        self.assertEqual("ok", test.status)
        self.assertEqual([], api.batches)

        # A minute later nothing else is due, but the new address hasn't been pulled from yet.
        self.clock.now += 60
        outcome = self.run_agent(api, connection)
        self.assertTrue(outcome.pulled)
        self.assertEqual("192.168.10.116:4370", outcome.address)
        self.assertEqual(3, sum(len(batch) for batch in api.batches))

        # And then it waits for the interval again.
        self.clock.now += 60
        self.assertFalse(self.run_agent(api, connection).pulled)

    def test_an_agent_ini_that_cant_be_written_doesnt_turn_every_run_into_a_pull(self):
        api, connection = FakeApi(device_address="192.168.10.116"), FakeConnection(records=3)

        def refuse(settings):
            raise OSError("access denied")

        self.assertTrue(self.run_agent(api, connection, save_settings=refuse).pulled)
        for _ in range(3):
            self.clock.now += 60
            outcome = self.run_agent(api, connection, save_settings=refuse)
            self.assertFalse(outcome.pulled)
            self.assertEqual("the Attendance screen", outcome.address_from)

    def test_with_no_address_anywhere_the_device_is_left_alone_and_the_card_is_told(self):
        api, connection = FakeApi(command="pull"), FakeConnection(records=3)

        outcome = self.run_agent(api, connection, settings=Settings("https://api.example.test"))

        self.assertEqual(("no_address", NO_ADDRESS), (outcome.status, outcome.error))
        self.assertEqual([], self.opened)
        self.assertEqual([], connection.calls)
        report = api.reports[0]
        self.assertEqual(("no_address", None, NO_ADDRESS), (report["status"], report["address"], report["error"]))
        self.assertEqual({"type": "pull", "result": "failed", "detail": NO_ADDRESS}, report["command"])
        self.assertEqual("no_address", self.store.get("last_pull_status"))
        self.assertIsNone(self.store.get("last_pull_ok_at"))

    def test_without_an_address_the_card_is_reminded_once_an_interval_and_a_new_address_is_tried_at_once(self):
        api, connection = FakeApi(), FakeConnection(records=2)
        nowhere = Settings("https://api.example.test")

        self.assertEqual("no_address", self.run_agent(api, connection, settings=nowhere).status)
        self.clock.now += 60
        self.assertFalse(self.run_agent(api, connection, settings=nowhere).pulled)
        self.assertEqual(1, len(api.reports))

        self.clock.now += 15 * 60
        self.assertEqual("no_address", self.run_agent(api, connection, settings=nowhere).status)
        self.assertEqual(2, len(api.reports))

        # The admin sets the address: the next minute's run reads the device.
        api.device_address = "192.168.10.116"
        self.clock.now += 60
        outcome = self.run_agent(api, connection, settings=nowhere)
        self.assertEqual(("ok", 2), (outcome.status, outcome.sent))
        self.assertEqual(["192.168.10.116:4370"], self.opened)

    def test_an_address_that_is_not_private_ipv4_is_ignored(self):
        for sent in ("8.8.8.8", "127.0.0.1", "192.168.1.300", "office-k40", "fe80::1"):
            with self.subTest(sent=sent):
                api, connection = FakeApi(device_address=sent), FakeConnection(records=1)
                self.run_agent(api, connection, force_pull=True)
                self.assertEqual("192.0.2.10:4370", self.opened[-1])
        self.assertEqual([], self.saved)

    def test_a_bad_port_from_the_server_is_ignored(self):
        api, connection = FakeApi(device_address="10.0.0.5", device_port=70000), FakeConnection(records=1)

        self.run_agent(api, connection)

        self.assertEqual(["192.0.2.10:4370"], self.opened)

    def test_an_address_that_cannot_be_saved_is_still_used(self):
        api, connection = FakeApi(device_address="172.16.4.20"), FakeConnection(records=1)

        def read_only(settings):
            raise PermissionError("agent.ini is read-only")

        outcome = self.run_agent(api, connection, save_settings=read_only)

        self.assertEqual("ok", outcome.status)
        self.assertEqual(["172.16.4.20:4370"], self.opened)


class GuardedConnectionTest(unittest.TestCase):
    def test_everything_outside_the_allowed_list_is_refused(self):
        guarded = GuardedConnection(FakeConnection())
        for forbidden in ("disable_device", "clear_attendance", "get_templates", "add_punch", "delete_user", "restart"):
            with self.assertRaises(ForbiddenDeviceCall):
                getattr(guarded, forbidden)
        with self.assertRaises(ForbiddenDeviceCall):
            guarded.users = 3
        guarded.read_sizes()
        self.assertEqual(9, guarded.users)

    def test_an_unreachable_device_raises_device_unavailable(self):
        def refuse(*args):
            raise ConnectionRefusedError("refused")

        with self.assertRaises(DeviceUnavailable):
            with Device("192.0.2.10", 4370, 0, 5, connector=refuse):
                pass


if __name__ == "__main__":
    unittest.main()
