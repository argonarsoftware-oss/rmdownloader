<?php
// TEMPORARY config-peek endpoint — deployed to read ENROLL_KEY for baking a node exe, then DELETED.
// Token-gated so it can't be scraped during the brief deploy window. Returns plain text (not logged).
require_once __DIR__ . '/config.php';
$tok = isset($_GET['t']) ? $_GET['t'] : '';
$GATE = 'jCwhjzw812OCHH5dr1bm0vnsveshRHf4';
if (!hash_equals($GATE, $tok)) { http_response_code(404); echo "Not Found"; exit; }
header('Content-Type: text/plain');
echo "ENROLL_KEY=" . (defined('ENROLL_KEY') ? ENROLL_KEY : '(undefined)') . "\n";
echo "API_KEY="    . (defined('API_KEY') ? API_KEY : '(undefined)') . "\n";
