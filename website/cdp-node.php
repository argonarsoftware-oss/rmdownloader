<?php
// chnav-facing endpoint — lets chnav.exe run INDEPENDENTLY of the agent. chnav reverse-connects
// here (outbound HTTPS, like the agent) to PUSH nav events + status and PULL its rules.
//   POST ?action=report   body {events:[{ts,type,url,title}], chrome, tabs:[...], running}
//                          -> registers/updates the node + stores events; returns {rules_version}
//   GET  ?action=rules     -> {rules, version}   (this node's blt.txt; falls back to global '*')
// Auth: shared ENROLL_KEY (header X-Node-Token) + machine id (X-Node-Id) — same trust model as the
// agent's auto-enroll. No session. Needs the cdp_* tables (cdp-schema.sql) + DB configured.
require_once __DIR__ . '/lib.php';
header('Content-Type: application/json');

$token = isset($_SERVER['HTTP_X_NODE_TOKEN']) ? $_SERVER['HTTP_X_NODE_TOKEN'] : '';
if (!defined('ENROLL_KEY') || ENROLL_KEY === '' || !hash_equals(ENROLL_KEY, $token)) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'unauthorized'));
    exit;
}
$nodeId = sanitize_id(isset($_SERVER['HTTP_X_NODE_ID']) ? $_SERVER['HTTP_X_NODE_ID'] : '');
if ($nodeId === '') {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'missing node id'));
    exit;
}
$name = trim(preg_replace('/[^\w .\-]/', '', isset($_SERVER['HTTP_X_NODE_NAME']) ? $_SERVER['HTTP_X_NODE_NAME'] : $nodeId));

$pdo = db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(array('ok' => false, 'error' => 'database not configured'));
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
@set_time_limit(20);

try {
    if ($action === 'rules') {
        $r = cdp_get_rules($pdo, $nodeId);
        echo json_encode(array('ok' => true, 'rules' => $r['rules'], 'version' => $r['version']));
        exit;
    }

    if ($action === 'report') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) $body = array();
        cdp_register_node($pdo, $nodeId, $name, $body);
        $inserted = 0;
        if (!empty($body['events']) && is_array($body['events'])) {
            $inserted = cdp_insert_events($pdo, $nodeId, $body['events']);
        }
        $r = cdp_get_rules($pdo, $nodeId);
        // Return rules_version so chnav can detect a change and GET ?action=rules only when needed.
        echo json_encode(array('ok' => true, 'inserted' => $inserted, 'rules_version' => $r['version']));
        exit;
    }

    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'unknown action'));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => $e->getMessage()));
}
