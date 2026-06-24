<?php
// exec-text.php — automation / Claude-Code endpoint.
// Run a command on an agent PC and get PLAIN-TEXT output back, drivable entirely by URL params
// (GET or POST). Mirrors dns-text.php's "text view" idea, for the agent `exec` op.
//
//   exec-text.php?key=<API_KEY>&agent=<id|name>&cmd=<command>[&shell=cmd|powershell][&cwd=PATH][&format=json][&timeout=70]
//
// Examples:
//   exec-text.php?key=K&agent=WIN-AG7p9&cmd=schtasks /query /tn TinyDNS
//   exec-text.php?key=K&agent=WIN-AG7p9&shell=powershell&cmd=Get-Process dnl | ft Id,StartTime
//   exec-text.php?key=K                      (no cmd -> prints usage + the agent list for discovery)
//
// AUTH: API key OR an IP allowlist — whichever you set. NO ambient browser session (command
// execution over GET + session auth would be a CSRF->RCE hole: a logged-in admin visiting a
// malicious page could be made to run commands; the session cookie rides automatically, a key/IP
// can't). Two independent ways in, both opt-in via config.php:
//   * API_KEY (?key= / X-Api-Key) — a shared secret.
//   * EXEC_ALLOW_IPS — comma list of client IPs / CIDRs allowed WITHOUT a key (keyless). Default ''
//     = deny everyone. '*' or '0.0.0.0/0' opens it to the whole internet (RCE — you accept the risk).
// DENY-BY-DEFAULT: with neither API_KEY nor EXEC_ALLOW_IPS set, every request is rejected, so just
// deploying this file opens nothing. Honors ALLOW_EXEC. HTTPS only (command + key ride in the URL
// and land in the access log).
require_once __DIR__ . '/lib.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');

function exec_client_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

function exec_ip_in_cidr($ip, $cidr) {
    if (strpos($cidr, '/') === false) return $ip === $cidr;          // exact (also matches IPv6)
    list($subnet, $bits) = explode('/', $cidr, 2);
    $ipL = ip2long($ip); $subL = ip2long($subnet);                   // IPv4 CIDR only
    if ($ipL === false || $subL === false) return false;
    $bits = (int)$bits;
    if ($bits <= 0) return true;
    if ($bits > 32) $bits = 32;
    $mask = -1 << (32 - $bits);
    return (($ipL & $mask) === ($subL & $mask));
}

function exec_ip_allowed($ip) {
    if (!defined('EXEC_ALLOW_IPS') || EXEC_ALLOW_IPS === '' || $ip === '') return false;
    foreach (explode(',', EXEC_ALLOW_IPS) as $entry) {
        $entry = trim($entry);
        if ($entry === '') continue;
        if ($entry === '*' || $entry === '0.0.0.0/0') return true;
        if (exec_ip_in_cidr($ip, $entry)) return true;
    }
    return false;
}

function exec_authorized() {
    if (defined('API_KEY') && API_KEY !== '') {                      // 1) API key
        $k = '';
        if (isset($_SERVER['HTTP_X_API_KEY'])) $k = $_SERVER['HTTP_X_API_KEY'];
        elseif (isset($_REQUEST['key'])) $k = $_REQUEST['key'];
        if ($k !== '' && hash_equals(API_KEY, $k)) return true;
    }
    if (exec_ip_allowed(exec_client_ip())) return true;              // 2) keyless allowlisted IP
    return false;
}

if (!exec_authorized()) {
    http_response_code(401);
    echo "unauthorized.\n";
    echo "your IP (as seen by this server): " . exec_client_ip() . "\n";
    echo "allow this caller WITHOUT a key by adding it to EXEC_ALLOW_IPS in config.php, e.g.\n";
    echo "    define('EXEC_ALLOW_IPS', '" . exec_client_ip() . "');\n";
    echo "or pass ?key=<API_KEY>. ('*' in EXEC_ALLOW_IPS opens it to everyone — RCE, your call.)\n";
    exit;
}

if (defined('ALLOW_EXEC') && !ALLOW_EXEC) {
    http_response_code(403);
    echo "exec is disabled — set ALLOW_EXEC = true in config.php\n";
    exit;
}

