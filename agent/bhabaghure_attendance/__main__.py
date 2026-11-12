"""agent.exe — the command line.

  agent.exe run            one run: what the scheduled task calls every minute
  agent.exe test           check in, read the device, report; sends no punches; prints what it read
  agent.exe status         what the agent last did
  agent.exe pull [--full]  read the device and send its punches now; --full first forgets what the server acknowledged,
                           so the whole log goes again (harmless)
  agent.exe set-token      store the device token (read from standard input), encrypted with DPAPI
  agent.exe configure [--api-url URL] [--device-address IP] [--device-port 4370] [--comm-key 0]
                           write agent.ini; what isn't given keeps its value. The device's address comes from the
                           Attendance screen; --device-address is only the fallback for while none is set there.
"""

import argparse
import dataclasses
import getpass
import logging
import logging.handlers
import os
import sys
import time
from datetime import datetime

from . import VERSION, config, token_store
from .api import Api
from .clock import FORMAT
from .device import Device
from .runner import Outcome, run_once
from .store import Store

log = logging.getLogger("bhabaghure_attendance")


def main(argv: list[str] | None = None) -> int:
    _console_safe()
    parser = argparse.ArgumentParser(prog="agent.exe", description=f"Bhabaghure attendance agent {VERSION}")
    commands = parser.add_subparsers(dest="command", required=True)
    commands.add_parser("run")
    commands.add_parser("test")
    commands.add_parser("status")
    pull = commands.add_parser("pull")
    pull.add_argument("--full", action="store_true", help="send the whole device log again")
    commands.add_parser("set-token")
    configure = commands.add_parser("configure", help="write agent.ini; what isn't given keeps its value")
    configure.add_argument("--api-url")
    configure.add_argument("--device-address", help="used only while the Attendance screen has no address for the device")
    configure.add_argument("--device-port", type=int)
    configure.add_argument("--comm-key", type=int)
    args = parser.parse_args(argv)

    _logging(verbose=args.command != "run")
    try:
        if args.command == "configure":
            return _configure(args)
        if args.command == "set-token":
            # Piped from PowerShell (install.ps1), the text may start with a byte-order mark and arrive in any code page:
            # read bytes, and let utf-8-sig drop the mark.
            token = sys.stdin.buffer.readline().decode("utf-8-sig", errors="ignore").strip() if not sys.stdin.isatty() else getpass.getpass("Device token: ")
            token_store.save(token)
            print("Device token stored (encrypted for this PC).")
            return 0
        if args.command == "status":
            return _status()
        return _run(args.command, full=getattr(args, "full", False))
    except (config.ConfigError, token_store.TokenError) as error:
        print(f"Not ready: {error}", file=sys.stderr)
        log.error("not ready: %s", error)
        return 2


def _console_safe() -> None:
    """Text from the server or the device can hold any character, and a console or file in a legacy code page can't show
    them all: those are printed escaped, where Python's default for stdout would stop the agent with UnicodeEncodeError."""
    for stream in (sys.stdout, sys.stderr):
        try:
            stream.reconfigure(errors="backslashreplace")
        except (AttributeError, ValueError, OSError):
            pass  # no console at all (None), or not a text stream that can be reconfigured


def _configure(args) -> int:
    path = config.home() / "agent.ini"
    try:
        current = config.load(path)
    except config.ConfigError as error:
        current = config.Settings()
        if path.exists():
            print(f"The settings in {path} can't be read ({error}); writing them anew.", file=sys.stderr)
    given = {
        "api_url": args.api_url.strip().rstrip("/") if args.api_url is not None else None,
        "device_address": args.device_address.strip() if args.device_address is not None else None,
        "device_port": args.device_port,
        "comm_key": args.comm_key,
    }
    settings = dataclasses.replace(current, **{name: value for name, value in given.items() if value is not None})
    config.write(settings, path)
    print(f"Settings written to {path}")
    print(f"  API:            {settings.api_url}")
    if settings.device_address:
        print(f"  Device address: {settings.device_address}:{settings.device_port} (until the Attendance screen gives one)")
    else:
        print("  Device address: none here; the agent uses the one set on the Attendance screen")
    print(f"  Comm Key:       {'set' if settings.comm_key else 'none'}")
    return 0


def _run(command: str, full: bool) -> int:
    settings = config.load()
    lock = _Lock(config.home() / "agent.lock")
    if not lock.acquire():
        log.info("another run is in progress; skipping")
        if command == "run":
            return 0
        print("Another run of the agent is in progress (the scheduled task's, or someone else's). Try again in a minute.")
        return 1
    store = Store(config.home() / "agent.db")
    try:
        if full:
            forgotten = store.forget_acknowledged()
            print(f"Forgot {forgotten} acknowledged punches; the whole log will be sent (the server keeps no duplicates).")
        api = Api(settings.api_url, token_store.load())
        outcome = run_once(
            api,
            store,
            settings,
            lambda current: Device(current.device_address, current.device_port, current.comm_key, current.device_timeout),
            force_pull=command == "pull",
            test_only=command == "test",
        )
        log.info("run: status=%s address=%s command=%s sent=%s stored=%s duplicates=%s rejected=%s error=%s",
                 outcome.status, outcome.address, outcome.command, outcome.sent, outcome.stored, outcome.duplicates, outcome.rejected, outcome.error)
        if command != "run":
            _print(outcome)
        return 0 if outcome.status in ("ok", "idle") else 1
    finally:
        store.close()
        lock.release()


