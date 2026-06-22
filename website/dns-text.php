<?php
// Text/JSON view of the DNS manager for automation (Claude Code, curl, scripts).
// Auth: session login OR API_KEY (?key=<API_KEY> or X-Api-Key) — same as api.php.
//
//   GET dns-text.php?key=<API_KEY>[&agent=<id>][&days=7][&queries=20][&format=json][&sync=1]
//
// Returns a plain-text DNS report by default (service status, this machine's IPs, top sites
// from MySQL, recent raw queries, blocklist + records). ?format=json returns the same as JSON.
require_once __DIR__ . '/dns-sync-core.php';
app_session();

if (!api_authorized()) {
    http_response_code(401);
    header('Content-Type: text/plain');
    echo "Not authorized (login or valid API key required).\n";
    exit;
}
session_write_close();
@set_time_limit(45);

$asJson = (isset($_REQUEST['format']) && $_REQUEST['format'] === 'json');

$id = current_agent_id();
if ($id === null) {
    out_err($asJson, 'No agents configured');
    exit;
}
$name = '';
$agents = all_agents();
if (isset($agents[$id])) $name = $agents[$id]['name'];
$online = is_online($id);
$days = isset($_REQUEST['days']) ? (int)$_REQUEST['days'] : 7;
$nq = isset($_REQUEST['queries']) ? (int)$_REQUEST['queries'] : 20;
if ($nq < 0) $nq = 0; if ($nq > 200) $nq = 200;

// Optionally ingest fresh log lines first (default on) so stats/queries are current.
$doSync = !isset($_REQUEST['sync']) || $_REQUEST['sync'] !== '0';
if ($doSync && $online) { try { dns_sync_agent($id, ''); } catch (Exception $e) { } }

// Live box state via the agent (status / IPs / blocklist / records).
$box = array('status' => 'unknown', 'dir' => '', 'ips' => array(), 'block' => '', 'rec' => '', 'upstream' => '');
if ($online) {
    $res = dns_agent_exec($id, dns_status_script());
    if ($res && !empty($res['ok'])) {
        $j = json_decode(trim(isset($res['stdout']) ? $res['stdout'] : ''), true);
        if (is_array($j)) {
            $box['status'] = isset($j['status']) ? $j['status'] : 'unknown';
            $box['dir'] = isset($j['dir']) ? $j['dir'] : '';
            $box['ips'] = isset($j['ips']) && is_array($j['ips']) ? $j['ips'] : array();
            $box['block'] = isset($j['block']) ? $j['block'] : '';
            $box['rec'] = isset($j['rec']) ? $j['rec'] : '';
            $box['upstream'] = isset($j['upstream']) ? $j['upstream'] : '';
        }
    }
}

// Stats + recent queries from MySQL.
$top = array(); $totalVisits = 0; $recent = array(); $dbOn = false;
$gambling = array('count' => 0, 'visits' => 0, 'sites' => array());
$pdo = db();
if ($pdo) {
    $dbOn = true;
    try {
        $w = 'agent_id = ?'; $a = array($id);
        if ($days > 0) { $w .= ' AND day >= (CURDATE() - INTERVAL ' . (int)$days . ' DAY)'; }
        // fold subdomains into registrable domains, flag gambling
        $st = $pdo->prepare('SELECT domain, SUM(hits) h FROM dns_stats_daily WHERE ' . $w . ' GROUP BY domain ORDER BY h DESC LIMIT 10000');
        $st->execute($a);
        $map = array();
        while ($r = $st->fetch()) { $b = registrable_domain($r['domain']); if (!isset($map[$b])) $map[$b] = 0; $map[$b] += (int)$r['h']; }
        arsort($map);
        $i = 0; $gSites = array();
        foreach ($map as $dom => $h) {
            $g = is_gambling_domain($dom) ? 1 : 0;
            if ($g) { $gambling['visits'] += $h; $gSites[$dom] = $h; }
            if ($i < 30) { $top[] = array($dom, $h, $g); $i++; }
        }
        arsort($gSites);
        $gambling['count'] = count($gSites);
        $j = 0; foreach ($gSites as $d => $h) { $gambling['sites'][] = array($d, $h); if (++$j >= 20) break; }
        $st = $pdo->prepare('SELECT COALESCE(SUM(hits),0) FROM dns_stats_daily WHERE ' . $w);
        $st->execute($a);
        $totalVisits = (int)$st->fetchColumn();
    } catch (Exception $e) { }
    if ($nq > 0) {
        try {
            $st = $pdo->prepare('SELECT ts, client, domain, qtype, disposition FROM dns_queries WHERE agent_id = ? ORDER BY id DESC LIMIT ' . $nq);
            $st->execute(array($id));
            while ($r = $st->fetch()) $recent[] = $r;
        } catch (Exception $e) { }
    }
}

if ($asJson) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'ok' => true,
        'agent' => array('id' => $id, 'name' => $name, 'online' => $online, 'version' => agent_version($id)),
        'service' => $box['status'], 'dns_folder' => $box['dir'], 'ips' => $box['ips'],
        'upstream' => $box['upstream'],
        'db' => $dbOn, 'days' => $days, 'total_visits' => $totalVisits,
        'top_sites' => $top, 'gambling' => $gambling, 'recent_queries' => $recent,
        'blocklist' => $box['block'], 'records' => $box['rec'],
    ));
    exit;
}

