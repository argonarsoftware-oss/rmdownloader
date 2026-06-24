<?php
// Text/JSON view of the CDP / Chrome-Navigation monitor for automation (Claude Code, curl, scripts).
// The CDP equivalent of dns-text.php. Auth: session login OR API_KEY (?key= / X-Api-Key).
//
//   GET cdp-text.php?key=<API_KEY>[&agent=<id|name>][&dir=<folder>][&port=9222][&feed=40][&format=json]
//
// Reports the chnav monitor state on the chosen PC: folder, chnav.exe presence, running state,
// Chrome debug-port + version, open tabs, site rules (blt.txt) with an action breakdown, and the
// recent nav.log feed. Drives the agent's exec op — no PHP backend of its own (same as cdp.php).
require_once __DIR__ . '/lib.php';
app_session();

if (!api_authorized()) {
    http_response_code(401);
    header('Content-Type: text/plain');
    echo "Not authorized (login or valid API key required).\n";
    exit;
}
session_write_close();
@set_time_limit(50);

$asJson = (isset($_REQUEST['format']) && $_REQUEST['format'] === 'json');

// Resolve the target PC by exact id, case-insensitive id/name, or id-prefix (default: first).
function cdp_resolve_agent($want) {
    $agents = all_agents();
    if (empty($agents)) return null;
    if ($want !== '' && isset($agents[$want])) return $want;
    if ($want !== '') {
        foreach ($agents as $aid => $a) {
            if (strcasecmp($aid, $want) === 0) return $aid;
            if (isset($a['name']) && strcasecmp($a['name'], $want) === 0) return $aid;
        }
        foreach ($agents as $aid => $a) { if (stripos($aid, $want) === 0) return $aid; }
    }
    $ids = array_keys($agents);
    return $ids[0];
}

$id = cdp_resolve_agent(isset($_REQUEST['agent']) ? $_REQUEST['agent'] : '');
if ($id === null) {
    if ($asJson) { header('Content-Type: application/json'); echo json_encode(array('ok' => false, 'error' => 'No agents configured')); }
    else { header('Content-Type: text/plain'); echo "No agents configured\n"; }
    exit;
}
$agents = all_agents();
$name = isset($agents[$id]['name']) ? $agents[$id]['name'] : $id;
$online = is_online($id);

$feedN = isset($_REQUEST['feed']) ? (int)$_REQUEST['feed'] : 40;
if ($feedN < 0) $feedN = 0;
if ($feedN > 300) $feedN = 300;
$dirOverride = isset($_REQUEST['dir']) ? $_REQUEST['dir'] : '';
$port = isset($_REQUEST['port']) ? (int)$_REQUEST['port'] : (defined('CDP_PORT') ? CDP_PORT : 9222);
if ($port < 1 || $port > 65535) $port = 9222;

// Live box state via the agent (one batched PowerShell round-trip — mirrors assets/cdp.js).
$data = null;
if ($online) {
    $cmdId = enqueue_command($id, 'exec', array(
        'command' => cdp_status_script($dirOverride, $port, $feedN > 0 ? $feedN : 40),
        'shell'   => 'powershell'));
    $res = fetch_result($id, $cmdId, 40);
    if ($res && !empty($res['ok'])) {
        $data = json_decode(trim(isset($res['stdout']) ? $res['stdout'] : ''), true);
    }
}

$dir = ''; $hasexe = false; $running = false; $chrome = ''; $rules = ''; $log = '';
$targets = array();
if (is_array($data)) {
    $dir = isset($data['dir']) ? $data['dir'] : '';
    $hasexe = !empty($data['hasexe']);
    $running = !empty($data['running']);
    $chrome = isset($data['chrome']) ? $data['chrome'] : '';
    $port = isset($data['port']) ? (int)$data['port'] : $port;
    $rules = isset($data['rules']) ? $data['rules'] : '';
    $log = isset($data['log']) ? $data['log'] : '';
    if (isset($data['targets'])) {
        $targets = is_array($data['targets']) ? $data['targets'] : ($data['targets'] !== '' ? array($data['targets']) : array());
    }
}

// Parse blt.txt site rules + an action breakdown.
$ruleRows = array();
$counts = array('redirect' => 0, 'block' => 0, 'warn' => 0, 'replace' => 0);
foreach (preg_split('/\r?\n/', (string)$rules) as $ln) {
    $t = trim($ln);
    if ($t === '' || $t[0] === '#') continue;
    $p = preg_split('/\s+/', $t, 3);
    $dom = $p[0];
    $act = isset($p[1]) ? strtolower($p[1]) : 'block';
    if (!isset($counts[$act])) $act = 'block';
    $arg = isset($p[2]) ? $p[2] : '';
    $counts[$act]++;
    $ruleRows[] = array($dom, $act, $arg);
}

// Parse open tabs "url|title".
$tabs = array();
foreach ($targets as $tg) {
    $pp = explode('|', (string)$tg, 2);
    $tabs[] = array('url' => $pp[0], 'title' => isset($pp[1]) ? $pp[1] : '');
}

if ($asJson) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'ok' => true,
        'agent' => array('id' => $id, 'name' => $name, 'online' => $online, 'version' => agent_version($id)),
        'monitor' => array('running' => $running, 'folder' => $dir, 'chnav_exe' => $hasexe),
        'chrome' => array('version' => $chrome, 'debug_port' => $port, 'up' => ($chrome !== '')),
        'open_tabs' => $tabs,
        'rules' => $ruleRows, 'rule_counts' => $counts,
        'nav_feed' => array_values(array_filter(preg_split('/\r?\n/', (string)$log), 'strlen')),
    ));
    exit;
}

