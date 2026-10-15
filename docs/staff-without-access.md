# Staff without software access, salary basis, and a day's status set by hand (2026-10-15)

**Asked (the owner):**
1. Add staff who never use the software — a peon, an office assistant, a video editor — to the staff list as ordinary
   employees, with no login and no role.
2. Keep everyone's salary on the salary sheet, not only the people who use the dashboard.
3. From the admin, change any staff member's day to absent, leave or present by hand, over what the device says.

**Decided (the owner's answers, 2026-10-15, each as recommended):**
- **Software access can be given later**, from the person's record.
- **The salary basis is chosen per person**: "From attendance" (as before) or "Fixed monthly".
- **Unpaid leave costs a day's pay.** It didn't before — the salary sheet counted absences and late days but not unpaid
  leave. This was a defect, fixed here for everyone on the attendance basis.

## 1. Staff without software access

**HR → Staff → + Add staff** has a **Software access** switch, on by default. Switched off:
- No role, no invitation. The email is optional (a contact detail only); name, mobile, designation and joining date as
  usual, and the **Salary basis**.
- They are **Active** from the start, so they are on the attendance (once matched to a device user) and on the salary
  sheet like anyone else.
- The staff list shows **No software access** in place of the role; their record says the same in **Role & access**.
- They can't sign in, can't ask for a password reset ("Forgot password?" sends them nothing), aren't offered a personal
  wallet, and aren't in any "assign to" list (those need a role's permissions).
- Anyone managing staff may change their email; for staff who sign in, it stays super-admin-only as before.
- Suspended and reactivated, they go straight back to Active (there's no password to wait for).

**Give software access** (on their record) asks for the sign-in email and a role, then shows the usual one-time
invitation link (also emailed). They stay Active throughout, so attendance and salary carry on; until they use the link
they simply can't sign in. If the link is lost, **Send password reset** sends a fresh one.

## 2. Salary basis

On the person's record (**Account & HR record → Salary basis**) and in the add dialog:
- **From attendance** (the default, as before): base − day rate × (absent days + unpaid leave days + days outside
  employment + (100 % − late pay %) × late-or-early days) + adjustments.
- **Fixed monthly**: base − day rate × days outside employment + adjustments. Lateness, absence and unpaid leave don't
  change it; someone joining or leaving mid-month is still paid only for their days.

The salary sheet marks fixed-basis rows "· Fixed monthly". A finalised month keeps the figures it was frozen with.

## 3. A day's status set by hand

**HR → Attendance → a person → Correct** (on any day up to today) now offers, beside **Set the in time** and **Set the out
time**:
- **Present — a full day** (was "Mark as worked"; e.g. official duty)
- **Absent**
- **Paid leave**
- **Unpaid leave** (a day's pay comes off)

with an optional **Until** date for several days at once (up to 31 days). Holidays and weekly days off inside the range
are left alone; a single day is set whatever it is.

- The status **overrides the device** and an approved leave request for that day; the device's punches stay on the record
  and the day is marked "Set by hand". When several statuses were set for the same day, the latest one counts.
- Each day is its own correction, listed under **Corrections** with the reason and who set it; **Undo** (with a reason)
  brings back what was there before. Nothing is deleted.
- Leave set by hand isn't a leave request (it doesn't appear in the Leave list); it is counted in the month's paid or
  unpaid leave totals, and unpaid leave comes off the salary on the attendance basis.
- As before, nobody changes their own attendance except the super admin, and a day to come can't be set.

## API

- `POST admin/staff` takes `software_access` (default true; when false, `email` and `role` are optional) and
  `salary_basis` (`attendance` · `fixed`). The response's `invitation` is null without access.
- `PUT admin/staff/{id}` takes `salary_basis`. Rows carry `software_access`; the record's `profile.salary_basis`;
  `actions.grant_access`.
- `POST admin/staff/{id}/access` `{email, role}` gives access and answers with the record and the invitation; refused with
  `already_has_access`. Role changes and password resets on someone without access are refused with `no_software_access`.
- `POST admin/attendance/corrections` takes `kind` `in` · `out` · `worked` · `absent` · `leave_paid` · `leave_unpaid`;
  `time` only for in/out; `until` only for the four statuses. Refused with `range_too_long`, `no_working_days`,
  `future_day`, `own_attendance`.
- A day's `leave` is `{id, paid, manual}`; `id` is null when it was set by hand.
- `GET admin/payroll` rows carry `salary_basis`.

Schema: `staff.email` nullable, `staff.software_access` (default true), `staff_profiles.salary_basis` (default
`attendance`), `attendance_corrections.kind` widened to 16 characters. Existing staff keep access and the attendance
basis.

Tests: `api/tests/Feature/StaffWithoutAccessTest.php`; admin e2e in `admin/e2e/staff.spec.ts` ("a peon is added
without software access…").
