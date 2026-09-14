"""agent.exe — the command line.

  agent.exe run            one run: what the scheduled task calls every minute
  agent.exe test           check in, read the device, report; sends no punches; prints what it found
  agent.exe status         what the agent last did
  agent.exe pull --full    forget what the server acknowledged and send the device's whole log again (harmless)
  agent.exe set-token      store the device token (read from standard input), encrypted with DPAPI
  agent.exe configure --api-url URL --device-address IP [--device-port 4370] [--comm-key 0]
"""

import argparse
import getpass
import logging
import logging.handlers
import os
import sys
import time
from datetime import datetime

from . import VERSION, config, token_store
from .api import Api
from .device import Device
from .runner import run_once
from .store import Store

log = logging.getLogger("bhabaghure_attendance")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="agent.exe", description=f"Bhabaghure attendance agent {VERSION}")
    commands = parser.add_subparsers(dest="command", required=True)
    commands.add_parser("run")
    commands.add_parser("test")
    commands.add_parser("status")
    pull = commands.add_parser("pull")
    pull.add_argument("--full", action="store_true", help="send the whole device log again")
    commands.add_parser("set-token")
    configure = commands.add_parser("configure")
    configure.add_argument("--api-url", required=True)
    configure.add_argument("--device-address", required=True)
    configure.add_argument("--device-port", type=int, default=4370)
    configure.add_argument("--comm-key", type=int, default=0)
    args = parser.parse_args(argv)

    _logging(verbose=args.command != "run")
    try:
        if args.command == "configure":
            path = config.write(config.Settings(args.api_url.rstrip("/"), args.device_address, args.device_port, args.comm_key))
            print(f"Settings written to {path}")
            return 0
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


def _run(command: str, full: bool) -> int:
    settings = config.load()
    lock = _Lock(config.home() / "agent.lock")
    if not lock.acquire():
        log.info("another run is in progress; skipping")
        return 0
    store = Store(config.home() / "agent.db")
    try:
        if full:
            forgotten = store.forget_acknowledged()
            print(f"Forgot {forgotten} acknowledged punches; the whole log will be sent (the server keeps no duplicates).")
        api = Api(settings.api_url, token_store.load())
        outcome = run_once(
            api,
            store,
            lambda: Device(settings.device_address, settings.device_port, settings.comm_key, settings.device_timeout),
            address=f"{settings.device_address}:{settings.device_port}",
            force_pull=command == "pull",
            test_only=command == "test",
        )
        log.info("run: status=%s pulled=%s command=%s sent=%s stored=%s duplicates=%s rejected=%s error=%s",
                 outcome.status, outcome.pulled, outcome.command, outcome.sent, outcome.stored, outcome.duplicates, outcome.rejected, outcome.error)
        if command != "run":
            print(f"API check-in: {'ok' if outcome.checked_in else 'failed'}")
            print(f"Device read: {'yes' if outcome.pulled and outcome.status not in ('device_unreachable',) else 'no'}")
            # Plain ASCII in console output: a Windows console in a legacy code page garbles anything else.
            print(f"Result: {outcome.status}" + (f" - {outcome.error}" if outcome.error else ""))
            for note in outcome.notes:
                print(note)
            if outcome.sent:
                print(f"Sent {outcome.sent}: {outcome.stored} new, {outcome.duplicates} already there, {outcome.rejected} refused.")
        return 0 if outcome.status in ("ok", "idle") else 1
    finally:
        store.close()
        lock.release()


def _status() -> int:
    store = Store(config.home() / "agent.db")
    try:
        def when(name: str) -> str:
            value = store.get(name)
            return datetime.fromtimestamp(float(value)).strftime("%Y-%m-%d %H:%M:%S") if value else "never"

        print(f"Agent {VERSION}, home {config.home()}")
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
