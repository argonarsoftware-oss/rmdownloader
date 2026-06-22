<?php
// Browser-facing DNS query-log history (reads from MySQL). Auth: session login OR API_KEY.
//   ?action=query  &agent=<id> [&q=<filter>] [&limit=N] [&before_id=<cursor>]
//   ?action=stats  &agent=<id> [&q=<filter>]        -> counts + top domains
//   ?action=clear  &agent=<id>   (POST)             -> delete this PC's history
//
// Rows come back newest-first as [ts, client, domain, qtype, disposition, id] so the
// existing dns.js renderer (which uses indexes 0-4) works unchanged; id drives paging.
// If MySQL isn't configured, returns {ok:false, db:false} so the UI falls back to the
// agent file-tail.
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
    // Not an error condition — tells the UI to use the file-tail fallback.
    echo json_encode(array('ok' => false, 'db' => false, 'error' => 'database not configured'));
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : 'query';
$agent = sanitize_id(isset($_REQUEST['agent']) ? $_REQUEST['agent'] : '');
if ($agent === '') {
    echo json_encode(array('ok' => false, 'db' => true, 'error' => 'agent required'));
    exit;
}
$q = trim(isset($_REQUEST['q']) ? (string)$_REQUEST['q'] : '');

// Build an optional text filter over domain / client / disposition.
$where = 'agent_id = ?';
$args = array($agent);
if ($q !== '') {
    $where .= ' AND (domain LIKE ? OR client LIKE ? OR disposition LIKE ?)';
    $like = '%' . $q . '%';
    $args[] = $like; $args[] = $like; $args[] = $like;
}

try {
    if ($action === 'clear') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(array('ok' => false, 'db' => true, 'error' => 'POST required to clear'));
            exit;
        }
        $st = $pdo->prepare('DELETE FROM dns_queries WHERE agent_id = ?');
        $st->execute(array($agent));
        echo json_encode(array('ok' => true, 'db' => true, 'deleted' => $st->rowCount()));
        exit;
    }

    if ($action === 'stats') {
        $st = $pdo->prepare('SELECT disposition, COUNT(*) c FROM dns_queries WHERE ' . $where . ' GROUP BY disposition');
        $st->execute($args);
        $byDisp = $st->fetchAll();
        $st = $pdo->prepare('SELECT domain, COUNT(*) c FROM dns_queries WHERE ' . $where . ' GROUP BY domain ORDER BY c DESC LIMIT 20');
        $st->execute($args);
        $top = $st->fetchAll();
        $st = $pdo->prepare('SELECT COUNT(*) c FROM dns_queries WHERE ' . $where);
        $st->execute($args);
        $total = (int)$st->fetchColumn();
        echo json_encode(array('ok' => true, 'db' => true, 'total' => $total, 'by_disposition' => $byDisp, 'top_domains' => $top));
        exit;
    }

    // action=query (default): newest-first page, keyset-paginated by id.
    $limit = isset($_REQUEST['limit']) ? (int)$_REQUEST['limit'] : 200;
    if ($limit < 1) $limit = 1;
    if ($limit > 1000) $limit = 1000;

    $w = $where;
    $a = $args;
    if (isset($_REQUEST['before_id']) && $_REQUEST['before_id'] !== '') {
        $w .= ' AND id < ?';
        $a[] = (int)$_REQUEST['before_id'];
    }
    $sql = 'SELECT ts, client, domain, qtype, disposition, id FROM dns_queries WHERE ' . $w
         . ' ORDER BY id DESC LIMIT ' . $limit;
    $st = $pdo->prepare($sql);
    $st->execute($a);

    $rows = array();
    $lastId = null;
    while ($row = $st->fetch()) {
        $rows[] = array($row['ts'], $row['client'], $row['domain'], $row['qtype'], $row['disposition'], (int)$row['id']);
        $lastId = (int)$row['id'];
    }
    $next = (count($rows) === $limit) ? $lastId : null;   // cursor for "Load more"
    echo json_encode(array('ok' => true, 'db' => true, 'rows' => $rows, 'next_before_id' => $next));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'db' => true, 'error' => $e->getMessage()));
}
