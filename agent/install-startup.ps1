# Registers Agent.exe to start automatically at Windows boot via Task Scheduler.
# No config file needed: the token (and optional server) are baked into the task.
# Run this ONCE in an elevated PowerShell:
#   powershell -ExecutionPolicy Bypass -File install-startup.ps1 -Token <PC token>
#   powershell -ExecutionPolicy Bypass -File install-startup.ps1 -Token <PC token> -Server https://dos.argonar.co
#
# The task runs as SYSTEM at startup (no login required) with highest privileges,
# so the agent is up before/without anyone signing in.
param(
    [Parameter(Mandatory = $true)][string]$Token,
    [string]$Server = 'https://dos.argonar.co',
    [string]$Root = ''
)

$ErrorActionPreference = 'Stop'
$TaskName = 'rmdownloaderAgent'
$ExePath  = Join-Path $PSScriptRoot 'Agent.exe'

if (-not (Test-Path $ExePath)) {
    Write-Error "Agent.exe not found next to this script. Build it first (build.bat)."
    exit 1
}

# Remove any previous copy so re-running is safe.
try { Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction Stop } catch {}

$argline = "--server `"$Server`" --token `"$Token`""
if ($Root) { $argline += " --root `"$Root`"" }
$action   = New-ScheduledTaskAction -Execute $ExePath -Argument $argline -WorkingDirectory $PSScriptRoot
$trigger  = New-ScheduledTaskTrigger -AtStartup
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
            -StartWhenAvailable -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
            -ExecutionTimeLimit ([TimeSpan]::Zero)

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger `
    -Principal $principal -Settings $settings `
    -Description 'rmdownloader remote file manager agent' | Out-Null

Write-Host "Registered scheduled task '$TaskName' (runs Agent.exe at boot as SYSTEM)."
Write-Host "Starting it now..."
Start-ScheduledTask -TaskName $TaskName
Write-Host "Done. Check status with:  Get-ScheduledTask -TaskName $TaskName"
