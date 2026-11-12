# Bhabaghure attendance agent

A small program for an office Windows PC. It reads the office ZKTeco fingerprint device over the local network and
sends punches to the Bhabaghure API. The server can't reach the device, which sits behind the office router, so the
agent does the reaching: it only ever makes outgoing HTTPS requests.

Design and reasons: `docs/phase-7-hr-attendance-bonus-wallet.md` §5.2.

## What it does

Windows Task Scheduler starts it every minute, as SYSTEM. Each run:
1. **Checks in** with the API. The reply carries the device's network address, as set on the Attendance screen, and may
   carry a command from an admin: *Sync now*, *Test link* or *Set device clock*.
2. **Pulls, when due:** 15 minutes after the last good pull, at once for a command, or at once when the device's address
   has just changed.
   - It connects to the device (TCP 4370) and reads its serial, clock and counts, the user list and the attendance log.
   - It reports that to the API.
   - It sends the punches the API hasn't acknowledged yet, 500 at a time.
3. **Exits.**

A failed pull is tried again after 1, 2, 4 and 8 minutes, then every 15; *Sync now* tries at once. The punches are
still on the device, and the API ignores a punch it already has, so nothing is lost and nothing is counted twice. A
device read that hasn't finished after three minutes is given up, and counts as failed.

**It never:**
- disables the device (staff can punch while it reads);
- clears its log or changes users;
- keeps, logs or sends passwords, card numbers or fingerprint templates (the device's user list includes passwords and
  card numbers; the agent drops them, and it never asks the device for templates).

The only thing it writes to the device is the clock, and only when an admin presses *Set device clock*.

## Install

You need:
- **The PC:** a 64-bit Windows 10 or 11 PC on the office network, on the same network as the device. Its administrator
  password should be one staff don't have, because anyone with administrator rights on it could send made-up punches.
- **The device on the Attendance screen:** add the device and copy its token. It is shown only once; if it is lost,
  press *Rotate token*. Then set the device's network address on its card. The address is on the device under
  *Menu → Comm. → Ethernet*; give the device a fixed one there (DHCP off), so that it doesn't change.
- **The device's Comm Key, if it has one:** on the device under *Menu → Comm. → Comm Key*. A Comm Key of 0 means none,
  and then there is nothing to do.

Then, on the office PC:
1. Open the Attendance screen and download the agent from the device card (`BhabaghureAttendanceAgent-<version>.zip`).
2. Right-click the zip, *Properties*; if there is an *Unblock* box at the bottom, tick it and press *OK*. Then right-click
   the zip again, *Extract All…*. The zip holds one folder, `BhabaghureAttendanceAgent-<version>`: open it.
3. In that folder, double-click **`install.cmd`**. If Windows shows *Windows protected your PC* (SmartScreen: the file
   is downloaded and not signed), press *More info*, then *Run anyway*. Answer *Yes* when Windows asks for administrator
   rights.
4. When it asks for the device token, paste it (Ctrl+V or right-click) and press Enter. It shows only asterisks.

It installs the agent, registers the task, and runs a test that prints whether the API and the device answered and what
it read from the device. The window stays open until you press a key.

**The device's address** comes from the device card, and the agent picks up a change within a minute, so that is where
to change it. `install.cmd -DeviceAddress 192.168.10.116` sets a fallback, used only while the device card has no address.

**With a Comm Key:** open a Command Prompt in the unzipped folder (click the folder window's address bar, type `cmd`, and
press Enter) and run `install.cmd -CommKey 1234`, with the device's own key in place of 1234. The Comm Key stays on
this PC; the server never has it. An update keeps it.

**To update,** download the new version from the Attendance screen and double-click its `install.cmd` the same way.
Press Enter at the token prompt to keep the stored token. Every setting stays as it was. There is no automatic update.

**To remove everything,** run `install.cmd -Uninstall` the same way as the Comm Key, best from the unzipped folder. It
removes the scheduled task, then the stored token and the settings, then the program. Run from the program folder
itself, Windows may not let it delete that folder while it is in use: the token and settings are gone all the same, and
it says which folder to delete by hand.

## Beside ZKTeco's Attendance Management program

