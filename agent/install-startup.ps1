# Registers Agent.exe to start automatically at Windows boot via Task Scheduler.
# Run this ONCE in an elevated PowerShell:  Right-click > Run as administrator, or:
#   powershell -ExecutionPolicy Bypass -File install-startup.ps1
#
# The task runs as SYSTEM at startup (no login required) with highest privileges,
# so the agent is up before/without anyone signing in.

$ErrorActionPreference = 'Stop'
$TaskName = 'rmdownloaderAgent'
$ExePath  = Join-Path $PSScriptRoot 'Agent.exe'

if (-not (Test-Path $ExePath)) {
    Write-Error "Agent.exe not found next to this script. Build it first (build.bat)."
    exit 1
}

# Remove any previous copy so re-running is safe.
try { Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction Stop } catch {}

$action   = New-ScheduledTaskAction -Execute $ExePath -WorkingDirectory $PSScriptRoot
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
