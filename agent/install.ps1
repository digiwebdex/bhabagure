#Requires -RunAsAdministrator
<#
.SYNOPSIS
  Installs the Bhabaghure attendance agent on the office PC (docs/phase-7-hr-attendance-bonus-wallet.md §5.2).

.DESCRIPTION
  Run from the unzipped agent folder, in PowerShell opened as administrator, with the device's own address (on the
  device under Menu > Comm. > Ethernet) in place of the example 192.0.2.10:

    .\install.ps1 -DeviceAddress 192.0.2.10

  It copies the agent to Program Files and keeps its settings, token and state in
  C:\ProgramData\Bhabaghure\Attendance, readable only by SYSTEM and Administrators. The device token (from the admin's
  Attendance screen) is asked for and stored encrypted with Windows DPAPI. A scheduled task runs the agent every minute
  and at start-up, as SYSTEM, whether or not anyone is signed in, never two at once. Finally it runs a test and prints
  what it found. Nothing listens on the network: no firewall or router change is needed.

  To remove everything (task, program, settings, token):

    .\install.ps1 -Uninstall
#>
param(
    [string]$ApiUrl = 'https://api.bhabaghure.com.bd',
    [string]$DeviceAddress,
    [int]$DevicePort = 4370,
    [int]$CommKey = 0,
    [switch]$Uninstall
)

$ErrorActionPreference = 'Stop'
$TaskName = 'Bhabaghure Attendance Agent'
$ProgramDir = Join-Path $env:ProgramFiles 'Bhabaghure Attendance Agent'
$DataDir = Join-Path $env:ProgramData 'Bhabaghure\Attendance'

if ($Uninstall) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
    if (Test-Path $ProgramDir) { Remove-Item -Recurse -Force $ProgramDir }
    if (Test-Path $DataDir) { Remove-Item -Recurse -Force $DataDir }
    Write-Host 'Removed the scheduled task, the program, its settings and the stored token.'
    return
}

if (-not $DeviceAddress) { throw 'Give the device address (on the device under Menu > Comm. > Ethernet): .\install.ps1 -DeviceAddress <address>' }
$source = $PSScriptRoot
if (-not (Test-Path (Join-Path $source 'agent.exe'))) { throw "agent.exe isn't next to install.ps1. Run this from the unzipped agent folder." }

# 1. The program. A running task is stopped first so its files can be replaced (updates are installed the same way).
Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force $ProgramDir | Out-Null
Copy-Item -Recurse -Force (Join-Path $source '*') $ProgramDir
$agent = Join-Path $ProgramDir 'agent.exe'

# 2. The data folder: SYSTEM (S-1-5-18) and Administrators (S-1-5-32-544) only, by SID so any Windows language works.
New-Item -ItemType Directory -Force $DataDir | Out-Null
& icacls $DataDir /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Could not restrict the permissions of the data folder.' }

# 3. Settings, then the token (typed or pasted; never shown, never written in plain text).
& $agent configure --api-url $ApiUrl --device-address $DeviceAddress --device-port $DevicePort --comm-key $CommKey
if ($LASTEXITCODE -ne 0) { throw 'Could not write the settings.' }
$existing = Test-Path (Join-Path $DataDir 'token.bin')
$prompt = if ($existing) { 'Device token (press Enter to keep the stored one)' } else { 'Device token (from the admin: Attendance, device, token)' }
$secure = Read-Host -AsSecureString $prompt
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
