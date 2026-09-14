"""The agent's local memory: which punches the server has acknowledged, and a little run state.

It only saves work. Correctness rests on the server, where a punch is unique on device, user and time — so if this file
is lost, the agent resends the whole log and every punch comes back as a duplicate.
"""

import sqlite3
import time
from pathlib import Path


class Store:
    def __init__(self, path: Path):
        path.parent.mkdir(parents=True, exist_ok=True)
        self._db = sqlite3.connect(str(path), timeout=30)
        self._db.execute("PRAGMA journal_mode=WAL")
        self._db.execute("CREATE TABLE IF NOT EXISTS acknowledged (key TEXT PRIMARY KEY, at INTEGER NOT NULL)")
        self._db.execute("CREATE TABLE IF NOT EXISTS meta (name TEXT PRIMARY KEY, value TEXT)")
        self._db.commit()

    def close(self) -> None:
        self._db.close()

    def acknowledged(self, keys: list[str]) -> set[str]:
        """Which of these keys the server already has."""
        found: set[str] = set()
        for start in range(0, len(keys), 500):
            chunk = keys[start:start + 500]
            placeholders = ",".join("?" * len(chunk))
            found.update(row[0] for row in self._db.execute(f"SELECT key FROM acknowledged WHERE key IN ({placeholders})", chunk))
        return found

    def acknowledge(self, keys: list[str]) -> None:
        now = int(time.time())
        self._db.executemany("INSERT OR IGNORE INTO acknowledged (key, at) VALUES (?, ?)", [(key, now) for key in keys])
        self._db.commit()

    def forget_acknowledged(self) -> int:
        count = self._db.execute("SELECT COUNT(*) FROM acknowledged").fetchone()[0]
        self._db.execute("DELETE FROM acknowledged")
        self._db.commit()
        return count

    def count_acknowledged(self) -> int:
        return self._db.execute("SELECT COUNT(*) FROM acknowledged").fetchone()[0]

    def get(self, name: str, default: str | None = None) -> str | None:
        row = self._db.execute("SELECT value FROM meta WHERE name = ?", (name,)).fetchone()
        return row[0] if row else default

    def set(self, name: str, value: str | int | float | None) -> None:
        self._db.execute("INSERT INTO meta (name, value) VALUES (?, ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value",
                         (name, None if value is None else str(value)))
        self._db.commit()