// ---- plain-text report ----
header('Content-Type: text/plain; charset=utf-8');
$L = array();
$L[] = 'rmdownloader CDP  —  ' . ($name !== '' ? $name : $id);
$L[] = 'agent id   : ' . $id;
$L[] = 'online     : ' . ($online ? 'yes' : 'NO (agent not connected)');
if (!$online) { echo implode("\n", $L) . "\n"; exit; }
$L[] = 'monitor    : ' . ($running ? 'running' : 'stopped')
     . '   chnav.exe: ' . ($hasexe ? 'present' : 'MISSING')
     . ($dir !== '' ? '   folder: ' . $dir : '');
$L[] = 'chrome     : ' . ($chrome !== '' ? $chrome . '   (debug port ' . $port . ' UP)' : '(debug port ' . $port . ' not open)');
$L[] = '';
$L[] = '== Open tabs (' . count($tabs) . ') ==';
if (empty($tabs)) { $L[] = '(none / debug port not open)'; }
else { foreach ($tabs as $t) { $L[] = '  ' . $t['url'] . ($t['title'] !== '' ? '   (' . $t['title'] . ')' : ''); } }
$L[] = '';
$L[] = '== Site rules (blt.txt): ' . count($ruleRows) . ' — '
     . 'redirect ' . $counts['redirect'] . ', block ' . $counts['block']
     . ', warn ' . $counts['warn'] . ', replace ' . $counts['replace'] . ' ==';
if (empty($ruleRows)) { $L[] = $hasexe ? '(no rules / blt.txt empty)' : '(chnav.exe not deployed here)'; }
else { foreach ($ruleRows as $r) { $L[] = '  ' . str_pad($r[0], 30) . ' ' . str_pad($r[1], 9) . ' ' . $r[2]; } }
$L[] = '';
$L[] = '== Recent navigation (nav.log) ==';
$feedLines = array_values(array_filter(preg_split('/\r?\n/', (string)$log), 'strlen'));
if (empty($feedLines)) { $L[] = $running ? '(no entries yet)' : '(monitor not running)'; }
else { foreach ($feedLines as $ln) $L[] = $ln; }
$L[] = '';
echo implode("\n", $L) . "\n";

// PowerShell that returns the chnav box state as one compact JSON blob — mirrors assets/cdp.js
// buildLoadScript(): folder auto-detect, exe/running, Chrome debug port + tabs, blt.txt, nav.log tail.
function cdp_status_script($override, $port, $tail) {
    $cdpDir = defined('CDP_DIR') ? CDP_DIR : 'C:\\Users\\Administrator\\Desktop\\chrome-nav';
    $port = (int)$port; if ($port < 1 || $port > 65535) $port = 9222;
    $tail = (int)$tail; if ($tail < 1) $tail = 40; if ($tail > 1000) $tail = 1000;
    $tpl = <<<'PS1'
$ErrorActionPreference='SilentlyContinue'
$dir=__OVERRIDE__
if (-not $dir) { $m=Get-Process chnav -ErrorAction SilentlyContinue | Where-Object { $_.Path } | Select-Object -First 1; if ($m) { $dir=Split-Path -Parent $m.Path } }
if (-not $dir) { $ag=Get-Process Agent,agentsvc -ErrorAction SilentlyContinue | Where-Object { $_.Path } | Select-Object -First 1; if ($ag) { $c=Split-Path -Parent $ag.Path; if (Test-Path -LiteralPath (Join-Path $c 'chnav.exe')) { $dir=$c } } }
if (-not $dir) { foreach ($c in @(__CDPDIR__,(Join-Path $env:USERPROFILE 'Desktop\chrome-nav'))) { if ($c -and (Test-Path -LiteralPath (Join-Path $c 'chnav.exe'))) { $dir=$c; break } } }
if (-not $dir) { $dir=__CDPDIR__ }
$exe=Join-Path $dir 'chnav.exe'
$running=@(Get-Process chnav -ErrorAction SilentlyContinue).Count -gt 0
$port=__PORT__
$chrome=''; $targets=@()
try { $v=Invoke-RestMethod -Uri ('http://127.0.0.1:{0}/json/version' -f $port) -TimeoutSec 2; $chrome=[string]$v.Browser; $tj=Invoke-RestMethod -Uri ('http://127.0.0.1:{0}/json' -f $port) -TimeoutSec 2; $targets=@($tj | Where-Object { $_.type -eq 'page' } | ForEach-Object { ([string]$_.url) + '|' + ([string]$_.title) }) } catch {}
$log=Join-Path $dir 'nav.log'
$tail=if (Test-Path -LiteralPath $log) { (Get-Content -LiteralPath $log -Tail __TAIL__ -Encoding utf8) -join "`n" } else { '' }
$rp=Join-Path $dir 'blt.txt'
$rules=if (Test-Path -LiteralPath $rp) { [IO.File]::ReadAllText($rp) } else { '' }
ConvertTo-Json ([ordered]@{ dir=$dir; hasexe=(Test-Path -LiteralPath $exe); running=$running; port=$port; chrome=$chrome; targets=$targets; rules=$rules; log=$tail }) -Compress -Depth 4
PS1;
    $q = function ($s) { return "'" . str_replace("'", "''", (string)$s) . "'"; };
    $tpl = str_replace('__OVERRIDE__', $q($override), $tpl);
    $tpl = str_replace('__CDPDIR__', $q($cdpDir), $tpl);
    $tpl = str_replace('__PORT__', (string)$port, $tpl);
    $tpl = str_replace('__TAIL__', (string)$tail, $tpl);
    return $tpl;
}
