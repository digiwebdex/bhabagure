"""Office time. Bangladesh keeps UTC+6 all year (no daylight saving), so a fixed offset is exact and needs no tz database,
which Windows Python doesn't ship. The PC's own timezone setting doesn't matter."""

from datetime import datetime, timedelta, timezone

OFFICE = timezone(timedelta(hours=6), "Asia/Dhaka")
FORMAT = "%Y-%m-%d %H:%M:%S"


def office_now() -> datetime:
    """Now in office time, without tzinfo — the form the device's own clock uses."""
    return datetime.now(OFFICE).replace(tzinfo=None, microsecond=0)


def office_text(moment: datetime) -> str:
    return moment.strftime(FORMAT)
