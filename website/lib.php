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
    app_session(); // start the session so a logged-in browser cookie is honored (not just API key)
    if (is_logged_in()) return true;
    if (defined('API_KEY') && API_KEY !== '') {
        $k = '';
        if (isset($_SERVER['HTTP_X_API_KEY'])) $k = $_SERVER['HTTP_X_API_KEY'];
        elseif (isset($_REQUEST['key'])) $k = $_REQUEST['key'];
        if ($k !== '' && hash_equals(API_KEY, $k)) return true;
    }
    return false;
}

// ---- database (optional; DNS query-log history) ----

// Lazy PDO singleton. Returns a PDO, or null if MySQL isn't configured / unreachable.
// Callers must handle null (the app works file-only without a DB).
function db() {
    static $pdo = false;            // false = not yet tried, null = unavailable
    if ($pdo !== false) return $pdo;
    if (!defined('DB_NAME') || DB_NAME === '' || !defined('DB_USER') || DB_USER === '') {
        $pdo = null; return $pdo;
    }
    try {
        $host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
        $port = defined('DB_PORT') ? DB_PORT : 3306;
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ));
    } catch (Exception $e) {
        $pdo = null;
    }
    return $pdo;
}

// Bulk-insert DNS query rows. $rows = array of array(ts, client, domain, qtype, disposition).
function insert_dns_rows($pdo, $agentId, $rows) {
    $inserted = 0;
    $chunk = array();
    foreach ($rows as $r) {
        if (count($r) < 5) continue;
        $chunk[] = array($agentId, substr($r[0], 0, 19), substr($r[1], 0, 45),
                         substr($r[2], 0, 255), substr($r[3], 0, 16), substr($r[4], 0, 64));
        if (count($chunk) >= 200) { $inserted += dns_insert_chunk($pdo, $chunk); $chunk = array(); }
    }
    if ($chunk) $inserted += dns_insert_chunk($pdo, $chunk);
    return $inserted;
}

function dns_insert_chunk($pdo, $chunk) {
    $ph = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?)'));
    $st = $pdo->prepare('INSERT INTO dns_queries (agent_id, ts, client, domain, qtype, disposition) VALUES ' . $ph);
    $vals = array();
    foreach ($chunk as $row) foreach ($row as $v) $vals[] = $v;
    $st->execute($vals);
    return count($chunk);
}

// Reduce a hostname to its registrable domain (eTLD+1), collapsing subdomains:
//   www.google.com, ogads-pa.clients6.google.com -> google.com ;  bbc.co.uk -> bbc.co.uk
// Uses a small list of common multi-label public suffixes; everything else is "last two labels".
function registrable_domain($host) {
    $host = strtolower(rtrim((string)$host, '.'));
    if ($host === '' || strpos($host, '.') === false) return $host;
    // a bare IPv4 has no registrable domain — leave it as-is
    if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $host)) return $host;
    $l = explode('.', $host);
    $n = count($l);
    if ($n <= 2) return $host;
    $last2 = $l[$n - 2] . '.' . $l[$n - 1];
    static $two = null;
    if ($two === null) {
        $two = array_flip(array(
            'co.uk','org.uk','me.uk','gov.uk','ac.uk','net.uk','sch.uk',
            'com.au','net.au','org.au','edu.au','gov.au','id.au',
            'co.jp','or.jp','ne.jp','ac.jp','go.jp',
            'com.br','net.br','org.br','gov.br',
            'co.in','net.in','org.in','gen.in','firm.in','ind.in',
            'co.nz','net.nz','org.nz','govt.nz','ac.nz',
            'co.za','org.za','net.za','gov.za',
            'com.cn','net.cn','org.cn','gov.cn',
            'com.sg','com.my','com.ph','com.hk','com.tw','com.mx','com.tr',
            'com.ar','com.co','co.id','co.kr','co.th','com.vn','com.pk',
            'com.sa','com.eg','com.ng','com.ua','co.il','com.pl','co.ke',
        ));
    }
    if ($n >= 3 && isset($two[$last2])) return $l[$n - 3] . '.' . $last2;
    return $last2;
}

// Best-effort flag: does this host look gambling-related? Keyword + known-brand substring match
// (incl. common PH/Asia online-gambling brands). Heuristic — catches obvious ones, not definitive.
function is_gambling_domain($host) {
    $h = strtolower((string)$host);
    if ($h === '') return false;
    static $kw = null;
    if ($kw === null) $kw = array(
        'casino','poker','slots','sportsbook','sportsbet','gambling','gamble','roulette',
        'baccarat','blackjack','jackpot','betting','bet365','betway','bookmaker','pokies',
        'sweepstake','lottery','bingo','sabong','pagcor','onlinebet','wagering',
        // common PH / Asia online-gambling brands
        'jilibet','bingoplus','luckycola','okbet','phlwin','swerte','panaloko','747live',
        'fc777','ph365','mwplay','peso888','gemdisco','nustabet','hawkplay','lodibet',
        'pisobet','jili777','bet88','phdream','superace','royalwin','winph','tmtplay',
        'phlwin','phpwin','phwin','winzir','bossjili','slotsgo','megapanalo',
    );
    foreach ($kw as $k) { if (strpos($h, $k) !== false) return true; }
    return false;
}