def _print(outcome: Outcome) -> None:
    # Plain ASCII in console output: a Windows console in a legacy code page garbles anything else.
    print(f"API check-in: {'ok' if outcome.checked_in else 'failed'}")
    if outcome.address:
        print(f"Device address: {outcome.address} (from {outcome.address_from})")
    info = outcome.info
    print(f"Device read: {'yes' if info else 'no'}")
    if info:
        print(f"  Serial:      {info.serial or '-'}")
        print(f"  Model:       {info.model or '-'}")
        print(f"  Firmware:    {info.firmware or '-'}")
        print(f"  Users:       {info.users_count}, with {info.fingers_count} fingerprints")
        print(f"  Records:     {info.records_count}")
        print(f"  Device time: {info.device_time or '-'}; this PC: {outcome.pc_time}{_drift(info.device_time, outcome.pc_time)}")
    print(f"Result: {outcome.status}" + (f" - {outcome.error}" if outcome.error else ""))
    for note in outcome.notes:
        print(note)
    if outcome.sent:
        print(f"Sent {outcome.sent}: {outcome.stored} new, {outcome.duplicates} already there, {outcome.rejected} refused.")


def _drift(device_time: str, pc_time: str | None) -> str:
    try:
        seconds = round((datetime.strptime(device_time, FORMAT) - datetime.strptime(pc_time or "", FORMAT)).total_seconds())
    except ValueError:
        return ""
    if abs(seconds) < 2:
        return " (in step)"
    drift = f" (the device is {_span(abs(seconds))} {'ahead' if seconds > 0 else 'behind'}"
    # The admin card offers the button past the same two minutes.
    return drift + ("; press Set device clock on the Attendance screen)" if abs(seconds) > 120 else ")")


def _span(seconds: int) -> str:
    for unit, size in (("day", 86400), ("hour", 3600), ("minute", 60)):
        if seconds >= 2 * size:
            return f"{seconds // size} {unit}s"
    return f"{seconds} seconds"


def _status() -> int:
    store = Store(config.home() / "agent.db")
    try:
        def when(name: str) -> str:
            value = store.get(name)
            return datetime.fromtimestamp(float(value)).strftime("%Y-%m-%d %H:%M:%S") if value else "never"

        print(f"Agent {VERSION}, home {config.home()}")
        try:
            settings = config.load()
            where = f"{settings.device_address}:{settings.device_port}" if settings.device_address else "none yet"
            print(f"Device address:    {where}")
        except config.ConfigError as error:
            print(f"Settings:          not ready: {error}")
        print(f"Last pull attempt: {when('last_pull_attempt_at')}")
        print(f"Last good pull:    {when('last_pull_ok_at')}")
        print(f"Last result:       {store.get('last_pull_status', '-')} {store.get('last_error') or ''}".rstrip())
        print(f"Punches acknowledged by the server: {store.count_acknowledged()}")
        return 0
    finally:
        store.close()


def _logging(verbose: bool) -> None:
    directory = config.home() / "logs"
    directory.mkdir(parents=True, exist_ok=True)
    handler = logging.handlers.TimedRotatingFileHandler(directory / "agent.log", when="midnight", backupCount=7, encoding="utf-8")
    handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s"))
    log.addHandler(handler)
    log.setLevel(logging.INFO)
    if verbose:
        log.addHandler(logging.StreamHandler(sys.stderr))


class _Lock:
    """One run at a time. Task Scheduler already refuses a second instance; this also covers someone running it by hand.
    A lock older than ten minutes is from a run that died, and is taken over."""

    def __init__(self, path):
        self._path = path
        self._fd = None

    def acquire(self) -> bool:
        self._path.parent.mkdir(parents=True, exist_ok=True)
        try:
            if self._path.exists() and time.time() - self._path.stat().st_mtime > 600:
                self._path.unlink()
            self._fd = os.open(str(self._path), os.O_CREAT | os.O_EXCL | os.O_WRONLY)
            os.write(self._fd, str(os.getpid()).encode("ascii"))
            return True
        except FileExistsError:
            return False

    def release(self) -> None:
        if self._fd is not None:
            os.close(self._fd)
            self._fd = None
            try:
                self._path.unlink()
            except FileNotFoundError:
                pass


if __name__ == "__main__":
    sys.exit(main())
