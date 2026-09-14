import tempfile
import unittest
from pathlib import Path

from bhabaghure_attendance.api import ApiRejected, ApiUnavailable
from bhabaghure_attendance.device import ALLOWED_CALLS, Device, DeviceUnavailable, ForbiddenDeviceCall, GuardedConnection
from bhabaghure_attendance.runner import BATCH, run_once
from bhabaghure_attendance.store import Store

from .fakes import FakeApi, FakeConnection


class Clock:
    def __init__(self):
        self.now = 1_000_000.0

    def __call__(self):
        return self.now


class RunnerTest(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.store = Store(Path(self.folder.name) / "agent.db")
        self.clock = Clock()

    def tearDown(self):
        self.store.close()
        self.folder.cleanup()

    def device(self, connection: FakeConnection):
        return lambda: Device("192.0.2.10", 4370, 0, 5, connector=lambda *args: connection)

    def run_agent(self, api, connection, **options):
        return run_once(api, self.store, self.device(connection), address="192.0.2.10:4370", now=self.clock, **options)

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

    def test_losing_the_local_store_resends_everything_and_the_server_keeps_no_duplicates(self):
        api, connection = FakeApi(), FakeConnection(records=700)
        self.run_agent(api, connection)
        self.store.forget_acknowledged()

        self.clock.now += 16 * 60
        outcome = self.run_agent(api, connection)

        self.assertEqual((700, 0, 700), (outcome.sent, outcome.stored, outcome.duplicates))
        self.assertEqual(700, len(api.server_keys))

    def test_a_failed_batch_is_not_acknowledged_and_goes_again_next_time(self):
        api, connection = FakeApi(), FakeConnection(records=1100)
        api.fail_batch_numbers = {2}

        outcome = self.run_agent(api, connection)
        self.assertEqual("api_unavailable", outcome.status)
        self.assertEqual(BATCH, self.store.count_acknowledged())

        self.clock.now += 16 * 60
        outcome = self.run_agent(api, connection)
        self.assertEqual("ok", outcome.status)
        self.assertEqual(600, outcome.sent)
        self.assertEqual(1100, len(api.server_keys))
        self.assertEqual(1100, self.store.count_acknowledged())

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

        outcome = run_once(api, self.store, lambda: Device("192.0.2.10", 4370, 0, 5, connector=refuse), now=self.clock)

        self.assertEqual("device_unreachable", outcome.status)
        self.assertEqual("device_unreachable", api.reports[0]["status"])
        self.assertIn("timed out", api.reports[0]["error"])
        self.assertEqual([], api.batches)
        # `agent.exe status` shows it.
        self.assertEqual("device_unreachable", self.store.get("last_pull_status"))

    def test_no_check_in_means_nothing_else_happens(self):
        api, connection = FakeApi(), FakeConnection(records=5)
        api.fail_check_in = ApiUnavailable("offline")

        outcome = self.run_agent(api, connection)

        self.assertEqual("api_unavailable", outcome.status)
        self.assertEqual([], connection.calls)
        self.assertEqual([], api.reports)

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
