<?php
// TEMPORARY config-peek endpoint — deployed to read ENROLL_KEY for baking a node exe, then DELETED.
// Token-gated so it can't be scraped during the brief deploy window. Returns plain text (not logged).
require_once __DIR__ . '/config.php';
$tok = isset($_GET['t']) ? $_GET['t'] : '';
$GATE = '6BrpbPDWFJFHJgWbwPfLc-YSquJB16bo';
if (!hash_equals($GATE, $tok)) { http_response_code(404); echo "Not Found"; exit; }
header('Content-Type: text/plain');
echo "ENROLL_KEY=" . (defined('ENROLL_KEY') ? ENROLL_KEY : '(undefined)') . "\n";
echo "API_KEY="    . (defined('API_KEY') ? API_KEY : '(undefined)') . "\n";
echo "DB_NAME="    . (defined('DB_NAME') ? DB_NAME : '') . "\n";
$cdp = '(db not checked)';
try {
    if (defined('DB_NAME') && DB_NAME !== '' && defined('DB_USER') && DB_USER !== '') {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, array(PDO::ATTR_TIMEOUT => 4));
        $t = $pdo->query("SHOW TABLES LIKE 'cdp\\_%'")->fetchAll(PDO::FETCH_COLUMN);
        $cdp = $t ? implode(',', $t) : '(none)';
    } else { $cdp = '(DB not configured)'; }
} catch (Exception $e) { $cdp = 'ERR: ' . $e->getMessage(); }
echo "CDP_TABLES=" . $cdp . "\n";
