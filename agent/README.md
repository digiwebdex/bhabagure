# Bhabaghure attendance agent

A small program for an office Windows PC. It reads the office ZKTeco fingerprint device over the local network and
sends punches to the Bhabaghure API. The server can't reach the device, which sits behind the office router, so the
agent does the reaching: it only ever makes outgoing HTTPS requests.

Design and reasons: `docs/phase-7-hr-attendance-bonus-wallet.md` §5.2.

## What it does

Windows Task Scheduler starts it every minute, as SYSTEM. Each run:
1. **Checks in** with the API. The reply may carry a command from an admin: *Sync now*, *Test link* or *Set device
   clock*.
2. **Pulls, when due:** every 15 minutes, or at once for a command.
   - It connects to the device (TCP 4370) and reads its serial, clock and counts, the user list (IDs and names only) and
     the attendance log.
   - It reports that to the API.
   - It sends the punches the API hasn't acknowledged yet, 500 at a time.
3. **Exits.**

Anything that fails is tried again the next minute. The punches are still on the device, and the API ignores a punch
it already has, so nothing is lost and nothing is counted twice.

**It never:**
- disables the device (staff can punch while it reads);
- clears its log or changes users;
- reads fingerprints, faces, passwords or card numbers.

The only thing it writes to the device is the clock, and only when an admin presses *Set device clock*.

## Install

You need:
- **The PC:** a 64-bit Windows 10 or 11 PC on the office network. Its administrator password should be one staff don't
  have, because anyone with administrator rights on it could send made-up punches.
- **The files:** the zip from `build.ps1`, unzipped.
- **The device token:** on the admin's Attendance screen, add the device and copy its token. It is shown only once; if
  it is lost, rotate it.
- **The device's address and Comm Key:** the address is on the device under *Menu → Comm. → Ethernet*, and the Comm Key
  under *Menu → Comm. → Comm Key*. A Comm Key of 0 means none.

Then, in PowerShell opened **as administrator**, in the unzipped folder, with the device's own address in place of the
example 192.0.2.10:

```powershell
.\install.ps1 -DeviceAddress 192.0.2.10
# with a Comm Key:        .\install.ps1 -DeviceAddress 192.0.2.10 -CommKey 1234
# another API address:    .\install.ps1 -DeviceAddress 192.0.2.10 -ApiUrl https://api.example.com
```

It asks for the token, registers the task, and runs a test that prints whether the API and the device answered.

**To update,** run the new version's `install.ps1` the same way; press Enter at the token prompt to keep the stored
token. There is no automatic update.

**To remove everything:** `.\install.ps1 -Uninstall`.

## Files

| Where | What |
|---|---|
| `C:\Program Files\Bhabaghure Attendance Agent\` | the program |
| `C:\ProgramData\Bhabaghure\Attendance\agent.ini` | the API address and the device's address, port and Comm Key |
| `C:\ProgramData\Bhabaghure\Attendance\token.bin` | the device token, encrypted with Windows DPAPI for this PC |
| `C:\ProgramData\Bhabaghure\Attendance\agent.db` | which punches the API has acknowledged, and the last run's state |
| `C:\ProgramData\Bhabaghure\Attendance\logs\` | seven days of logs: counts and errors, no names |

The `ProgramData` folder is readable only by SYSTEM and Administrators.

## Commands

Run from `C:\Program Files\Bhabaghure Attendance Agent\` in an administrator PowerShell:

| Command | Does |
|---|---|
| `.\agent.exe test` | check in, read the device, report; prints the result; sends no punches |
| `.\agent.exe status` | when it last pulled, the last result, how many punches the API has acknowledged |
| `.\agent.exe pull --full` | sends the device's whole log again. Harmless: the API keeps no duplicates |
| `.\agent.exe set-token` | stores a new token (after rotating it on the Attendance screen) |

## When something is wrong

| The admin shows | Likely cause | What to do |
|---|---|---|
| The office PC hasn't checked in | The PC is off or asleep, or has no internet, or the task isn't running | Switch the PC on and stop it sleeping; check *Task Scheduler → Bhabaghure Attendance Agent*; run `.\agent.exe test` |
| Device unreachable | The device is off, the cable is out, the address changed, or the Comm Key is wrong | Check the device's network settings (fixed IP, DHCP off); re-run `install.ps1` with the right address or Comm Key |
| Token refused (401) | The token was rotated or revoked | Copy the new token from the Attendance screen; `.\agent.exe set-token` |
| Device changed (409) | This token belongs to a different device's serial | If the device really was replaced, press *Confirm replacement* on the Attendance screen; the next run binds the new one |
| Clock drift | The device's clock is wrong (often after a power cut) | Press *Set device clock*. The PC's clock is used, so keep Windows time sync on |
| The test says the API is unavailable, but the internet works | Cloudflare challenged the agent, or the office uses a web proxy | Ask for a Cloudflare WAF skip rule for `/api/v1/attendance-agent/*`; a proxy has to allow the SYSTEM account |
| Windows refuses to run `agent.exe` | SmartScreen or antivirus distrusts an unsigned program | Allow it for this folder, or unblock it in the zip's properties before unzipping |

## Build

On a development machine with the `py` launcher and 64-bit Python 3.12 or newer:

```powershell
.\build.ps1
```

It creates `.venv-build`, installs pyzk (pinned by hash) and PyInstaller, runs the tests, and writes
`dist\BhabaghureAttendanceAgent-<version>.zip`. Build output is never committed.

To run only the tests, without installing anything:

```powershell
py -3 -m unittest discover -s tests -t .
```

## Licence

The agent uses [pyzk](https://github.com/fananimi/pyzk), licensed under the GNU GPL v2.0, and pyzk's dependency
[future](https://pypi.org/project/future/), under the MIT licence.

`agent.exe` bundles pyzk, so pyzk's licence is copied into the zip, and this folder's source is public in this repository.
The agent is a separate program that talks to the API over HTTPS, so the rest of the repository is not affected.