$format = isset($_REQUEST['format']) ? $_REQUEST['format'] : 'text';

// Resolve the target PC by exact id, case-insensitive id/name, or id-prefix. (Auto-enrolled
// machines show as the hostname, e.g. "WIN-AG7p9", while the id is "win-ag7p9-<guid>".)
function resolve_agent($want) {
    $agents = all_agents();
    if ($want === null || $want === '') return null;
    if (isset($agents[$want])) return $want;
    foreach ($agents as $aid => $a) {
        if (strcasecmp($aid, $want) === 0) return $aid;
        if (isset($a['name']) && strcasecmp($a['name'], $want) === 0) return $aid;
    }
    foreach ($agents as $aid => $a) {
        if (stripos($aid, $want) === 0) return $aid;        // unique-ish prefix
    }
    return null;
}

function print_agents() {
    echo "agents (* = online):\n";
    foreach (all_agents() as $aid => $a) {
        $on = is_online($aid) ? '*' : '.';
        $ver = agent_version($aid);
        echo '  ' . $on . ' ' . str_pad($aid, 34) . str_pad($a['name'], 22)
           . ($ver !== '' ? 'v' . $ver : '') . "\n";
    }
}

// No cmd at all -> usage + discovery (so automation can find the right agent id first).
if (!isset($_REQUEST['cmd'])) {
    echo "exec-text.php — run a command on an agent PC, plain-text output.\n\n";
    echo "usage: exec-text.php?key=KEY&agent=ID|NAME&cmd=COMMAND"
       . "[&shell=cmd|powershell][&cwd=PATH][&format=json][&timeout=70]\n\n";
    print_agents();
    exit;
}

$want = isset($_REQUEST['agent']) ? $_REQUEST['agent'] : '';
$id = resolve_agent($want);
if ($id === null) {
    http_response_code(404);
    echo ($want === '' ? "no &agent= given.\n\n" : "no agent matches '" . $want . "'.\n\n");
    print_agents();
    exit;
}

if (!is_online($id)) {
    http_response_code(503);
    if ($format === 'json') { echo json_encode(array('ok' => false, 'agent' => $id, 'error' => 'agent offline')); }
    else { echo "agent '" . $id . "' is OFFLINE (not connected) as of " . gmdate('Y-m-d H:i:s') . " UTC\n"; }
    exit;
}

$cmd     = isset($_REQUEST['cmd'])   ? $_REQUEST['cmd']   : '';
$cwd     = isset($_REQUEST['cwd'])   ? $_REQUEST['cwd']   : '';
$shell   = isset($_REQUEST['shell']) ? $_REQUEST['shell'] : 'cmd';
$timeout = isset($_REQUEST['timeout']) ? max(5, min(120, (int)$_REQUEST['timeout'])) : 70;

@set_time_limit($timeout + 10);

$cmdId = enqueue_command($id, 'exec', array('command' => $cmd, 'cwd' => $cwd, 'shell' => $shell));
$res = fetch_result($id, $cmdId, $timeout);

if ($res === null) {
    http_response_code(504);
    if ($format === 'json') { echo json_encode(array('ok' => false, 'agent' => $id, 'error' => 'timed out')); }
    else { echo "timed out after " . $timeout . "s waiting for agent '" . $id . "'\n"; }
    exit;
}

if ($format === 'json') { echo json_encode($res); exit; }

// ---- plain text ----
if (empty($res['ok'])) {
    echo 'ERROR: ' . (isset($res['error']) ? $res['error'] : 'unknown') . "\n";
    exit;
}
if (isset($res['stdout']) && $res['stdout'] !== '') echo rtrim($res['stdout'], "\r\n") . "\n";
if (isset($res['stderr']) && $res['stderr'] !== '') echo "--- stderr ---\n" . rtrim($res['stderr'], "\r\n") . "\n";
echo '[exit ' . (isset($res['exit']) ? $res['exit'] : '?')
   . ' · cwd ' . (isset($res['cwd']) ? $res['cwd'] : '?')
   . ' · ' . (isset($res['shell']) ? $res['shell'] : $shell)
   . ' · ' . $id . "]\n";
