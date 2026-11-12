"""Where the agent keeps its files, and its settings.

Everything lives in one folder, readable only by SYSTEM and Administrators (install.ps1 sets that):
  agent.ini   API address; the device's address and port (the last ones set on the Attendance screen, or install.ps1's
              -DeviceAddress until then) and its Comm Key, which never leaves this PC
  token.bin   the device token, encrypted with Windows DPAPI
  agent.db    punches the server has acknowledged, and run state
  logs\\      seven days of logs: counts and errors, never names
"""

import configparser
import contextlib
import os
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import urlparse

DEFAULT_HOME = Path(os.environ.get("PROGRAMDATA", r"C:\ProgramData")) / "Bhabaghure" / "Attendance"
DEFAULT_API_URL = "https://api.bhabaghure.com.bd"


def home() -> Path:
    """BHABAGHURE_AGENT_HOME overrides the folder, for development and tests."""
    return Path(os.environ.get("BHABAGHURE_AGENT_HOME", str(DEFAULT_HOME)))


@dataclass(frozen=True)
class Settings:
    api_url: str = DEFAULT_API_URL
    # None until the Attendance screen (or install.ps1 -DeviceAddress) gives one; the agent then leaves the device alone.
    device_address: str | None = None
    device_port: int = 4370
    comm_key: int = 0
    device_timeout: int = 10

    @property
    def ini_path(self) -> Path:
        return home() / "agent.ini"


class ConfigError(Exception):
    pass


def load(path: Path | None = None) -> Settings:
    path = path or home() / "agent.ini"
    if not path.exists():
        raise ConfigError(f"No settings at {path}. Run install.cmd first.")
    parser = configparser.ConfigParser(interpolation=None)
    # Everything that can go wrong with a hand-edited file is "not ready" (printed and logged), never a crash on every run.
    try:
        # utf-8-sig: Notepad and Windows PowerShell 5.1 start a file they save as UTF-8 with a byte-order mark.
        with path.open(encoding="utf-8-sig") as handle:
            parser.read_file(handle)
        settings = Settings(
            api_url=parser.get("api", "url").strip().rstrip("/"),
            device_address=parser.get("device", "address", fallback="").strip() or None,
            device_port=parser.getint("device", "port", fallback=4370),
            comm_key=parser.getint("device", "comm_key", fallback=0),
            device_timeout=parser.getint("device", "timeout_seconds", fallback=10),
        )
    except (OSError, UnicodeError, configparser.Error, ValueError) as error:
        raise ConfigError(f"{path} can't be read: {error}") from error
    check_api_url(settings.api_url)
    check_port(settings.device_port)
    return settings


def check_api_url(url: str) -> None:
    """HTTPS only; plain HTTP just for a local development API."""
    parsed = urlparse(url)
    local = parsed.hostname in ("localhost", "127.0.0.1")
    if parsed.scheme != "https" and not (parsed.scheme == "http" and local):
        raise ConfigError(f"The API address must be https:// (got {url}).")


def check_port(port: int) -> None:
    if not 1 <= port <= 65535:
        raise ConfigError(f"The device port must be 1 to 65535 (got {port}).")


def write(settings: Settings, path: Path | None = None) -> Path:
    """UTF-8 without a byte-order mark, replaced in one step (a new file, then a rename): a run never reads half a file,
    and a failed write leaves the old one."""
    check_api_url(settings.api_url)
    check_port(settings.device_port)
    path = path or home() / "agent.ini"
    path.parent.mkdir(parents=True, exist_ok=True)
    parser = configparser.ConfigParser(interpolation=None)
    parser["api"] = {"url": settings.api_url}
    parser["device"] = {
        "address": settings.device_address or "",
        "port": str(settings.device_port),
        "comm_key": str(settings.comm_key),
        "timeout_seconds": str(settings.device_timeout),
    }
    staged = path.with_name(path.name + ".new")
    try:
        with staged.open("w", encoding="utf-8") as handle:
            parser.write(handle)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(staged, path)
    except OSError:
        with contextlib.suppress(OSError):
            staged.unlink(missing_ok=True)
        raise
    return path
