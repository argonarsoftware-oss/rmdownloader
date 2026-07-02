<?php
// Icafe9 relay helpers — a small, self-contained file-queue that lets the
// developer drive a cafe's LIVE Icafe9 engine remotely. The cafe's desktop app
// (via web/bridge.js) reverse-connects to icafe9-node.php; the developer's
// browser drives it through icafe9-api.php. Kept separate from the shell-agent
// queue in lib.php so the two subsystems never interfere.
//
// Layout under DATA_DIR/icafe9/:
//   nodes.json                          registry { id => {name, ver, seen} }
//   <nodeId>/state.json                 latest engine snapshot pushed by the cafe
//   <nodeId>/online                     unix ts of the cafe's last poll/state
//   <nodeId>/cmd/<cmdId>.json           queued developer command {id,method,payload}
//   <nodeId>/res/<cmdId>.json           result {ok,data|error}
require_once __DIR__ . '/config.php';

function ic9_root() { return DATA_DIR . '/icafe9'; }

// ---- durable cloud mirror (MySQL) ----
// The cafe's .exe stays local-authoritative; here we mirror each pushed snapshot
// into MySQL so the web console shows real, durable data even if DATA_DIR is lost
// or the VPS restarts. Best-effort and fully optional: every function degrades to
// the file store when no database is configured (db() returns null).
function ic9_db() {
    static $ready = null;
    if (!function_exists('db')) return null;
    $pdo = db();
    if (!$pdo) return null;
    if ($ready === null) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS icafe9_state ("
                . "node_id VARCHAR(64) NOT NULL PRIMARY KEY,"
                . "name VARCHAR(190) NOT NULL DEFAULT '',"
                . "state_json LONGTEXT NOT NULL,"
                . "updated_at BIGINT NOT NULL"
                . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $ready = true;
        } catch (Exception $e) { $ready = false; }
    }
    return $ready ? $pdo : null;
}

function ic9_safe_id($id) {
    $id = preg_replace('/[^0-9A-Za-z._-]/', '', (string)$id);
    return substr($id, 0, 64);
}
function ic9_node_dir($id) { return ic9_root() . '/' . ic9_safe_id($id); }

function ic9_ensure($dir) { if (!is_dir($dir)) @mkdir($dir, 0775, true); }

function ic9_write_atomic($file, $data) {
    ic9_ensure(dirname($file));
    $tmp = $file . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, $data);
    rename($tmp, $file);
}

// Node auth: the cafe presents the shared ENROLL_KEY as X-Node-Token.
function ic9_check_node_token() {
    $t = isset($_SERVER['HTTP_X_NODE_TOKEN']) ? $_SERVER['HTTP_X_NODE_TOKEN'] : '';
    return defined('ENROLL_KEY') && ENROLL_KEY !== '' && hash_equals(ENROLL_KEY, $t);
}

function ic9_registry() {
    $f = ic9_root() . '/nodes.json';
    if (!is_file($f)) return array();
    $j = json_decode(@file_get_contents($f), true);
    return is_array($j) ? $j : array();
}
function ic9_save_registry($reg) { ic9_write_atomic(ic9_root() . '/nodes.json', json_encode($reg)); }

function ic9_register($id, $name, $ver) {
    $id = ic9_safe_id($id);
    if ($id === '') return;
    $reg = ic9_registry();
    $reg[$id] = array(
        'name' => substr((string)$name, 0, 60) ?: $id,
        'ver'  => preg_replace('/[^0-9A-Za-z._-]/', '', (string)$ver),
        'seen' => time()
    );
    ic9_save_registry($reg);
    ic9_touch($id);
}

function ic9_touch($id) {
    $dir = ic9_node_dir($id);
    ic9_ensure($dir);
    @file_put_contents($dir . '/online', time());
    $reg = ic9_registry();
    if (isset($reg[ic9_safe_id($id)])) { $reg[ic9_safe_id($id)]['seen'] = time(); ic9_save_registry($reg); }
}

function ic9_online($id) {
    $f = ic9_node_dir($id) . '/online';
    if (!is_file($f)) return false;
    return (time() - (int)@file_get_contents($f)) < 30;
}

