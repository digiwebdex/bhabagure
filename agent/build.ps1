<#
.SYNOPSIS
  Builds dist\BhabaghureAttendanceAgent-<version>.zip (docs/phase-7-hr-attendance-bonus-wallet.md §5.2).

.DESCRIPTION
  Needs 64-bit Python 3.12 or newer (the "py" launcher) and internet access for pip. It makes .venv-build, installs pyzk
  by hash and PyInstaller, runs the tests, builds a one-folder agent.exe (a folder starts faster than a single file,
  which matters when it runs every minute) and zips it with install.ps1, the README and pyzk's licence.

  The build isn't committed: the repository is public, and the zip is carried to the office PC by hand.
#>
param([string]$PythonVersion = '3')

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
$version = (Select-String -Path 'bhabaghure_attendance\__init__.py' -Pattern 'VERSION = "([^"]+)"').Matches[0].Groups[1].Value
$python = '.venv-build\Scripts\python.exe'

if (-not (Test-Path $python)) { & py "-$PythonVersion" -m venv .venv-build }
& $python -m pip install --quiet --upgrade pip
& $python -m pip install --quiet --require-hashes -r requirements.txt
if ($LASTEXITCODE -ne 0) { throw 'pyzk did not install (or its hash did not match).' }
# The unit tests use a fake device, so prove pyzk itself imports on this Python before building around it.
& $python -c "from zk import ZK; print('pyzk imports')"
if ($LASTEXITCODE -ne 0) { throw 'pyzk does not import on this Python version; try another with -PythonVersion (for example 3.12).' }
& $python -m pip install --quiet -r requirements-build.txt
if ($LASTEXITCODE -ne 0) { throw 'PyInstaller did not install.' }

& $python -m unittest discover -s tests -t .
if ($LASTEXITCODE -ne 0) { throw 'Tests failed; nothing was built.' }

Remove-Item -Recurse -Force build, dist -ErrorAction SilentlyContinue
& $python -m PyInstaller --noconfirm --clean --onedir --console --name agent --distpath dist --workpath build --hidden-import zk agent_main.py
if ($LASTEXITCODE -ne 0) { throw 'PyInstaller failed.' }

$out = 'dist\agent'
Copy-Item install.ps1, README.md $out
# pyzk is GPL-2.0 and is bundled in agent.exe: its licence travels with it.
$license = Get-ChildItem '.venv-build\Lib\site-packages' -Recurse -File -Include 'LICENSE*', 'COPYING*' |
    Where-Object { $_.FullName -match 'pyzk' } | Select-Object -First 1
if ($license) {
    Copy-Item $license.FullName (Join-Path $out 'LICENSE-pyzk.txt')
} else {
    Set-Content -Encoding utf8 (Join-Path $out 'LICENSE-pyzk.txt') 'pyzk is licensed under the GNU General Public License v2.0: https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt. Its source: https://github.com/fananimi/pyzk'
}

$zip = "dist\BhabaghureAttendanceAgent-$version.zip"
Compress-Archive -Path "$out\*" -DestinationPath $zip -Force
Write-Host "Built $zip"
