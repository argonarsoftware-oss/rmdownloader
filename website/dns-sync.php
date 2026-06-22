<?php
// Browser-facing trigger for the DNS query-log bridge: ingest this PC's new queries.log
// lines into MySQL on demand (called by dns.js before reading history). Auth: login or API key.
require_once __DIR__ . '/dns-sync-core.php';
app_session();

if (!api_authorized()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(array('ok' => false, 'error' => 'Not authorized'));
    exit;
}
session_write_close();
@set_time_limit(45);
header('Content-Type: application/json');

$id = sanitize_id(isset($_REQUEST['agent']) ? $_REQUEST['agent'] : '');
if ($id === '') { echo json_encode(array('ok' => false, 'error' => 'agent required')); exit; }
$dir = isset($_REQUEST['dir']) ? $_REQUEST['dir'] : '';

echo json_encode(dns_sync_agent($id, $dir));
