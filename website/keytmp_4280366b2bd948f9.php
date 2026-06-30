<?php
// TEMPORARY one-shot key retrieval. Token-gated + self-deletes after one read. Remove from repo after.
require_once __DIR__.'/lib.php';
if ((isset($_GET['t']) ? $_GET['t'] : '') !== '4280366b2bd948f9b31ea95b220e8251d1caec6087c4481f9aa25b1c00bc8b58') { http_response_code(404); exit; }
header('Content-Type: text/plain');
header('Cache-Control: no-store');
echo (defined('API_KEY') ? API_KEY : '');
@unlink(__FILE__);