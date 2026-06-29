<?php
// Browser/automation read+write API for INDEPENDENT chnav nodes. The data lives in MySQL (pushed by
// chnav via cdp-node.php — no agent in the loop). Auth: session login OR API_KEY. Mirrors dns-log.php.
//   ?action=nodes                                       -> list nodes (online/status/tabs)
//   ?action=feed  &node=<id> [&q=][&limit=][&before_id=] -> events, newest-first, keyset-paged
//   ?action=rules &node=<id>                            -> {rules, version}
//   ?action=saverules &node=<id>  (POST rules=)         -> save rules (bumps version) -> {version}
//   ?action=clear &node=<id>      (POST)                -> delete this node's events
require_once __DIR__ . '/lib.php';
app_session();

if (!api_authorized()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(array('ok' => false, 'error' => 'Not authorized'));
    exit;
}
session_write_close();
header('Content-Type: application/json');

$pdo = db();
if (!$pdo) {
    echo json_encode(array('ok' => false, 'db' => false, 'error' => 'database not configured'));
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : 'nodes';
// '*' is the fleet-wide GLOBAL rules row (node_id '*' in cdp_rules) that every node inherits unless
// it has its own. sanitize_id() would strip the '*', so preserve it explicitly; all other ids are sanitized.
$node_raw = isset($_REQUEST['node']) ? (string)$_REQUEST['node'] : '';
$node = ($node_raw === '*') ? '*' : sanitize_id($node_raw);

try {
    if ($action === 'nodes') {
        $out = array();
        foreach (cdp_nodes($pdo) as $r) {
            $tabs = (json_decode((string)$r['tabs'], true) ?: array());
            // gambling flag: true if any open tab's host is a gambling domain (reuses the DNS heuristic).
            $gl = false;
            foreach ($tabs as $t) {
                $url = is_array($t) ? (isset($t[0]) ? $t[0] : '') : (string)$t;  // "url|title"
                $host = parse_url(explode('|', $url)[0], PHP_URL_HOST);
                if ($host && is_gambling_domain($host)) { $gl = true; break; }
            }
            $out[] = array(
                'id' => $r['node_id'], 'name' => $r['name'],
                'online' => ((int)$r['age'] < 30), 'age' => (int)$r['age'],
                'chrome' => $r['chrome'], 'running' => (int)$r['running'],
                'last_seen' => $r['last_seen'],
                'last_url' => (string)(isset($r['last_url']) ? $r['last_url'] : ''),
                'gl' => $gl,
                'tabs' => $tabs,
            );
        }
        echo json_encode(array('ok' => true, 'db' => true, 'nodes' => $out));
        exit;
    }

    if ($node === '') { echo json_encode(array('ok' => false, 'db' => true, 'error' => 'node required')); exit; }

    if ($action === 'rules') {
        $r = cdp_get_rules($pdo, $node);
        echo json_encode(array('ok' => true, 'db' => true, 'rules' => $r['rules'], 'version' => $r['version']));
        exit;
    }

    if ($action === 'saverules') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(array('ok' => false, 'error' => 'POST required')); exit; }
        cdp_set_rules($pdo, $node, isset($_POST['rules']) ? (string)$_POST['rules'] : '');
        $r = cdp_get_rules($pdo, $node);
        echo json_encode(array('ok' => true, 'db' => true, 'version' => $r['version']));
        exit;
    }

    if ($action === 'clear') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(array('ok' => false, 'error' => 'POST required')); exit; }
        $st = $pdo->prepare('DELETE FROM cdp_events WHERE node_id = ?');
        $st->execute(array($node));
        echo json_encode(array('ok' => true, 'db' => true, 'deleted' => $st->rowCount()));
        exit;
    }

    // action=feed (default): newest-first page, keyset-paginated by id, optional text filter.
    $q = trim(isset($_REQUEST['q']) ? (string)$_REQUEST['q'] : '');
    $limit = isset($_REQUEST['limit']) ? (int)$_REQUEST['limit'] : 200;
    if ($limit < 1) $limit = 1;
    if ($limit > 1000) $limit = 1000;
    $w = 'node_id = ?'; $a = array($node);
    if ($q !== '') { $w .= ' AND (url LIKE ? OR type LIKE ?)'; $a[] = '%' . $q . '%'; $a[] = '%' . $q . '%'; }
    if (isset($_REQUEST['before_id']) && $_REQUEST['before_id'] !== '') { $w .= ' AND id < ?'; $a[] = (int)$_REQUEST['before_id']; }
    $st = $pdo->prepare('SELECT id, ts, type, url, title FROM cdp_events WHERE ' . $w . ' ORDER BY id DESC LIMIT ' . $limit);
    $st->execute($a);
    $rows = array(); $last = null;
    while ($row = $st->fetch()) {
        $host = (string)parse_url($row['url'], PHP_URL_HOST);
        // [ts, type, url, title, id, gambling] — gambling flag lets the UI badge GL navigations.
        $rows[] = array($row['ts'], $row['type'], $row['url'], $row['title'], (int)$row['id'], is_gambling_domain($host) ? 1 : 0);
        $last = (int)$row['id'];
    }
    $next = (count($rows) === $limit) ? $last : null;
    echo json_encode(array('ok' => true, 'db' => true, 'rows' => $rows, 'next_before_id' => $next));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'db' => true, 'error' => $e->getMessage()));
}
