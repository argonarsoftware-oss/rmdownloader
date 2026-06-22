<?php
/**
 * TEMPORARY one-shot DNS-stats migration — DELETE after use.
 *
 * Shipped via the [deploy] webhook. Creates the rollup tables (dns_stats_daily,
 * dns_rollup_state), rolls up the raw rows you already have, prints the top sites, then
 * self-deletes. Guarded by ?key=<token>.
 *
 *   https://dos.argonar.co/dns-migrate.php?key=<TOKEN>
 *
 * The website DB user is least-privilege (no CREATE), so this needs a one-time grant first
 * (the page prints the exact command if it's missing). After that grant, page-driven
 * migrations work for good.
 */
require_once __DIR__ . '/dns-sync-core.php';
header('Content-Type: text/plain');

define('MIGRATE_TOKEN', '9ca70c81667634facd571cbf37de3f19');

$key = isset($_GET['key']) ? $_GET['key'] : '';
if (!hash_equals(MIGRATE_TOKEN, (string)$key)) { http_response_code(403); exit("forbidden\n"); }

$pdo = db();
if (!$pdo) { http_response_code(503); exit("database not configured (set DB_* in config.php)\n"); }

$ddl = array(
"CREATE TABLE IF NOT EXISTS dns_stats_daily (
   agent_id VARCHAR(120) NOT NULL, day DATE NOT NULL, domain VARCHAR(255) NOT NULL,
   hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
   PRIMARY KEY (agent_id, day, domain), KEY idx_agent_day (agent_id, day)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
"CREATE TABLE IF NOT EXISTS dns_rollup_state (
   agent_id VARCHAR(120) NOT NULL, rolled_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
   updated_at DATETIME NOT NULL, PRIMARY KEY (agent_id)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
);

try {
    foreach ($ddl as $sql) $pdo->exec($sql);
    echo "✓ tables ready: dns_stats_daily, dns_rollup_state\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "✗ CREATE failed: " . $e->getMessage() . "\n\n";
    echo "The website DB user lacks CREATE. Run this ONCE as root, then reload this page:\n\n";
    echo "  sudo mysql -e \"GRANT CREATE,ALTER,INDEX ON " . DB_NAME .
         ".* TO '" . DB_USER . "'@'127.0.0.1','" . DB_USER . "'@'localhost'; FLUSH PRIVILEGES;\"\n";
    exit;
}

// Roll up the raw rows that are already in dns_queries.
echo "\nrolling up existing rows:\n";
foreach (all_agents() as $id => $a) {
    try { $n = dns_rollup($pdo, $id); echo "  " . $id . ": folded " . $n . " row(s)\n"; }
    catch (Exception $e) { echo "  " . $id . ": rollup error " . $e->getMessage() . "\n"; }
}

// Show the current top sites.
try {
    $st = $pdo->query("SELECT domain, SUM(hits) h FROM dns_stats_daily GROUP BY domain ORDER BY h DESC LIMIT 15");
    echo "\ntop sites so far:\n";
    foreach ($st as $r) echo "  " . str_pad($r['h'], 8, ' ', STR_PAD_LEFT) . "  " . $r['domain'] . "\n";
} catch (Exception $e) { }

// Shrink the exposure window — remove this file from disk now.
@unlink(__FILE__);
echo "\n✓ done — this migrator self-deleted. Reload https://dos.argonar.co/dns.php (Top sites).\n";
echo "  (also remove it from the repo with a [deploy] commit.)\n";
