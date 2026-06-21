<#
.SYNOPSIS
    Rebuild records.txt and blocklist.txt for TinyDNS from queries.log.

.DESCRIPTION
    The query log records every resolved name and its disposition, so your custom
    routes (LOCAL / redirect) and blocks (BLOCKED) can be reconstructed from it.
    By DEFAULT this writes to *.recovered.txt so you can review before using them.
    Pass -Apply to replace the real files (the originals, if any, are backed up to
    *.bak first).

.PARAMETER Dir
    Folder holding queries.log (and where records/blocklist live). Default C:\Viewers.

.PARAMETER Apply
    Write straight to records.txt / blocklist.txt (after backing up to .bak).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File recover-config.ps1
    powershell -ExecutionPolicy Bypass -File recover-config.ps1 -Dir C:\Viewers -Apply

.NOTES
    Limitations: the log stores the *queried* name, so wildcard rules (*.x) come back
    as the individual subdomains that were actually hit, not the original "*." form.
    Review the output and re-add any wildcards by hand.
#>
[CmdletBinding()]
param(
    [string]$Dir = 'C:\Viewers',
    [switch]$Apply
)

$ErrorActionPreference = 'Stop'
$log = Join-Path $Dir 'queries.log'
if (-not (Test-Path -LiteralPath $log)) {
    Write-Host "queries.log not found at $log" -ForegroundColor Red
    Write-Host "Pass -Dir <folder> with the correct path." -ForegroundColor Yellow
    exit 1
}

$routes  = [ordered]@{}          # domain -> target (ip or redirect domain)
$blocked = New-Object System.Collections.Generic.HashSet[string]

foreach ($line in Get-Content -LiteralPath $log -Encoding UTF8) {
    $c = $line -split "`t"
    if ($c.Count -lt 5) { continue }
    $domain = $c[2].Trim()
    $disp   = $c[4].Trim()
    if ($domain -eq '') { continue }

    if ($disp -match '^LOCAL\s+(.+?)\s+<-\s+(.+)$') {
        # redirect: keep the original target domain, not the resolved IP
        $routes[$domain] = $matches[2].Trim()
    }
    elseif ($disp -match '^LOCAL\s+(\d{1,3}(\.\d{1,3}){3})$') {
        # direct domain -> IP
        $routes[$domain] = $matches[1]
    }
    elseif ($disp -eq 'BLOCKED') {
        [void]$blocked.Add($domain)
    }
    # 'LOCAL (no AAAA)', 'FWD', 'NXDOMAIN' -> not a config entry, skip
}

# ---- format records.txt (aligned columns) ----
$recLines = New-Object System.Collections.Generic.List[string]
$recLines.Add('# Recovered from queries.log on ' + (Get-Date -Format 'yyyy-MM-dd HH:mm') + ' - review and re-add any *. wildcards.')
if ($routes.Count) {
    $pad = ([int](($routes.Keys | Measure-Object -Maximum -Property Length).Maximum)) + 3
    if ($pad -lt 20) { $pad = 20 }
    foreach ($d in ($routes.Keys | Sort-Object)) {
        $recLines.Add($d.PadRight($pad) + $routes[$d])
    }
}

# ---- format blocklist.txt ----
$blkLines = New-Object System.Collections.Generic.List[string]
$blkLines.Add('# Recovered from queries.log on ' + (Get-Date -Format 'yyyy-MM-dd HH:mm') + ' - review and collapse subdomains into *. rules if you like.')
foreach ($b in ($blocked | Sort-Object)) { $blkLines.Add($b) }

# ---- write ----
function Write-File($path, $lines) {
    if ($Apply -and (Test-Path -LiteralPath $path)) {
        Copy-Item -LiteralPath $path -Destination ($path + '.bak') -Force
        Write-Host ("  backed up existing -> " + (Split-Path -Leaf $path) + ".bak") -ForegroundColor DarkGray
    }
    # UTF-8 WITHOUT BOM — a BOM would make the server misparse the first line
    $enc = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllLines($path, $lines, $enc)
    Write-Host ("  wrote " + $path) -ForegroundColor Green
}

$recOut = if ($Apply) { Join-Path $Dir 'records.txt' }   else { Join-Path $Dir 'records.recovered.txt' }
$blkOut = if ($Apply) { Join-Path $Dir 'blocklist.txt' } else { Join-Path $Dir 'blocklist.recovered.txt' }

Write-Host ""
Write-Host ("Recovered {0} custom route(s) and {1} blocked domain(s) from the log." -f $routes.Count, $blocked.Count) -ForegroundColor Cyan
Write-File $recOut $recLines
Write-File $blkOut $blkLines
Write-Host ""
if ($Apply) {
    Write-Host "Applied to the live files. Restart/reload happens automatically (hot-reload)." -ForegroundColor Cyan
} else {
    Write-Host "Review the .recovered.txt files. To use them, either copy their content into" -ForegroundColor Yellow
    Write-Host "the DNS page editors and Save, or re-run with -Apply to write the real files." -ForegroundColor Yellow
}
