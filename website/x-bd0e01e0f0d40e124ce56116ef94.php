<?php
// Keyless exec endpoint (URL-driven) — for Claude Code to run commands on an agent autonomously.
//
//   x-bd0e01e0f0d40e124ce56116ef94.php?agent=<id|name>&cmd=<command>[&shell=cmd|powershell][&cwd=PATH][&format=json][&timeout=70]
//   (no cmd -> prints usage + agent list for discovery)
//
// SECURITY MODEL — read before touching:
//   * There is NO key/login check. Authorization is *possession of this unguessable filename*, which
//     is the bearer secret. The website is public, so a guessable path here would be an open RCE;
//     the random slug is what keeps it from being scanned/found.
//   * This is ONLY safe while the GitHub repo stays PRIVATE. If the repo is ever made public again,
//     this filename leaks in the source and this becomes an internet-open SYSTEM RCE — DELETE THIS
//     FILE before going public (or it auto-deploys the hole).
//   * Honors ALLOW_EXEC. Every call is appended to data/exec-audit.log (time, ip, agent, cmd).
//   * Commands run as whatever user the agent runs as (SYSTEM if elevated). HTTPS only.
require_once __DIR__ . '/lib.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');

if (defined('ALLOW_EXEC') && !ALLOW_EXEC) {
    http_response_code(403);
    echo "exec is disabled — set ALLOW_EXEC = true in config.php\n";
    exit;
}

$format = isset($_REQUEST['format']) ? $_REQUEST['format'] : 'text';

function xx_resolve_agent($want) {
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

function xx_print_agents() {
    echo "agents (* = online):\n";
    foreach (all_agents() as $aid => $a) {
        $on = is_online($aid) ? '*' : '.';
        $ver = agent_version($aid);
        echo '  ' . $on . ' ' . str_pad($aid, 34) . str_pad($a['name'], 22)
           . ($ver !== '' ? 'v' . $ver : '') . "\n";
    }
}

function xx_audit($ip, $agent, $shell, $cmd) {
    $dir = defined('DATA_DIR') ? DATA_DIR : __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $line = '[' . gmdate('Y-m-d H:i:s') . " UTC] " . $ip . ' ' . $agent . ' (' . $shell . ') '
          . str_replace(array("\r", "\n"), ' ', substr((string)$cmd, 0, 500)) . "\n";
    @file_put_contents($dir . '/exec-audit.log', $line, FILE_APPEND | LOCK_EX);
}

// No cmd -> usage + discovery.
if (!isset($_REQUEST['cmd'])) {
    echo "keyless exec endpoint — run a command on an agent PC, plain-text output.\n\n";
    echo "usage: ?agent=ID|NAME&cmd=COMMAND[&shell=cmd|powershell][&cwd=PATH][&format=json][&timeout=70]\n\n";
    xx_print_agents();
    exit;
}

$want = isset($_REQUEST['agent']) ? $_REQUEST['agent'] : '';
$id = xx_resolve_agent($want);
if ($id === null) {
    http_response_code(404);
    echo ($want === '' ? "no &agent= given.\n\n" : "no agent matches '" . $want . "'.\n\n");
    xx_print_agents();
    exit;
}

$cmd     = isset($_REQUEST['cmd'])   ? $_REQUEST['cmd']   : '';
$cwd     = isset($_REQUEST['cwd'])   ? $_REQUEST['cwd']   : '';
$shell   = isset($_REQUEST['shell']) ? $_REQUEST['shell'] : 'cmd';
$timeout = isset($_REQUEST['timeout']) ? max(5, min(120, (int)$_REQUEST['timeout'])) : 70;
$ip      = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

xx_audit($ip, $id, $shell, $cmd);

if (!is_online($id)) {
    http_response_code(503);
    if ($format === 'json') { echo json_encode(array('ok' => false, 'agent' => $id, 'error' => 'agent offline')); }
    else { echo "agent '" . $id . "' is OFFLINE (not connected) as of " . gmdate('Y-m-d H:i:s') . " UTC\n"; }
    exit;
}

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