function ic9_save_state($id, $state) {
    $json = json_encode($state);
    ic9_write_atomic(ic9_node_dir($id) . '/state.json', $json);
    ic9_touch($id);
    // Durable cloud mirror (best-effort).
    $pdo = ic9_db();
    if ($pdo) {
        try {
            $sid = ic9_safe_id($id);
            $reg = ic9_registry();
            $name = isset($reg[$sid]['name']) ? $reg[$sid]['name'] : $sid;
            $stmt = $pdo->prepare("INSERT INTO icafe9_state (node_id,name,state_json,updated_at) VALUES (?,?,?,?)"
                . " ON DUPLICATE KEY UPDATE name=VALUES(name), state_json=VALUES(state_json), updated_at=VALUES(updated_at)");
            $stmt->execute(array($sid, $name, $json, time()));
        } catch (Exception $e) { /* mirror is best-effort */ }
    }
}
function ic9_load_state($id) {
    $f = ic9_node_dir($id) . '/state.json';
    if (is_file($f)) {
        $j = json_decode(@file_get_contents($f), true);
        if ($j !== null) return $j;
    }
    // Durable fallback: the last snapshot mirrored to MySQL (survives DATA_DIR loss).
    $pdo = ic9_db();
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT state_json FROM icafe9_state WHERE node_id=?");
            $stmt->execute(array(ic9_safe_id($id)));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['state_json'])) return json_decode($row['state_json'], true);
        } catch (Exception $e) { /* ignore */ }
    }
    return null;
}
// Latest mirror row for a node: { present, updated_at } — used by the ?action=mirror diagnostic.
function ic9_mirror_status($id) {
    $pdo = ic9_db();
    if (!$pdo) return array('db' => false);
    try {
        $stmt = $pdo->prepare("SELECT name, updated_at, CHAR_LENGTH(state_json) AS bytes FROM icafe9_state WHERE node_id=?");
        $stmt->execute(array(ic9_safe_id($id)));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return array('db' => true, 'present' => false);
        return array('db' => true, 'present' => true, 'name' => $row['name'],
            'updated_at' => (int)$row['updated_at'], 'bytes' => (int)$row['bytes'],
            'age_seconds' => time() - (int)$row['updated_at']);
    } catch (Exception $e) { return array('db' => true, 'error' => $e->getMessage()); }
}

function ic9_nodes() {
    $reg = ic9_registry();
    $out = array();
    $seen = array();
    foreach ($reg as $id => $meta) {
        $seen[$id] = true;
        $out[] = array(
            'id'     => $id,
            'name'   => isset($meta['name']) ? $meta['name'] : $id,
            'ver'    => isset($meta['ver']) ? $meta['ver'] : '',
            'online' => ic9_online($id),
            'seen'   => isset($meta['seen']) ? $meta['seen'] : 0
        );
    }
    // Include cafes known only from the durable mirror (e.g. after DATA_DIR loss),
    // so the web console still lists them (offline) and can read their last state.
    $pdo = ic9_db();
    if ($pdo) {
        try {
            foreach ($pdo->query("SELECT node_id, name, updated_at FROM icafe9_state") as $r) {
                if (isset($seen[$r['node_id']])) continue;
                $out[] = array(
                    'id'     => $r['node_id'],
                    'name'   => $r['name'] !== '' ? $r['name'] : $r['node_id'],
                    'ver'    => '',
                    'online' => ic9_online($r['node_id']),
                    'seen'   => (int)$r['updated_at']
                );
            }
        } catch (Exception $e) { /* ignore */ }
    }
    return $out;
}

// ---- command queue ----

function ic9_new_id() { return dechex(time()) . bin2hex(random_bytes(5)); }

function ic9_enqueue($id, $method, $payload) {
    $cmdId = ic9_new_id();
    $file = ic9_node_dir($id) . '/cmd/' . $cmdId . '.json';
    ic9_write_atomic($file, json_encode(array('id' => $cmdId, 'method' => $method, 'payload' => $payload, 'at' => time())));
    return $cmdId;
}

// Claim the oldest pending command (remove it so it runs once). Returns assoc or null.
function ic9_claim($id) {
    $dir = ic9_node_dir($id) . '/cmd';
    if (!is_dir($dir)) return null;
    $files = glob($dir . '/*.json');
    if (!$files) return null;
    sort($files); // cmdId is time-prefixed → oldest first
    foreach ($files as $f) {
        $data = json_decode(@file_get_contents($f), true);
        if (@unlink($f) && is_array($data)) return $data; // claimed
    }
    return null;
}

function ic9_store_result($id, $cmdId, $result) {
    $cmdId = preg_replace('/[^0-9A-Za-z]/', '', (string)$cmdId);
    ic9_write_atomic(ic9_node_dir($id) . '/res/' . $cmdId . '.json', json_encode($result));
}

function ic9_fetch_result($id, $cmdId, $timeout = 12) {
    $cmdId = preg_replace('/[^0-9A-Za-z]/', '', (string)$cmdId);
    $f = ic9_node_dir($id) . '/res/' . $cmdId . '.json';
    $deadline = time() + $timeout;
    while (time() <= $deadline) {
        if (is_file($f)) {
            $data = json_decode(@file_get_contents($f), true);
            @unlink($f);
            return $data;
        }
        usleep(200000); // 0.2s
    }
    return null; // timed out (cafe offline or slow)
}
