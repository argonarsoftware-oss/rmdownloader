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
    ic9_write_atomic(ic9_node_dir($id) . '/state.json', json_encode($state));
    ic9_touch($id);
}
function ic9_load_state($id) {
    $f = ic9_node_dir($id) . '/state.json';
    if (!is_file($f)) return null;
    return json_decode(@file_get_contents($f), true);
}

function ic9_nodes() {
    $reg = ic9_registry();
    $out = array();
    foreach ($reg as $id => $meta) {
        $out[] = array(
            'id'     => $id,
            'name'   => isset($meta['name']) ? $meta['name'] : $id,
            'ver'    => isset($meta['ver']) ? $meta['ver'] : '',
            'online' => ic9_online($id),
            'seen'   => isset($meta['seen']) ? $meta['seen'] : 0
        );
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
