#Requires -RunAsAdministrator
<#
.SYNOPSIS
  Installs, updates or removes the Bhabaghure attendance agent on the office PC (docs/phase-7-hr-attendance-bonus-wallet.md §5.2).

.DESCRIPTION
  Started by double-clicking install.cmd next to it, which asks Windows for administrator rights and passes on any
  arguments. It copies the agent to Program Files and keeps its settings, token and state in
  C:\ProgramData\Bhabaghure\Attendance, readable only by SYSTEM and Administrators. The device token (from the admin's
  Attendance screen) is asked for and stored encrypted with Windows DPAPI. A scheduled task runs the agent every minute
  and at start-up, as SYSTEM, whether or not anyone is signed in, never two at once. Finally it runs a test and prints
  what it found. Nothing listens on the network: no firewall or router change is needed.

  The device's network address is set on the admin's Attendance screen (the device card) and reaches the agent within a
  minute; -DeviceAddress is only a fallback for while none is set there. The Comm Key stays on this PC. A setting that
  isn't given keeps its current value, so an update is the new version's install.cmd, double-clicked.

    install.cmd                               install, or update
    install.cmd -CommKey 1234                 the device's Comm Key (Menu > Comm. > Comm Key), if it has one
    install.cmd -DeviceAddress 192.168.1.20   the fallback address
    install.cmd -Uninstall                    remove the task, the program, its settings and the stored token
#>
param(
    [string]$ApiUrl,
    [string]$DeviceAddress,
    [int]$DevicePort,
    [int]$CommKey,
    [switch]$Uninstall
)

$ErrorActionPreference = 'Stop'
$TaskName = 'Bhabaghure Attendance Agent'
$ProgramDir = Join-Path $env:ProgramFiles 'Bhabaghure Attendance Agent'
$DataDir = Join-Path $env:ProgramData 'Bhabaghure\Attendance'
# What build.ps1 puts in the package, and all that is ever unblocked or copied: never other files in the folder this runs
# from, which may be Downloads. _internal is PyInstaller's folder beside agent.exe.
$Package = @('agent.exe', '_internal', 'install.cmd', 'install.ps1', 'README.md', 'LICENSE-pyzk.txt')

function Get-AgentProcess {
    # agent.exe started from the program folder: a scheduled run, or a test someone started by hand.
    $prefix = $ProgramDir.TrimEnd('\') + '\'
    Get-CimInstance Win32_Process -Filter "Name = 'agent.exe'" |
        Where-Object { $_.ExecutablePath -and $_.ExecutablePath.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase) }
}

function Suspend-Agent {
    # The task is disabled so that no new run starts, and a run in progress gets up to a minute to finish, so that the
    # program's files can be replaced or removed. One still busy after that is stopped: what it was sending goes again.
    if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
        Disable-ScheduledTask -TaskName $TaskName | Out-Null
    }
    $deadline = (Get-Date).AddSeconds(60)
    while (@(Get-AgentProcess).Count -gt 0 -and (Get-Date) -lt $deadline) {
        Start-Sleep -Seconds 1
    }
    $busy = @(Get-AgentProcess)
    if ($busy.Count -gt 0) {
        Write-Host 'The agent is still busy after a minute; stopping it (what it was sending goes again on its next run).'
        Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
        $busy | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
        Start-Sleep -Seconds 2
        # A stopped run can't remove its lock, and nothing else is running now.
        Remove-Item -LiteralPath (Join-Path $DataDir 'agent.lock') -Force -ErrorAction SilentlyContinue
    }
}

if ($Uninstall) {
    Suspend-Agent
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
    # The token and settings first: they matter most, and the program folder may be the one this runs from, which Windows
    # can refuse to delete while it is in use.
    if (Test-Path -LiteralPath $DataDir) { Remove-Item -LiteralPath $DataDir -Recurse -Force }
    Write-Host 'Removed the scheduled task, the settings and the stored token.'
    if (Test-Path -LiteralPath $ProgramDir) {
        try {
            Remove-Item -LiteralPath $ProgramDir -Recurse -Force
            Write-Host 'Removed the program.'
        } catch {
            Write-Host "The program folder couldn't be removed while in use: delete '$ProgramDir' by hand, or run install.cmd -Uninstall again from the unzipped folder."
        }
    }
    return
}

