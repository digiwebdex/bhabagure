# Holiday calendar (2026-10-12)

**Asked (the owner):** a yearly holiday calendar that every staff member can see, with the year's fixed holidays, kept
up to date by the super admin.

**Decided (the owner's answers, 2026-10-12, each as recommended):**
- **Only the super admin keeps it.** Admins, who could add holidays under Attendance → Rules before, now only see them.
  This is a permission, `holidays.manage`, that no role holds by default; the super admin can grant it to another role in
  Roles & permissions.
- **Staff see holidays, the weekly day off and their own leave** (approved, and pending marked as waiting). Nobody sees
  anyone else's leave.
- **A holiday of several days (Eid, Puja) is one date range**, entered once.
- **Twelve small months and the list beside them** (on a phone the list comes first).

## What people see

**HR → Holiday calendar**, for every staff member:
- The year switch (← 2025 · 2026 · 2027 →), the next holiday from today with its dates and length, and how many
  holidays and days off for holidays the year has.
- Twelve small months, the week starting on Saturday so the Friday off is the last column: holidays in orange, the weekly
  day off shaded, the person's own approved leave outlined in green (dashed while waiting for approval), today
  underlined. Hovering a day names it; screen readers hear the same.
- The year's holidays in order: English and Bangla name, the date or date range, the number of days. Past ones are
  dimmed.

**For whoever keeps it** (the super admin by default):
- **+ Add holiday**: From, To (the same day for a one-day holiday), the name in English and in Bangla. It shows the
  number of days as they are picked. A holiday can be 1 to 15 days, and can't fall on a day another holiday already has.
- **Edit** a holiday: its dates and names; days dropped from a range become working days again.
- **Remove**, after a confirmation.
- **Copy from <last year>**: tick the holidays that fall on the same date every year (21 February, 26 March,
  16 December) and they are added on the same days. Holidays that move each year (Eid, Puja) are added by hand. One that
  would land on a day already a holiday is skipped and named.

Attendance and salary read the same holidays as before: nobody is absent on a holiday, and holidays aren't working days.
Attendance → Rules now shows the year's count with **Open the holiday calendar**.

## How it works

- `holidays` keeps one row per day, so attendance and payroll are unchanged. The days of one holiday share a `group_id`
  (migration `2026_10_12_100000_holiday_groups.php`; holidays entered earlier each became their own group).
- `App\Services\Attendance\HolidayCalendar`: `year()`, `view()` (holidays, holiday days in the year, weekly days off per
  month from the attendance rules in force, the person's approved and pending leave, `can_manage`), `save()` (add, or
  replace a holiday's days; refuses an overlap or more than 15 days), `remove()`, `copy()`.
- Routes: `GET admin/profile/holidays?year=` for every signed-in staff member; `POST admin/holidays`,
  `PUT admin/holidays/{id}`, `DELETE admin/holidays/{id}`, `POST admin/holidays/copy` need `holidays.manage`. The old
  `admin/attendance/holidays` routes are gone.
- Refusals: `holiday_overlap`, `holiday_too_long` (409). Audit: `attendance.holiday_saved` (before and after) and
  `attendance.holiday_removed`.
- Admin: `features/holidays/HolidayCalendarPage.tsx`, menu item **Holiday calendar** under HR for everyone.

## Tests

- API `HolidayCalendarTest`: nobody but the super admin keeps it (admins included) until granted in Roles; a range is
  one holiday of a row per day; overlap, length and order refused; a change replaces the days; everyone's view of the
  year with only their own leave; attendance reads each day; copy to next year, a second copy skipped; remove; audit.
- Admin e2e `holidays.spec.ts`: the super admin adds a day and a range, is refused an overlap, shortens the range,
  copies the fixed-date holiday to the next year and removes it; a sales agent sees the calendar without any way to
  change it.
