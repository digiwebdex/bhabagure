"""One run of the agent (docs/phase-7-hr-attendance-bonus-wallet.md §5.2).

Every minute: check in (the reply may carry a command from an admin). When 15 minutes have passed since the last pull,
or a command asks for one: read the device, report what it said (or that it didn't answer), then send the punches the
server hasn't acknowledged, 500 per request. A punch is marked acknowledged only after the server's 2xx. Anything that
fails is simply tried again next run: the records are still on the device, and the server ignores what it already has.
"""

import logging
import time
from dataclasses import dataclass, field
from typing import Callable

from .api import Api, ApiRejected, ApiUnavailable
from .clock import office_now, office_text
from .device import Device, DeviceInfo, DeviceUnavailable, Punch
from .store import Store

BATCH = 500
log = logging.getLogger("bhabaghure_attendance")


@dataclass
class Outcome:
    checked_in: bool = False
    pulled: bool = False
    status: str = "idle"
    command: str | None = None
    sent: int = 0
    stored: int = 0
    duplicates: int = 0
    rejected: int = 0
    error: str | None = None
    notes: list[str] = field(default_factory=list)


def run_once(api: Api, store: Store, open_device: Callable[[], Device], *, address: str | None = None, force_pull: bool = False,
             test_only: bool = False, now: Callable[[], float] = time.time) -> Outcome:
    outcome = Outcome()

    try:
        reply = api.check_in(_last_pull(store))
    except (ApiUnavailable, ApiRejected) as error:
        outcome.status, outcome.error = "api_unavailable" if isinstance(error, ApiUnavailable) else "api_rejected", str(error)
        log.warning("check-in failed: %s", error)
        return outcome
    outcome.checked_in = True

    command = (reply.get("command") or {}).get("type")
    outcome.command = command
    interval = int(reply.get("pull_interval_minutes") or 15) * 60
    last_attempt = float(store.get("last_pull_attempt_at", "0") or 0)
    if not (force_pull or test_only or command or now() - last_attempt >= interval):
        return outcome

    store.set("last_pull_attempt_at", now())
    outcome.pulled = True
    command_result = None

    try:
        with open_device() as device:
            info = device.info()
            users = device.users()
            if command == "set_clock":
                device.set_clock(office_now())
                command_result = {"type": command, "result": "ok", "detail": f"set to {office_text(office_now())}"}
                info = device.info()
            elif command in ("test", "pull"):
                command_result = {"type": command, "result": "ok"}
            punches = [] if (test_only or command == "test") else device.punches()
    except DeviceUnavailable as error:
        outcome.status, outcome.error = "device_unreachable", str(error)
        log.warning("device unreachable: %s", error)
        if _report(api, store, outcome, {
            "status": "device_unreachable",
            "address": address,
            "error": str(error)[:300],
            "pc_time": office_text(office_now()),
            "command": {"type": command, "result": "failed", "detail": str(error)[:300]} if command else None,
        }):
            outcome.status, outcome.error = "device_unreachable", str(error)
            store.set("last_pull_status", outcome.status)
            store.set("last_error", outcome.error)
        return outcome

    body = {
        "status": "ok",
        "serial": info.serial,
        "model": info.model,
        "firmware": info.firmware,
        "address": address,
        "device_time": info.device_time,
        "pc_time": office_text(office_now()),
        "users_count": info.users_count,
        "fingers_count": info.fingers_count,
        "records_count": info.records_count,
        "users": users,
        "command": command_result,
    }
    if not _report(api, store, outcome, body):
        return outcome

    if punches:
        _send(api, store, info, punches, outcome)
    if outcome.status in ("idle", "ok"):
        outcome.status = "ok"
        store.set("last_pull_ok_at", now())
    store.set("last_pull_status", outcome.status)
    store.set("last_error", outcome.error)
    return outcome


def _report(api: Api, store: Store, outcome: Outcome, body: dict) -> bool:
    try:
        api.report(body)
        return True
    except ApiUnavailable as error:
        outcome.status, outcome.error = "api_unavailable", str(error)
    except ApiRejected as error:
        # device_changed: this token belongs to another device until an admin confirms the replacement.
        outcome.status, outcome.error = f"rejected:{error.code}", str(error)
    log.warning("report failed: %s", outcome.error)
    store.set("last_pull_status", outcome.status)
    store.set("last_error", outcome.error)
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
        except ApiUnavailable as error:
            outcome.status, outcome.error = "api_unavailable", str(error)
            log.warning("punch batch failed, will retry: %s", error)
            return
        except ApiRejected as error:
            outcome.status, outcome.error = f"rejected:{error.code}", str(error)
            log.warning("punch batch refused: %s", error)
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