$source = (Get-Item -LiteralPath $PSScriptRoot).FullName
if (-not (Test-Path -LiteralPath (Join-Path $source 'agent.exe'))) { throw "agent.exe isn't next to install.ps1. Run install.cmd from the unzipped agent folder." }
$items = foreach ($name in $Package) {
    $path = Join-Path $source $name
    if (Test-Path -LiteralPath $path) { Get-Item -LiteralPath $path }
}
if (-not ($items | Where-Object Name -eq '_internal')) { throw "The _internal folder isn't next to agent.exe. Unzip the whole download, then run install.cmd in that folder." }
# Downloaded files carry the internet zone mark, and Windows would ask before running each one. Only the package's own.
$items | ForEach-Object { if ($_.PSIsContainer) { Get-ChildItem -LiteralPath $_.FullName -Recurse -File | Unblock-File } else { Unblock-File -LiteralPath $_.FullName } }
# Run from the program folder itself (to change a setting or the token), there is nothing to copy.
$inPlace = [string]::Equals($source.TrimEnd('\'), $ProgramDir.TrimEnd('\'), [StringComparison]::OrdinalIgnoreCase)
$agent = Join-Path $ProgramDir 'agent.exe'

# The token is asked for before the agent is paused: closing this window at the question then leaves it running as it
# was, never with its scheduled task switched off.
$existing = Test-Path -LiteralPath (Join-Path $DataDir 'token.bin')
$prompt = if ($existing) { 'Paste a new device token, or press Enter to keep the stored one' } else { 'Paste the device token from the Attendance screen (Ctrl+V or right-click), then press Enter' }
$secure = Read-Host -AsSecureString $prompt

try {
    Suspend-Agent

    # 1. The program. Updates are installed the same way; an update's _internal replaces the old one whole.
    if (-not $inPlace) {
        New-Item -ItemType Directory -Force $ProgramDir | Out-Null
        $oldInternal = Join-Path $ProgramDir '_internal'
        if (Test-Path -LiteralPath $oldInternal) { Remove-Item -LiteralPath $oldInternal -Recurse -Force }
        $items | Copy-Item -Destination $ProgramDir -Recurse -Force
    }

    # 2. The data folder: SYSTEM (S-1-5-18) and Administrators (S-1-5-32-544) only, by SID so any Windows language works.
    New-Item -ItemType Directory -Force $DataDir | Out-Null
    & icacls $DataDir /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not restrict the permissions of the data folder.' }

    # 3. Settings: only what was given changes, so an update keeps the Comm Key and the rest. Then the token (typed or
    #    pasted; never shown, never written in plain text).
    $configure = @('configure')
    if ($PSBoundParameters.ContainsKey('ApiUrl')) { $configure += '--api-url', $ApiUrl }
    if ($PSBoundParameters.ContainsKey('DeviceAddress')) { $configure += '--device-address', $DeviceAddress }
    if ($PSBoundParameters.ContainsKey('DevicePort')) { $configure += '--device-port', $DevicePort }
    if ($PSBoundParameters.ContainsKey('CommKey')) { $configure += '--comm-key', $CommKey }
    & $agent @configure
    if ($LASTEXITCODE -ne 0) { throw 'Could not write the settings.' }
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try {
        $plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    }
    if ($plain -ne '' -or -not $existing) {
        $plain | & $agent set-token
        if ($LASTEXITCODE -ne 0) { throw 'Could not store the token.' }
    }
    $plain = $null

    # 4. The scheduled task.
    $action = New-ScheduledTaskAction -Execute $agent -Argument 'run' -WorkingDirectory $ProgramDir
    $everyMinute = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
    $atStartup = New-ScheduledTaskTrigger -AtStartup
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 10)
    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger @($everyMinute, $atStartup) -Settings $settings -Principal $principal -Force | Out-Null
    Write-Host "Scheduled task '$TaskName' registered: every minute, and at start-up."
} finally {
    # Whatever happened above, a task that exists runs again.
    if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
        Enable-ScheduledTask -TaskName $TaskName | Out-Null
    }
}

# 5. A test run: check in with the API, read the device, report. It sends no punches; the task does that.
Write-Host ''
Write-Host 'Testing...'
& $agent test
if ($LASTEXITCODE -eq 0) {
    Write-Host ''
    Write-Host 'Installed. Punches reach the admin within 15 minutes, or at once with "Sync now" on the Attendance screen.'
} else {
    Write-Host ''
    Write-Host 'Installed, but the test found a problem (above). The task keeps trying every minute; see README.md, "When something is wrong".'
}