// ---- plain-text report ----
header('Content-Type: text/plain; charset=utf-8');
$L = array();
$L[] = 'rmdownloader DNS  —  ' . ($name !== '' ? $name : $id);
$L[] = 'agent id   : ' . $id;
$L[] = 'online     : ' . ($online ? 'yes' : 'NO (agent not connected)');
$L[] = 'dns service: ' . $box['status'] . ($box['dir'] !== '' ? '   folder: ' . $box['dir'] : '');
$L[] = 'upstream   : ' . ($box['upstream'] !== '' ? $box['upstream'] : '185.228.168.10,185.228.168.11 (CleanBrowsing, default)');
if (!empty($box['ips'])) {
    $ipstr = array();
    foreach ($box['ips'] as $line) { $p = explode('|', $line); $ipstr[] = $p[0] . (isset($p[1]) && $p[1] !== '' ? ' (' . $p[1] . ')' : ''); }
    $L[] = 'this PC IPs: ' . implode(', ', $ipstr);
}
$L[] = '';
if ($dbOn && $gambling['count'] > 0) {
    $gnames = array();
    foreach ($gambling['sites'] as $s) $gnames[] = $s[0];
    $L[] = '!! GAMBLING ALERT: ' . $gambling['count'] . ' site(s), ' . number_format($gambling['visits'])
         . ' visits — ' . implode(', ', array_slice($gnames, 0, 10)) . (count($gnames) > 10 ? ', …' : '');
    $L[] = '';
}
$L[] = '== Top sites (' . ($days > 0 ? 'last ' . $days . ' days' : 'all time') . ', subdomains grouped) — ' . number_format($totalVisits) . ' total visits ==';
if (!$dbOn) {
    $L[] = '(MySQL not configured — no stats)';
} elseif (empty($top)) {
    $L[] = '(no data yet)';
} else {
    $rank = 0;
    foreach ($top as $t) { $rank++; $L[] = str_pad($rank, 3, ' ', STR_PAD_LEFT) . '. ' . str_pad(number_format($t[1]), 8, ' ', STR_PAD_LEFT) . '  ' . $t[0] . (!empty($t[2]) ? '   [GAMBLING]' : ''); }
}
$L[] = '';
$L[] = '== Recent queries (' . count($recent) . ') ==';
if (empty($recent)) {
    $L[] = $dbOn ? '(none)' : '(MySQL not configured)';
} else {
    foreach ($recent as $r) {
        $L[] = $r['ts'] . '  ' . str_pad($r['client'], 15, ' ') . '  ' . str_pad($r['qtype'], 5, ' ') . ' ' . str_pad($r['disposition'], 14, ' ') . '  ' . $r['domain'];
    }
}
$L[] = '';
$L[] = '== Blocked domains (blocklist.txt) ==';
$L[] = trim($box['block']) !== '' ? rtrim($box['block']) : ($online ? '(none)' : '(agent offline)');
$L[] = '';
$L[] = '== Custom routing (records.txt) ==';
$L[] = trim($box['rec']) !== '' ? rtrim($box['rec']) : ($online ? '(none)' : '(agent offline)');
$L[] = '';
echo implode("\n", $L) . "\n";

// ---- helpers ----
function out_err($asJson, $msg) {
    if ($asJson) { header('Content-Type: application/json'); echo json_encode(array('ok' => false, 'error' => $msg)); }
    else { header('Content-Type: text/plain'); echo $msg . "\n"; }
}

// PowerShell that returns the DNS box's live state (folder, blocklist, records, IPs, task status)
// as one compact JSON blob — mirrors the bundle assets/dns.js loads, minus the query log.
function dns_status_script() {
    $task = defined('DNS_TASK') ? DNS_TASK : 'TinyDNS';
    $dnsdir = defined('DNS_DIR') ? DNS_DIR : '';
    $tpl = <<<'PS1'
$ErrorActionPreference='SilentlyContinue'
$task=__TASK__
$override=''
if ($override) { $dir=$override } else { $t=Get-ScheduledTask -TaskName $task; if ($t) { $dir=Split-Path -Parent $t.Actions.Execute } }
if (-not $dir) { $dir=__DNSDIR__ }
$bp=Join-Path $dir 'blocklist.txt'
$rp=Join-Path $dir 'records.txt'
$block=if (Test-Path -LiteralPath $bp) { [IO.File]::ReadAllText($bp) } else { '' }
$rec=if (Test-Path -LiteralPath $rp) { [IO.File]::ReadAllText($rp) } else { '' }
$ips=@(Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } | ForEach-Object { $_.IPAddress + '|' + $_.InterfaceAlias })
if (-not $t) { $t=Get-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue }
$exe=if ($t) { Split-Path -Leaf $t.Actions.Execute } else { 'dnl.exe' }
$pname=[IO.Path]::GetFileNameWithoutExtension($exe)
$alive=@(Get-Process -Name $pname -ErrorAction SilentlyContinue).Count -gt 0
$st='unknown'
$q=schtasks /query /tn $task /fo list 2>$null
if ($alive) { $st='running' } elseif ($LASTEXITCODE -eq 0) { $st='stopped' } else { $st='notask' }
$ua=if ($t -and $t.Actions -and $t.Actions[0].Arguments) { [string]$t.Actions[0].Arguments } else { '' }
$up=''; if ($ua -match '--upstream\s+(\S+)') { $up=$matches[1] }
ConvertTo-Json ([ordered]@{ dir=$dir; block=$block; rec=$rec; ips=$ips; status=$st; upstream=$up }) -Compress -Depth 3
PS1;
    $q = function ($s) { return "'" . str_replace("'", "''", (string)$s) . "'"; };
    $tpl = str_replace('__TASK__', $q($task), $tpl);
    $tpl = str_replace('__DNSDIR__', $q($dnsdir), $tpl);
    return $tpl;
}
