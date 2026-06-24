<?php
// One-time CDP schema migration (keyless, obscure path — run once, then delete). Creates the
// cdp_nodes / cdp_events / cdp_rules tables. Idempotent (CREATE TABLE IF NOT EXISTS). Self-contained:
// reads DB creds from config.php and runs the DDL via PDO, then reports each table's status.
// Safe only while the repo is PRIVATE-or-accepted-exposure; delete after running.
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');

if (!defined('DB_NAME') || DB_NAME === '' || !defined('DB_USER') || DB_USER === '') {
    echo "DB not configured in config.php (DB_NAME / DB_USER empty)\n";
    exit;
}
try {
    $host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
    $port = defined('DB_PORT') ? DB_PORT : 3306;
    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
} catch (Exception $e) {
    echo "DB connect failed: " . $e->getMessage() . "\n";
    exit;
}

$ddl = array(
'cdp_nodes' => "CREATE TABLE IF NOT EXISTS cdp_nodes (
  node_id     VARCHAR(80)  NOT NULL PRIMARY KEY,
  name        VARCHAR(120) NOT NULL DEFAULT '',
  first_seen  DATETIME     NULL,
  last_seen   DATETIME     NULL,
  chrome      VARCHAR(64)  NOT NULL DEFAULT '',
  tabs        TEXT         NULL,
  running     TINYINT      NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'cdp_events' => "CREATE TABLE IF NOT EXISTS cdp_events (
  id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  node_id VARCHAR(80)     NOT NULL,
  ts      VARCHAR(19)     NOT NULL DEFAULT '',
  type    VARCHAR(12)     NOT NULL DEFAULT '',
  url     VARCHAR(1024)   NOT NULL DEFAULT '',
  title   VARCHAR(255)    NOT NULL DEFAULT '',
  KEY k_node_id   (node_id, id),
  KEY k_node_type (node_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'cdp_rules' => "CREATE TABLE IF NOT EXISTS cdp_rules (
  node_id    VARCHAR(80) NOT NULL PRIMARY KEY,
  rules      MEDIUMTEXT  NULL,
  version    INT         NOT NULL DEFAULT 0,
  updated_at DATETIME    NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
);

echo "CDP schema migration on DB '" . DB_NAME . "' as '" . DB_USER . "'\n";
echo "------------------------------------------------------------\n";
foreach ($ddl as $name => $sql) {
    try { $pdo->exec($sql); echo "  CREATE ok    : " . $name . "\n"; }
    catch (Exception $e) { echo "  CREATE FAILED: " . $name . " -> " . $e->getMessage() . "\n"; }
}
echo "------------------------------------------------------------\n";
foreach (array('cdp_nodes', 'cdp_events', 'cdp_rules') as $t) {
    try { $n = $pdo->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn(); echo "  " . $t . ": EXISTS (" . $n . " rows)\n"; }
    catch (Exception $e) { echo "  " . $t . ": MISSING (" . $e->getMessage() . ")\n"; }
}
echo "\nDone. Delete this file when finished.\n";
