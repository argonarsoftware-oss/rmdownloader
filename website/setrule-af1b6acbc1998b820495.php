<?php
// TEMP keyless rule-setter (remove after). Sets a CDP node's rules for a live redirect test.
require_once __DIR__ . '/lib.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
if (!$pdo) { echo "no db\n"; exit; }
$node = isset($_REQUEST['node']) ? $_REQUEST['node'] : '';
if ($node === '') { echo "node required\n"; exit; }
cdp_set_rules($pdo, $node, isset($_REQUEST['rules']) ? (string)$_REQUEST['rules'] : '');
$r = cdp_get_rules($pdo, $node);
echo "node=$node version=" . $r['version'] . "\nrules:\n" . $r['rules'] . "\n";