// ---- CDP independent nodes (chnav reporting WITHOUT the agent) ----
// chnav.exe reverse-connects to cdp-node.php to push nav events + status and pull its rules.
// These mirror the dns_* helpers but for the cdp_* tables (see cdp-schema.sql).

function cdp_register_node($pdo, $id, $name, $info) {
    $chrome  = substr((string)(isset($info['chrome']) ? $info['chrome'] : ''), 0, 64);
    $tabs    = substr(json_encode(isset($info['tabs']) && is_array($info['tabs']) ? $info['tabs'] : array()), 0, 60000);
    $running = !empty($info['running']) ? 1 : 0;
    $st = $pdo->prepare('INSERT INTO cdp_nodes (node_id, name, first_seen, last_seen, chrome, tabs, running)
                         VALUES (?,?,NOW(),NOW(),?,?,?)
                         ON DUPLICATE KEY UPDATE name=VALUES(name), last_seen=NOW(),
                           chrome=VALUES(chrome), tabs=VALUES(tabs), running=VALUES(running)');
    $st->execute(array($id, ($name !== '' ? $name : $id), $chrome, $tabs, $running));
}

function cdp_insert_events($pdo, $id, $events) {
    $rows = array();
    foreach ($events as $e) {
        if (!is_array($e)) continue;
        $rows[] = array($id,
            substr((string)(isset($e['ts'])    ? $e['ts']    : ''), 0, 19),
            substr((string)(isset($e['type'])  ? $e['type']  : ''), 0, 12),
            substr((string)(isset($e['url'])   ? $e['url']   : ''), 0, 1024),
            substr((string)(isset($e['title']) ? $e['title'] : ''), 0, 255));
    }
    if (!$rows) return 0;
    $ins = 0;
    for ($i = 0; $i < count($rows); $i += 200) {
        $chunk = array_slice($rows, $i, 200);
        $ph = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?)'));
        $st = $pdo->prepare('INSERT INTO cdp_events (node_id, ts, type, url, title) VALUES ' . $ph);
        $vals = array();
        foreach ($chunk as $r) foreach ($r as $v) $vals[] = $v;
        $st->execute($vals);
        $ins += count($chunk);
    }
    return $ins;
}

function cdp_get_rules($pdo, $id) {
    $st = $pdo->prepare('SELECT rules, version FROM cdp_rules WHERE node_id = ?');
    $st->execute(array($id));
    $row = $st->fetch();
    if (!$row) { $st->execute(array('*')); $row = $st->fetch(); }   // fall back to global default
    return $row ? array('rules' => (string)$row['rules'], 'version' => (int)$row['version'])
                : array('rules' => '', 'version' => 0);
}

function cdp_set_rules($pdo, $id, $rules) {
    $st = $pdo->prepare('INSERT INTO cdp_rules (node_id, rules, version, updated_at) VALUES (?,?,1,NOW())
                         ON DUPLICATE KEY UPDATE rules=VALUES(rules), version=version+1, updated_at=NOW()');
    $st->execute(array($id, (string)$rules));
}

function cdp_nodes($pdo) {
    // last_url = the node's most recent navigation event (newest by id); SELECT-only, no schema change.
    // has_own_rules: does this node have its OWN cdp_rules row? If not, cdp_get_rules() falls back to
    // the '*' global, so the UI can badge it as inheriting. (A node with its own row — even empty —
    // does NOT inherit, matching cdp_get_rules.)
    $st = $pdo->query('SELECT n.node_id, n.name, n.last_seen, n.chrome, n.tabs, n.running,
                        (UNIX_TIMESTAMP() - UNIX_TIMESTAMP(n.last_seen)) AS age,
                        EXISTS(SELECT 1 FROM cdp_rules r WHERE r.node_id = n.node_id) AS has_own_rules,
                        (SELECT e.url FROM cdp_events e WHERE e.node_id = n.node_id ORDER BY e.id DESC LIMIT 1) AS last_url
                       FROM cdp_nodes n ORDER BY n.last_seen DESC');
    return $st->fetchAll();
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

// Record the agent's reported version (from X-Agent-Version) so the UI can show it
// and feature-detect. Works for both static and auto-enrolled agents.
function touch_version($id, $ver) {
    $ver = preg_replace('/[^0-9A-Za-z._-]/', '', (string)$ver);
    if ($ver === '') return;
    $dir = agent_dir($id);
    ensure_dir($dir);
    @file_put_contents($dir . '/version', substr($ver, 0, 32));
}

function agent_version($id) {
    $f = agent_dir($id) . '/version';
    return is_file($f) ? trim((string)@file_get_contents($f)) : '';
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
