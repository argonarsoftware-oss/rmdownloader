# Removes the boot auto-start task and stops the running agent.
# Run elevated:  powershell -ExecutionPolicy Bypass -File uninstall-startup.ps1
$TaskName = 'rmdownloaderAgent'
try { Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue } catch {}
try {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction Stop
    Write-Host "Removed scheduled task '$TaskName'."
} catch {
    Write-Host "Task '$TaskName' was not registered."
}
try { Get-Process Agent -ErrorAction SilentlyContinue | Stop-Process -Force } catch {}
