"""A fake ZKTeco connection (the shape pyzk returns) and a fake API that behaves like the server: a punch is unique on
device serial, user and time, so replays store nothing."""

from datetime import datetime, timedelta
from types import SimpleNamespace

from bhabaghure_attendance.api import ApiRejected, ApiUnavailable


class FakeConnection:
    def __init__(self, records: int = 0, serial: str = "K40-TEST-0001"):
        self.calls: list[str] = []
        self.serial = serial
        self.users = 0
        self.fingers = 0
        self.records = 0
        start = datetime(2026, 9, 1, 10, 55, 0)
        self._attendance = [
            SimpleNamespace(user_id=str(1 + index % 9), timestamp=start + timedelta(minutes=37 * index), status=1, punch=0, uid=index)
            for index in range(records)
        ]
        self.clock = datetime(2026, 9, 15, 10, 0, 0)

    # Allowed calls.
    def read_sizes(self):
        self.calls.append("read_sizes")
        self.users, self.fingers, self.records = 9, 18, len(self._attendance)

    def get_serialnumber(self):
        self.calls.append("get_serialnumber")
        return self.serial

    def get_firmware_version(self):
        self.calls.append("get_firmware_version")
        return "Ver 6.60 Apr 28 2018"

    def get_device_name(self):
        self.calls.append("get_device_name")
        return "K40/ID"

    def get_time(self):
        self.calls.append("get_time")
        return self.clock

    def get_users(self):
        self.calls.append("get_users")
        return [SimpleNamespace(user_id=str(n), name=f"Staff {n}", password="1234", card=998877, privilege=0, uid=n) for n in range(1, 10)]

    def get_attendance(self):
        self.calls.append("get_attendance")
        return list(self._attendance)

    def set_time(self, moment):
        self.calls.append("set_time")
        self.clock = moment

    def disconnect(self):
        self.calls.append("disconnect")

    # Calls the agent must never make.
    def disable_device(self):
        self.calls.append("disable_device")

    def clear_attendance(self):
        self.calls.append("clear_attendance")

    def get_templates(self):
        self.calls.append("get_templates")

    def add_punch(self, *args):
        self.calls.append("add_punch")


class FakeApi:
    def __init__(self, command: str | None = None, interval_minutes: int = 15):
        self.command = command
        self.interval_minutes = interval_minutes
        self.check_ins = 0
        self.reports: list[dict] = []
        self.batches: list[list[dict]] = []
        self.server_keys: set[str] = set()
        self.fail_check_in: Exception | None = None
        self.fail_report: Exception | None = None
        self.fail_batch_numbers: set[int] = set()
        self.reject_before = "2015-01-01 00:00:00"

    def check_in(self, last_pull):
        if self.fail_check_in:
            raise self.fail_check_in
        self.check_ins += 1
        command, self.command = self.command, None
        return {"pull_interval_minutes": self.interval_minutes, "command": {"type": command} if command else None}

    def report(self, body):
        if self.fail_report:
            raise self.fail_report
        self.reports.append(body)
        return {}

    def punches(self, serial, punches):
        number = len(self.batches) + 1
        self.batches.append(punches)
        if number in self.fail_batch_numbers:
            raise ApiUnavailable("simulated network failure")
        stored, rejected = 0, []
        for index, punch in enumerate(punches):
            if punch["time"] < self.reject_before:
                rejected.append({"index": index, "reason": "too_old"})
                continue
            key = f"{serial}|{punch['user_id']}|{punch['time']}"
            if key not in self.server_keys:
                self.server_keys.add(key)
                stored += 1
        return {"stored": stored, "duplicates": len(punches) - len(rejected) - stored, "rejected": rejected}


__all__ = ["FakeConnection", "FakeApi", "ApiRejected", "ApiUnavailable"]
