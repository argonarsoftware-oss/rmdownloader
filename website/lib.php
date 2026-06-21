<?php
// Shared helpers: session/auth, agent lookup, and the file-based command queue.
//
// Queue layout (under DATA_DIR):
//   data/<agentId>/cmd/<cmdId>.json   pending command (written by api.php, claimed by agent)
//   data/<agentId>/res/<cmdId>.json   result          (written by agent.php, read by api.php)
//   data/<agentId>/online             unix timestamp of the agent's last poll
require_once __DIR__ . '/config.php';

// ---- session / auth (browser only; the agent endpoint never starts a session) ----

function app_session() {
    if (session_status() === PHP_SESSION_NONE) session_start();
}

function is_logged_in() {
    if (empty($_SESSION['authed'])) return false;
    if (isset($_SESSION['last']) && (time() - $_SESSION['last']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        return false;
    }
    $_SESSION['last'] = time();
    return true;
}

function require_login() {
    app_session();
    if (!is_logged_in()) { header('Location: login.php'); exit; }
}

// Authorize an API request: a logged-in browser session OR a valid API key
// (?key=<API_KEY> or header X-Api-Key). Lets automation/Claude Code drive api.php.
function api_authorized() {
    if (is_logged_in()) return true;
    if (defined('API_KEY') && API_KEY !== '') {
        $k = '';
        if (isset($_SERVER['HTTP_X_API_KEY'])) $k = $_SERVER['HTTP_X_API_KEY'];
        elseif (isset($_REQUEST['key'])) $k = $_REQUEST['key'];
        if ($k !== '' && hash_equals(API_KEY, $k)) return true;
    }
    return false;
}

// ---- agent identity ----

// All known PCs = static rm_agents() merged with auto-enrolled agents (registry).
function all_agents() {
    $out = array();
    foreach (rm_agents() as $id => $a) {
        $out[$id] = array('name' => $a['name']);
    }
    foreach (load_registry() as $id => $a) {
        $out[$id] = array('name' => isset($a['name']) ? $a['name'] : $id);
    }
    return $out;
}

// Browser side: which PC is targeted (?agent=<id>), validated against the known list.
function current_agent_id() {
    $agents = all_agents();
    if (empty($agents)) return null;
    $id = isset($_REQUEST['agent']) ? $_REQUEST['agent'] : null;
    if ($id === null || !isset($agents[$id])) {
        $ids = array_keys($agents);
        $id = $ids[0];
    }
    return $id;
}

// Agent side: map an incoming token to a static agent id (per-PC tokens, if any).
function agent_id_by_token($token) {
    if ($token === '' || $token === null) return null;
    foreach (rm_agents() as $id => $a) {
        if (isset($a['token']) && hash_equals($a['token'], $token)) return $id;
    }
    return null;
}

function sanitize_id($id) {
    $id = preg_replace('/[^a-z0-9_-]/i', '', (string)$id);
    return substr($id, 0, 80);
}

// ---- auto-enroll registry (data/agents.json) ----

function registry_path() { return DATA_DIR . '/agents.json'; }

function load_registry() {
    $f = registry_path();
    if (!is_file($f)) return array();
    $d = json_decode(@file_get_contents($f), true);
    return is_array($d) ? $d : array();
}

// Add/update an agent that authenticated with the shared ENROLL_KEY.
function register_agent($id, $name) {
    ensure_dir(DATA_DIR);
    $fp = @fopen(registry_path(), 'c+');
    if (!$fp) return;
    @flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $reg = json_decode($raw, true);
    if (!is_array($reg)) $reg = array();
    if (!isset($reg[$id])) $reg[$id] = array('added' => time());
    $reg[$id]['name'] = ($name !== '') ? $name : $id;
    $reg[$id]['seen'] = time();
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($reg)); fflush($fp);
    @flock($fp, LOCK_UN);
    fclose($fp);
}

// Remove an auto-enrolled agent from the registry and delete its queue dir.
function unregister_agent($id) {
    $fp = @fopen(registry_path(), 'c+');
    if ($fp) {
        @flock($fp, LOCK_EX);
        $reg = json_decode(stream_get_contents($fp), true);
        if (!is_array($reg)) $reg = array();
        unset($reg[$id]);
        ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($reg)); fflush($fp);
        @flock($fp, LOCK_UN);
        fclose($fp);
    }
    $dir = agent_dir($id);
    if (is_dir($dir)) rrmdir($dir);
}

function rrmdir($dir) {
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') continue;
        $p = $dir . '/' . $e;
        if (is_dir($p)) rrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}

// ---- file queue ----

function agent_dir($id) {
    return DATA_DIR . '/' . preg_replace('/[^a-z0-9_-]/i', '', $id);
}

function ensure_dir($d) {
    if (!is_dir($d)) @mkdir($d, 0775, true);
}

function atomic_write($path, $data) {
    $tmp = $path . '.tmp' . getmypid() . mt_rand();
    file_put_contents($tmp, $data);
    @rename($tmp, $path);
}

function gen_id() {
    return bin2hex(random_bytes(8));
}

// Browser -> queue a command for the agent. Returns the command id.
function enqueue_command($id, $op, $args = array()) {
    $cmd = array_merge(array('id' => gen_id(), 'op' => $op, 'ts' => time()), $args);
    $dir = agent_dir($id) . '/cmd';
    ensure_dir($dir);
    atomic_write($dir . '/' . $cmd['id'] . '.json', json_encode($cmd));
    return $cmd['id'];
}

// Agent -> take all pending commands (and remove them from the queue).
function claim_commands($id) {
    $dir = agent_dir($id) . '/cmd';
    ensure_dir($dir);
    $out = array();
    foreach (glob($dir . '/*.json') as $f) {
        $c = json_decode(@file_get_contents($f), true);
        @unlink($f);
        if ($c) $out[] = $c;
    }
    return $out;
}

// Agent -> store a command's result.
function store_result($id, $cmdId, $payload) {
    $cmdId = preg_replace('/[^a-f0-9]/i', '', $cmdId);
    if ($cmdId === '') return;
    $dir = agent_dir($id) . '/res';
    ensure_dir($dir);
    atomic_write($dir . '/' . $cmdId . '.json', json_encode($payload));
}

// Browser -> wait up to $timeout seconds for a result, then consume it.
function fetch_result($id, $cmdId, $timeout = 30) {
    $cmdId = preg_replace('/[^a-f0-9]/i', '', $cmdId);
    $f = agent_dir($id) . '/res/' . $cmdId . '.json';
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        if (is_file($f)) {
            $d = json_decode(@file_get_contents($f), true);
            @unlink($f);
            return $d;
        }
        usleep(200000); // 0.2s
    }
    return null;
}

function touch_online($id) {
    $dir = agent_dir($id);
    ensure_dir($dir);
    @file_put_contents($dir . '/online', time());
}

function is_online($id) {
    $f = agent_dir($id) . '/online';
    if (!is_file($f)) return false;
    return (time() - (int)@file_get_contents($f)) < 60;
}

// Remove stale queue files so a long-offline agent doesn't accumulate junk.
function cleanup_stale($id, $maxAge = 120) {
    foreach (array('/cmd', '/res') as $sub) {
        $dir = agent_dir($id) . $sub;
        if (!is_dir($dir)) continue;
        foreach (glob($dir . '/*.json') as $f) {
            if (time() - @filemtime($f) > $maxAge) @unlink($f);
        }
    }
}
