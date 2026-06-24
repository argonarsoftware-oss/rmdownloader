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
// AUTH: API KEY ONLY (?key=<API_KEY> or header X-Api-Key). It deliberately does NOT accept an
// ambient browser session — command execution over GET + session auth would be a CSRF->RCE hole
// (a logged-in admin visiting a malicious page could be made to run commands). The key is a secret
// the attacker doesn't have, so requiring it closes that. Honors ALLOW_EXEC. Keep the site on HTTPS:
// the command and the key ride in the URL and land in the web server's access log.
require_once __DIR__ . '/lib.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');

// ---- key-only auth (no session fallback for exec) ----
function exec_key_ok() {
    if (!defined('API_KEY') || API_KEY === '') return false;
    $k = '';
    if (isset($_SERVER['HTTP_X_API_KEY'])) $k = $_SERVER['HTTP_X_API_KEY'];
    elseif (isset($_REQUEST['key'])) $k = $_REQUEST['key'];
    return ($k !== '' && hash_equals(API_KEY, $k));
}

if (!exec_key_ok()) {
    http_response_code(401);
    echo "unauthorized — pass ?key=<API_KEY> (set API_KEY in config.php; '' disables this endpoint)\n";
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
