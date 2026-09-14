"""Where the agent keeps its files, and its settings.

Everything lives in one folder, readable only by SYSTEM and Administrators (install.ps1 sets that):
  agent.ini   API address and device address — no secrets
  token.bin   the device token, encrypted with Windows DPAPI
  agent.db    punches the server has acknowledged, and run state
  logs\\      seven days of logs: counts and errors, never names
"""

import configparser
import os
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import urlparse

DEFAULT_HOME = Path(os.environ.get("PROGRAMDATA", r"C:\ProgramData")) / "Bhabaghure" / "Attendance"


def home() -> Path:
    """BHABAGHURE_AGENT_HOME overrides the folder, for development and tests."""
    return Path(os.environ.get("BHABAGHURE_AGENT_HOME", str(DEFAULT_HOME)))


@dataclass(frozen=True)
class Settings:
    api_url: str
    device_address: str
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
        raise ConfigError(f"No settings at {path}. Run install.ps1 first.")
    parser = configparser.ConfigParser()
    parser.read(path, encoding="utf-8")
    try:
        settings = Settings(
            api_url=parser.get("api", "url").rstrip("/"),
            device_address=parser.get("device", "address"),
            device_port=parser.getint("device", "port", fallback=4370),
            comm_key=parser.getint("device", "comm_key", fallback=0),
            device_timeout=parser.getint("device", "timeout_seconds", fallback=10),
        )
    except (configparser.Error, ValueError) as error:
        raise ConfigError(f"{path}: {error}") from error
    check_api_url(settings.api_url)
    return settings


def check_api_url(url: str) -> None:
    """HTTPS only; plain HTTP just for a local development API."""
    parsed = urlparse(url)
    local = parsed.hostname in ("localhost", "127.0.0.1")
    if parsed.scheme != "https" and not (parsed.scheme == "http" and local):
        raise ConfigError(f"The API address must be https:// (got {url}).")


def write(settings: Settings, path: Path | None = None) -> Path:
    check_api_url(settings.api_url)
    path = path or home() / "agent.ini"
    path.parent.mkdir(parents=True, exist_ok=True)
    parser = configparser.ConfigParser()
    parser["api"] = {"url": settings.api_url}
    parser["device"] = {
        "address": settings.device_address,
        "port": str(settings.device_port),
        "comm_key": str(settings.comm_key),
        "timeout_seconds": str(settings.device_timeout),
    }
    with path.open("w", encoding="utf-8") as handle:
        parser.write(handle)
    return path
