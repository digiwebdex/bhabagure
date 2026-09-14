"""The ZKTeco device, through pyzk, with only the calls the agent is allowed to make.

What the agent never does to the device (docs/phase-7-hr-attendance-bonus-wallet.md §5.2): disable it (staff can punch
during a read), clear its log, change users, or read fingerprint or face templates, passwords or card numbers. The one
write is the clock, and only when an admin asks. `GuardedConnection` enforces that list: any other pyzk call raises.
"""

from dataclasses import dataclass
from datetime import datetime

from .clock import office_text

ALLOWED_CALLS = frozenset({
    "read_sizes",
    "get_serialnumber",
    "get_firmware_version",
    "get_device_name",
    "get_time",
    "get_users",
    "get_attendance",
    "set_time",
    "disconnect",
})
# Filled by read_sizes(); plain attributes, not calls.
ALLOWED_ATTRIBUTES = frozenset({"users", "fingers", "records"})


class DeviceUnavailable(Exception):
    """The device didn't answer: power, cable, address, or the Comm Key."""


class ForbiddenDeviceCall(Exception):
    pass


@dataclass(frozen=True)
class DeviceInfo:
    serial: str
    model: str
    firmware: str
    device_time: str
    users_count: int
    fingers_count: int
    records_count: int


@dataclass(frozen=True)
class Punch:
    user_id: str
    time: str
    verify: int | None
    state: int | None

    def key(self, serial: str) -> str:
        return f"{serial}|{self.user_id}|{self.time}"


class GuardedConnection:
    """Wraps a pyzk connection and refuses everything outside the allowed list."""

    def __init__(self, connection):
        object.__setattr__(self, "_connection", connection)

    def __getattr__(self, name):
        if name not in ALLOWED_CALLS and name not in ALLOWED_ATTRIBUTES:
            raise ForbiddenDeviceCall(f"The agent may not use {name} on the device.")
        return getattr(object.__getattribute__(self, "_connection"), name)

    def __setattr__(self, name, value):
        raise ForbiddenDeviceCall("The agent may not change the device connection.")


class Device:
    """Opened as a context manager; reads what a pull needs."""

    def __init__(self, address: str, port: int, comm_key: int, timeout: int, connector=None):
        self._address = address
        self._port = port
        self._comm_key = comm_key
        self._timeout = timeout
        self._connector = connector or _pyzk_connector
        self._connection: GuardedConnection | None = None

    def __enter__(self) -> "Device":
        try:
            self._connection = GuardedConnection(self._connector(self._address, self._port, self._comm_key, self._timeout))
        except ForbiddenDeviceCall:
            raise
        except Exception as error:  # pyzk raises its own ZKError, socket errors and timeouts
            raise DeviceUnavailable(f"{self._address}:{self._port}: {error}") from error
        return self

    def __exit__(self, *exc) -> None:
        if self._connection is not None:
            try:
                self._connection.disconnect()
            except Exception:
                pass
            self._connection = None

    def info(self) -> DeviceInfo:
        connection = self._require()
        try:
            connection.read_sizes()
            device_time = connection.get_time()
            return DeviceInfo(
                serial=str(connection.get_serialnumber() or "").strip(),
                model=str(connection.get_device_name() or "").strip(),
                firmware=str(connection.get_firmware_version() or "").strip(),
                device_time=office_text(device_time) if isinstance(device_time, datetime) else "",
                users_count=int(getattr(connection, "users", 0) or 0),
                fingers_count=int(getattr(connection, "fingers", 0) or 0),
                records_count=int(getattr(connection, "records", 0) or 0),
            )
        except ForbiddenDeviceCall:
            raise
        except Exception as error:
            raise DeviceUnavailable(f"reading device info: {error}") from error

    def users(self) -> list[dict]:
        """IDs and names only: never passwords, card numbers or templates."""
        connection = self._require()
        try:
            return [{"id": str(user.user_id), "name": str(user.name or "").strip()} for user in connection.get_users() or []]
        except ForbiddenDeviceCall:
            raise
        except Exception as error:
            raise DeviceUnavailable(f"reading users: {error}") from error

    def punches(self) -> list[Punch]:
        connection = self._require()
        try:
            records = connection.get_attendance() or []
        except ForbiddenDeviceCall:
            raise
        except Exception as error:
            raise DeviceUnavailable(f"reading the attendance log: {error}") from error
        punches = []
        for record in records:
            if not isinstance(record.timestamp, datetime):
                continue
            punches.append(Punch(
                user_id=str(record.user_id).strip(),
                time=office_text(record.timestamp),
                verify=_small_int(getattr(record, "status", None)),
                state=_small_int(getattr(record, "punch", None)),
            ))
        return punches

    def set_clock(self, moment: datetime) -> None:
        connection = self._require()
        try:
            connection.set_time(moment)
        except ForbiddenDeviceCall:
            raise
        except Exception as error:
            raise DeviceUnavailable(f"setting the clock: {error}") from error

    def _require(self) -> GuardedConnection:
        if self._connection is None:
            raise DeviceUnavailable("not connected")
        return self._connection


def _small_int(value) -> int | None:
    try:
        number = int(value)
    except (TypeError, ValueError):
        return None
    return number if 0 <= number <= 65535 else None


def _pyzk_connector(address: str, port: int, comm_key: int, timeout: int):
    # Imported here so the rest of the agent (and its tests) run without pyzk installed.
    from zk import ZK

    # ommit_ping: Windows firewalls often drop ICMP; the TCP connect itself proves the device is there.
    return ZK(address, port=port, timeout=timeout, password=comm_key, force_udp=False, ommit_ping=True).connect()
