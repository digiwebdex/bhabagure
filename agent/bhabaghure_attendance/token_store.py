"""The device token, encrypted with Windows DPAPI in machine scope.

Machine scope lets the scheduled task (running as SYSTEM) decrypt what install.ps1 (running as an administrator) stored;
the folder's permissions keep everyone else from reading the file at all. Anyone with administrator rights on this PC
could still read it, which is why the plan asks for a PC whose administrator password staff don't have.
"""

import ctypes
import sys
from pathlib import Path

from . import config

CRYPTPROTECT_UI_FORBIDDEN = 0x01
CRYPTPROTECT_LOCAL_MACHINE = 0x04


class TokenError(Exception):
    pass


def path() -> Path:
    return config.home() / "token.bin"


def save(token: str) -> None:
    token = token.strip()
    if not token.startswith("bhatt_") or len(token) != 70:
        raise TokenError("That doesn't look like a device token (it starts with bhatt_ and is 70 characters).")
    target = path()
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_bytes(_protect(token.encode("ascii")))


def load() -> str:
    target = path()
    if not target.exists():
        raise TokenError(f"No device token at {target}. Run: agent.exe set-token")
    try:
        return _unprotect(target.read_bytes()).decode("ascii")
    except OSError as error:
        raise TokenError(f"The device token at {target} can't be decrypted on this PC: {error}") from error


class _Blob(ctypes.Structure):
    _fields_ = [("cbData", ctypes.c_uint32), ("pbData", ctypes.POINTER(ctypes.c_char))]


def _windows() -> bool:
    return sys.platform == "win32"


def _protect(data: bytes) -> bytes:
    if not _windows():
        raise TokenError("The token can only be stored on Windows (DPAPI).")
    buffer = ctypes.create_string_buffer(data, len(data))
    source = _Blob(len(data), ctypes.cast(buffer, ctypes.POINTER(ctypes.c_char)))
    result = _Blob()
    crypt32 = ctypes.windll.crypt32
    if not crypt32.CryptProtectData(ctypes.byref(source), ctypes.c_wchar_p("Bhabaghure attendance device token"), None, None, None,
                                    CRYPTPROTECT_UI_FORBIDDEN | CRYPTPROTECT_LOCAL_MACHINE, ctypes.byref(result)):
        raise ctypes.WinError()
    try:
        return ctypes.string_at(result.pbData, result.cbData)
    finally:
        ctypes.windll.kernel32.LocalFree(result.pbData)


def _unprotect(data: bytes) -> bytes:
    if not _windows():
        raise TokenError("The token can only be read on Windows (DPAPI).")
    buffer = ctypes.create_string_buffer(data, len(data))
    source = _Blob(len(data), ctypes.cast(buffer, ctypes.POINTER(ctypes.c_char)))
    result = _Blob()
    crypt32 = ctypes.windll.crypt32
    if not crypt32.CryptUnprotectData(ctypes.byref(source), None, None, None, None, CRYPTPROTECT_UI_FORBIDDEN, ctypes.byref(result)):
        raise ctypes.WinError()
    try:
        return ctypes.string_at(result.pbData, result.cbData)
    finally:
        ctypes.windll.kernel32.LocalFree(result.pbData)
