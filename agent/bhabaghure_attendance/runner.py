"""One run of the agent (docs/phase-7-hr-attendance-bonus-wallet.md §5.2).

Every minute: check in. The reply may carry a command from an admin, and it carries the device's address as set on the
Attendance screen. When 15 minutes have passed since the last good pull, or a command asks for one, or the address has
just changed: read the device, report what it said (or that it didn't answer), then send the punches the server hasn't
acknowledged, 500 per request. A punch is marked acknowledged only after the server's 2xx. A failed pull is tried again
after 1, 2, 4 and 8 minutes, then every 15: the records are still on the device, and the server ignores what it already
has.
"""

import dataclasses
import ipaddress
import logging
import time
from dataclasses import dataclass, field
from typing import Callable

from . import config
from .api import Api, ApiBlocked, ApiRejected, ApiUnavailable
from .clock import office_now, office_text
from .config import Settings
from .device import Device, DeviceInfo, DeviceUnavailable, Punch, with_deadline
from .store import Store

BATCH = 500
# A whole device read (connect, info, users, log, disconnect) gets this long; then it counts as device_unreachable.
DEVICE_READ_SECONDS = 180
# How much earlier than due a run may start and still pull: Task Scheduler's minute isn't exact.
RUN_SLACK_SECONDS = 10
# The Attendance screen accepts only these for the device, and the agent holds the check-in to the same.
PRIVATE_NETWORKS = tuple(ipaddress.IPv4Network(network) for network in ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16"))
NO_ADDRESS = "no device address: set it on the Attendance screen (the device card); this PC picks it up within a minute"
# The admin's buttons, named in what a hand-run test prints.
BUTTONS = {"pull": "Sync now", "test": "Test link", "set_clock": "Set device clock"}
log = logging.getLogger("bhabaghure_attendance")


@dataclass
class Outcome:
    checked_in: bool = False
    pulled: bool = False  # the run went for the device: read it, tried to, or found no address to try
    status: str = "idle"
    command: str | None = None
    address: str | None = None  # address:port the device was read at
    address_from: str | None = None  # "the Attendance screen" or "agent.ini"
    info: DeviceInfo | None = None
    pc_time: str | None = None  # this PC's clock, in office time, when the device's was read
    sent: int = 0
    stored: int = 0
    duplicates: int = 0
    rejected: int = 0
    error: str | None = None
    notes: list[str] = field(default_factory=list)


def run_once(api: Api, store: Store, settings: Settings, open_device: Callable[[Settings], Device], *,
             save_settings: Callable[[Settings], object] = config.write, force_pull: bool = False, test_only: bool = False,
             now: Callable[[], float] = time.time, read_seconds: float = DEVICE_READ_SECONDS) -> Outcome:
    outcome = Outcome()

    try:
        reply = api.check_in(_last_pull(store))
    except (ApiUnavailable, ApiRejected) as error:
        outcome.status, outcome.error = _failure(error), str(error)
        log.warning("check-in failed: %s", error)
        return outcome
    outcome.checked_in = True

    command = (reply.get("command") or {}).get("type")
    if test_only and command not in (None, "test"):
        # A hand-run test answers only Test link. Sync now and Set device clock stay pending, and the server hands them
        # to the next scheduled run, which does them.
        outcome.notes.append(f"{BUTTONS.get(command, command)} was left pending for the scheduled run.")
        command = None
    outcome.command = command

    settings, outcome.address_from, moved = _device_address(reply.get("device"), settings, save_settings, store)
    # A new address waits for a pull: a hand-run test, or a Test link, sees it first but doesn't use it up.
    if moved:
        store.set("address_pull_due", "1")
    moved = store.get("address_pull_due") == "1"
    interval = int(reply.get("pull_interval_minutes") or 15) * 60
    if not (force_pull or test_only or command or moved or _pull_due(store, settings, interval, now())):
        return outcome

    # A pull reads and sends the punches. Test link (an admin's, or agent.exe test) reads the device and reports, no
    # more, and isn't counted as a pull: the punches stay due.
    pull = not test_only and (force_pull or command != "test")
    if pull:
        store.set("last_pull_attempt_at", now())
        store.set("address_pull_due", None)
    _visit_device(api, store, settings, open_device, command, pull, outcome, read_seconds)
    if pull:
        if outcome.status == "ok":
            store.set("last_pull_ok_at", now())
            store.set("failed_pulls", 0)
        else:
            store.set("failed_pulls", _failed_pulls(store) + 1)
        store.set("last_pull_status", outcome.status)
        store.set("last_error", outcome.error)
    return outcome


def _pull_due(store: Store, settings: Settings, interval: int, at: float) -> bool:
    """An interval after the last good pull. After a failed one, soon and then less often (1, 2, 4 and 8 minutes, then
    once an interval), so a device busy for a moment is read again quickly while one switched off for the night doesn't
    fill the sync log with a report every minute. Without an address a retry can't do better: once an interval, and an
    address set on the Attendance screen is taken at once anyway (it counts as moved)."""
    failures = _failed_pulls(store)
    if failures == 0:
        since, wait = float(store.get("last_pull_ok_at", "0") or 0), interval
    else:
        since = float(store.get("last_pull_attempt_at", "0") or 0)
        no_address = not settings.device_address and store.get("last_pull_status") == "no_address"
        wait = interval if no_address else min(interval, 60 * 2 ** min(failures - 1, 4))
    # The PC's clock was put back since: waiting for it to pass the old time again could stop the pulls for hours.
    if at < since:
        return True
    # The task starts once a minute, so the next run comes a little under or over 60 s after this one: allow for it.
    return at - since >= wait - RUN_SLACK_SECONDS


def _failed_pulls(store: Store) -> int:
    try:
        return max(0, int(store.get("failed_pulls", "0") or 0))
    except ValueError:
        return 0


def _visit_device(api: Api, store: Store, settings: Settings, open_device: Callable[[Settings], Device], command: str | None,
                  pull: bool, outcome: Outcome, read_seconds: float) -> None:
    outcome.pulled = True
    if not settings.device_address:
        # Nothing to connect to, so the device is left alone and the card says what is missing.
        outcome.status, outcome.error = "no_address", NO_ADDRESS
        log.warning("no device address: none from the Attendance screen and none in agent.ini")
        _report(api, outcome, {
            "status": "no_address",
            "address": None,
            "error": NO_ADDRESS,
            "pc_time": office_text(office_now()),
            "command": _failed(command, NO_ADDRESS),
        })
        return
    outcome.address = f"{settings.device_address}:{settings.device_port}"

    def read():
        with open_device(settings) as device:
            info, pc_time = device.info(), office_text(office_now())
            users = device.users()
            answer = None
            if command == "set_clock":
                device.set_clock(office_now())
                answer = {"type": command, "result": "ok", "detail": f"set to {office_text(office_now())}"}
                info, pc_time = device.info(), office_text(office_now())
            elif command in ("test", "pull"):
                answer = {"type": command, "result": "ok"}
            return info, pc_time, users, (device.punches() if pull else []), answer

    try:
        info, pc_time, users, punches, answer = with_deadline(read_seconds, read)
    except DeviceUnavailable as error:
        outcome.status, outcome.error = "device_unreachable", str(error)
        log.warning("device unreachable at %s: %s", outcome.address, error)
        _report(api, outcome, {
            "status": "device_unreachable",
            "address": outcome.address,
            "error": str(error)[:300],
            "pc_time": office_text(office_now()),
            "command": _failed(command, str(error)),
        })
        return
    outcome.info, outcome.pc_time = info, pc_time

    if not _report(api, outcome, {
        "status": "ok",
        "serial": info.serial,
        "model": info.model,
        "firmware": info.firmware,
        "address": outcome.address,
        "device_time": info.device_time,
        "pc_time": pc_time,
        "users_count": info.users_count,
        "fingers_count": info.fingers_count,
        "records_count": info.records_count,
        "users": users,
        "command": answer,
    }):
        return
    if punches:
        _send(api, store, info, punches, outcome)
    if outcome.status == "idle":
        outcome.status = "ok"


def _device_address(sent, settings: Settings, save: Callable[[Settings], object], store: Store) -> tuple[Settings, str | None, bool]:
    """Where the device is: the address set on the Attendance screen (the check-in's "device"), or with none there,
    agent.ini's (install.cmd -DeviceAddress, or the last one the screen gave). A new one from the screen is written to
    agent.ini and used at once. Returns the settings to use, where the address came from, and whether it just changed.

    "Changed" is measured against the last address the screen sent (kept in the agent's state), not against agent.ini:
    an agent.ini that can't be written would otherwise make every run look like a move, with a full pull every minute."""
    given = _sent_address(sent)
    if given is None:
        return settings, "agent.ini" if settings.device_address else None, False
    address, port = given
    key = f"{address}:{port}"
    moved = store.get("screen_address") != key
    if moved:
        store.set("screen_address", key)
    if given == (settings.device_address, settings.device_port):
        return settings, "the Attendance screen", moved
    updated = dataclasses.replace(settings, device_address=address, device_port=port)
    try:
        save(updated)
        log.info("device address from the Attendance screen: %s:%s (was %s); agent.ini updated", address, port, _where(settings))
    except (OSError, config.ConfigError) as error:
        log.warning("device address from the Attendance screen: %s:%s, used, but agent.ini couldn't be updated: %s", address, port, error)
    return updated, "the Attendance screen", moved


def _sent_address(sent) -> tuple[str, int] | None:
    if not isinstance(sent, dict) or not sent.get("address"):
        return None
    try:
        address = ipaddress.IPv4Address(str(sent["address"]).strip())
        port = int(sent.get("port") or 4370)
    except (TypeError, ValueError):
        address, port = None, 0
    if address is None or not any(address in network for network in PRIVATE_NETWORKS) or not 1 <= port <= 65535:
        log.warning("ignored a device address from the server that isn't a private IPv4 address and port: %r", sent)
        return None
    return str(address), port


def _where(settings: Settings) -> str:
    return f"{settings.device_address}:{settings.device_port}" if settings.device_address else "none"


def _failed(command: str | None, error: str) -> dict | None:
    return {"type": command, "result": "failed", "detail": error[:300]} if command else None


def _failure(error: ApiUnavailable | ApiRejected) -> str:
    if isinstance(error, ApiBlocked):
        return "api_blocked"
    if isinstance(error, ApiUnavailable):
        return "api_unavailable"
    # rejected:device_changed — this token belongs to another device until an admin confirms the replacement.
    return f"rejected:{error.code}"


def _report(api: Api, outcome: Outcome, body: dict) -> bool:
    try:
        api.report(body)
        return True
    except (ApiUnavailable, ApiRejected) as error:
        outcome.status, outcome.error = _failure(error), str(error)
    log.warning("report failed: %s", outcome.error)
    return False


def _send(api: Api, store: Store, info: DeviceInfo, punches: list[Punch], outcome: Outcome) -> None:
    keys = [punch.key(info.serial) for punch in punches]
    known = store.acknowledged(keys)
    pending = [punch for punch, key in zip(punches, keys) if key not in known]
    outcome.notes.append(f"{len(punches)} records on the device, {len(pending)} not yet acknowledged")

    for start in range(0, len(pending), BATCH):
        batch = pending[start:start + BATCH]
        payload = [{"user_id": punch.user_id, "time": punch.time, "verify": punch.verify, "state": punch.state} for punch in batch]
        try:
            result = api.punches(info.serial, payload)
        except (ApiUnavailable, ApiRejected) as error:
            outcome.status, outcome.error = _failure(error), str(error)
            log.warning("punch batch not accepted, will retry: %s", error)
            return
        # Rejected punches (a clock reset's impossible dates) are acknowledged too: sending them again changes nothing.
        store.acknowledge([punch.key(info.serial) for punch in batch])
        outcome.sent += len(batch)
        outcome.stored += int(result.get("stored", 0))
        outcome.duplicates += int(result.get("duplicates", 0))
        outcome.rejected += len(result.get("rejected", []))


def _last_pull(store: Store) -> dict | None:
    status = store.get("last_pull_status")
    if status is None:
        return None
    return {"status": status, "error": store.get("last_error")}