The office PC may also run ZKTeco's own *Attendance Management* program, connected to the same device. Many ZKTeco
devices answer one program at a time (not yet tried on this office's K40), so:
- **Keep that program disconnected from the device, or closed, when nobody is using it.** While it holds the device,
  the agent can't read it: the device card and `agent.exe test` say *"the device accepted the connection but didn't
  answer: another program (such as ZKTeco Attendance Management) may be connected to it; disconnect it"*. The agent
  tries again after 1, 2, 4 and 8 minutes, then every 15 (or at once on *Sync now*), and the punches arrive once the
  program lets go. Nothing is lost meanwhile.
- **Never turn on the program's option to delete or clear the device's records after downloading them,** and never
  clear the device's log by hand. The agent reads the punches from the device: any cleared before the agent read them
  never reach the admin.

The agent changes nothing on the device apart from the clock on request, so the program's own data isn't affected.

## Files

| Where | What |
|---|---|
| `C:\Program Files\Bhabaghure Attendance Agent\` | the program |
| `C:\ProgramData\Bhabaghure\Attendance\agent.ini` | the API address; the device's address and port (the last ones from the device card, or the installer's fallback) and its Comm Key |
| `C:\ProgramData\Bhabaghure\Attendance\token.bin` | the device token, encrypted with Windows DPAPI for this PC |
| `C:\ProgramData\Bhabaghure\Attendance\agent.db` | which punches the API has acknowledged, and the last run's state |
| `C:\ProgramData\Bhabaghure\Attendance\logs\` | seven days of logs: counts and errors, no names |

The `ProgramData` folder is readable only by SYSTEM and Administrators.

## Commands

Open a Command Prompt as administrator (*Start*, type `cmd`, right-click *Command Prompt*, *Run as administrator*), go
to the program folder with `cd "C:\Program Files\Bhabaghure Attendance Agent"`, then:

| Command | Does |
|---|---|
| `agent.exe test` | checks in, reads the device and reports. Prints the address it used and what it read: serial, model, firmware, users and fingerprints, records, and the device's clock against this PC's. Sends no punches, and leaves a pending *Sync now* or *Set device clock* to the scheduled run |
| `agent.exe status` | the device address, when it last pulled, the last result, how many punches the API has acknowledged |
| `agent.exe pull` | reads the device and sends its punches now |
| `agent.exe pull --full` | sends the device's whole log again. Harmless: the API keeps no duplicates |
| `agent.exe set-token` | stores a new token: paste it and press Enter |

## When something is wrong

| The admin or the test shows | Likely cause | What to do |
|---|---|---|
| The office PC hasn't checked in | The PC is off or asleep, has no internet, or the task isn't running | Switch the PC on and stop it sleeping; check *Task Scheduler → Bhabaghure Attendance Agent*; run `agent.exe test` |
| No device address | No address is set on the device card | Set the device's address on the Attendance screen; the agent uses it within a minute |
| Device unreachable: *no network connection to …* | The device is off, its cable is out, its address changed, or this PC isn't on the device's network | Check the device is on and plugged in, and its network settings (fixed address, DHCP off); correct the address on the device card |
| Device unreachable: *accepted the connection but didn't answer* | The device is on, but busy: another program, such as ZKTeco Attendance Management, is connected to it | Disconnect that program from the device, or close it. See "Beside ZKTeco's Attendance Management program" |
| Device unreachable: *refused this PC's Comm Key* | The device has a Comm Key, and the agent's doesn't match | Run `install.cmd -CommKey <the device's key>` (see "With a Comm Key") |
| Token refused (401) | The token was rotated or revoked | Press *Rotate token* on the Attendance screen and copy the new token; on the PC, run `agent.exe set-token` and paste it |
| Device changed (409) | This token belongs to a different device's serial | If the device really was replaced, press *Confirm replacement* on the Attendance screen; the next run binds the new one |
| Clock drift | The device's clock is wrong (often after a power cut) | Press *Set device clock*. The PC's clock is used, so keep Windows time sync on |
| The test says *HTTP 403 … not from the API* | Something in front of the API blocked this PC before the API saw it: Cloudflare, or a web proxy in the office. The token isn't the problem | In Cloudflare, *Bot Fight Mode* blocks the agent, and no WAF rule can skip it: turn Bot Fight Mode off, or allow the office's public IP address with an IP Access rule (*Security → WAF → Tools*). A proxy has to let the SYSTEM account through |
| The test says the API is unavailable, but the internet works | The API is down, or a proxy holds the request | Try again in a few minutes; if it lasts, check the API. A proxy has to let the SYSTEM account through |
| Windows warns before running `install.cmd` (*Windows protected your PC*) | The zip came from the internet and the agent isn't signed | Press *More info*, then *Run anyway*; or tick *Unblock* in the zip's *Properties* before unzipping. The installer unblocks the files it copies |
| Windows refuses to run `agent.exe` | Antivirus distrusts an unsigned program | Allow the program folder in the antivirus |

## Build

On a development machine with the `py` launcher and 64-bit Python 3.12 or newer:

```powershell
.\build.ps1
```

It creates `.venv-build`, installs pyzk (pinned by hash) and PyInstaller, runs the tests, and writes
`dist\BhabaghureAttendanceAgent-<version>.zip` with `install.cmd`, `install.ps1`, this README and pyzk's licence. Build
output is never committed. To offer the zip on the Attendance screen, run `deploy/upload-agent-package.sh` in Git Bash:
it puts the zip on the server.

To run only the tests, without installing anything:

```powershell
py -3 -m unittest discover -s tests -t .
```

Without pyzk, the tests that use the real pyzk are skipped; `build.ps1` runs them all.

## Licence

The agent uses [pyzk](https://github.com/fananimi/pyzk), licensed under the GNU GPL v2.0, and pyzk's dependency
[future](https://pypi.org/project/future/), under the MIT licence.

`agent.exe` bundles pyzk, so pyzk's licence is copied into the zip, and this folder's source is public in this repository.
That includes the one pyzk method the agent replaces with a fixed copy when it connects (`bhabaghure_attendance/pyzk_fixes.py`);
the bundled pyzk itself is unchanged. The agent is a separate program that talks to the API over HTTPS, so the rest of the
repository is not affected.
