<#
.SYNOPSIS
    Roll out a new Agent.exe to the fleet via the web API: canary first, then the rest.

.DESCRIPTION
    1. Lists agents from the server (api.php?action=agents).
    2. Pushes the new worker (the 'update' op) to ONE canary PC and waits until it
       reconnects reporting the target version (the supervisor swaps + health-checks +
       auto-rolls-back a bad build on its own).
    3. Only if the canary succeeds, rolls out to the remaining online PCs, verifying each.
    Offline PCs are skipped. A PC that doesn't reach the target version in time is reported
    as FAILED (its supervisor will have rolled it back, so it stays on the old version).

.PARAMETER Server     Base URL, e.g. https://dos.argonar.co
.PARAMETER ApiKey     API_KEY from the VPS config.php
.PARAMETER ExePath    Path to the NEW Agent.exe to deploy (e.g. .\Agent.exe)
.PARAMETER Version    The new build's AGENT_VERSION (e.g. 2026.06.22) - used to confirm the swap.
.PARAMETER CanaryId   Optional agent id to use as canary (default: first online agent).
.PARAMETER TimeoutSec How long to wait for a PC to come back on the new version (default 150).

.EXAMPLE
    .\rollout-update.ps1 -Server https://dos.argonar.co -ApiKey XXXX -ExePath .\Agent.exe -Version 2026.06.22
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$Server,
    [Parameter(Mandatory=$true)][string]$ApiKey,
    [Parameter(Mandatory=$true)][string]$ExePath,
    [Parameter(Mandatory=$true)][string]$Version,
    [string]$CanaryId = "",
    [int]$TimeoutSec = 150
)

$ErrorActionPreference = 'Stop'
$Server = $Server.TrimEnd('/')
if (-not (Test-Path -LiteralPath $ExePath)) { throw "ExePath not found: $ExePath" }

# Pre-flight: refuse an exe that isn't a Windows binary.
$bytes = [System.IO.File]::ReadAllBytes((Resolve-Path $ExePath))
if ($bytes.Length -lt 2 -or $bytes[0] -ne 0x4D -or $bytes[1] -ne 0x5A) { throw "ExePath is not a .exe (no MZ header)." }
$b64 = [Convert]::ToBase64String($bytes)
Write-Host ("New worker: {0}  ({1:N0} bytes)  target version {2}" -f $ExePath, $bytes.Length, $Version) -ForegroundColor Cyan

function Get-Agents {
    $r = Invoke-RestMethod -Method Get -Uri "$Server/api.php?action=agents&key=$ApiKey" -TimeoutSec 30
    if (-not $r.ok) { throw "agents list failed: $($r.error)" }
    return $r.agents
}

function Push-Update($id) {
    $body = @{ exe = $b64; version = $Version }
    return Invoke-RestMethod -Method Post -Uri "$Server/api.php?action=update&agent=$id&key=$ApiKey" -Body $body -TimeoutSec 90
}

# Wait until the agent is online AND reporting the target version.
function Wait-Updated($id, $timeout) {
    $deadline = (Get-Date).AddSeconds($timeout)
    $sawOffline = $false
    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Seconds 3
        $a = (Get-Agents) | Where-Object { $_.id -eq $id }
        if (-not $a) { continue }
        if (-not $a.online) { $sawOffline = $true; continue }   # mid-swap blip
        if ($a.version -eq $Version) { return $true }
    }
    return $false
}

function Rollout-One($id, $name) {
    Write-Host ("  -> {0} ({1}): pushing..." -f $name, $id) -NoNewline
    try {
        $res = Push-Update $id
        if (-not $res.ok) { Write-Host (" REJECTED: " + $res.error) -ForegroundColor Red; return $false }
    } catch {
        Write-Host (" SEND FAILED: " + $_.Exception.Message) -ForegroundColor Red; return $false
    }
    Write-Host " staged, waiting for reconnect..." -NoNewline
    if (Wait-Updated $id $TimeoutSec) {
        Write-Host (" OK (v{0})" -f $Version) -ForegroundColor Green; return $true
    }
    Write-Host " FAILED (did not reach target version; supervisor will have rolled it back)" -ForegroundColor Red
    return $false
}

# ---- discover ----
$agents = Get-Agents
$online = @($agents | Where-Object { $_.online })
$offline = @($agents | Where-Object { -not $_.online })
Write-Host ("Fleet: {0} online, {1} offline." -f $online.Count, $offline.Count)
if ($online.Count -eq 0) { throw "No online agents to update." }
if ($offline.Count) { Write-Host ("  (skipping offline: " + (($offline | ForEach-Object { $_.name }) -join ", ") + ")") -ForegroundColor DarkGray }

$already = @($online | Where-Object { $_.version -eq $Version })
if ($already.Count) { Write-Host ("  ({0} already on {1})" -f $already.Count, $Version) -ForegroundColor DarkGray }
$targets = @($online | Where-Object { $_.version -ne $Version })
if ($targets.Count -eq 0) { Write-Host "Everything online is already on the target version. Nothing to do." -ForegroundColor Green; return }

# ---- canary ----
$canary = if ($CanaryId) { $targets | Where-Object { $_.id -eq $CanaryId } | Select-Object -First 1 } else { $targets[0] }
if (-not $canary) { throw "Canary '$CanaryId' not found among online targets." }
Write-Host ("`n=== CANARY: {0} ===" -f $canary.name) -ForegroundColor Yellow
if (-not (Rollout-One $canary.id $canary.name)) {
    Write-Host "`nCanary failed - ABORTING fleet rollout. Investigate before retrying." -ForegroundColor Red
    return
}

# ---- fleet ----
$rest = @($targets | Where-Object { $_.id -ne $canary.id })
Write-Host ("`n=== FLEET: {0} more PC(s) ===" -f $rest.Count) -ForegroundColor Yellow
$ok = @($canary.name); $fail = @()
foreach ($a in $rest) {
    if (Rollout-One $a.id $a.name) { $ok += $a.name } else { $fail += $a.name }
}

# ---- summary ----
Write-Host "`n================ SUMMARY ================" -ForegroundColor Cyan
Write-Host ("Updated to {0}: {1}" -f $Version, $ok.Count) -ForegroundColor Green
if ($fail.Count) { Write-Host ("Failed/rolled back: {0} -> {1}" -f $fail.Count, ($fail -join ", ")) -ForegroundColor Red }
if ($offline.Count) { Write-Host ("Skipped (offline): {0}" -f $offline.Count) -ForegroundColor DarkGray }
Write-Host "Re-run later to catch PCs that were offline or rolled back." -ForegroundColor DarkGray
