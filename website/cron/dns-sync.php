<?php
// CLI cron: ingest DNS query logs for ALL online agents into MySQL, so history keeps
// accumulating even when no browser is open. The read never stops dnl.exe (shared read).
//
// Install on the VPS (every minute):
//   * * * * * www-data php /var/www/rmdownloader/website/cron/dns-sync.php >> /var/log/rmd-dns-sync.log 2>&1
//
// Each run reads up to ~100 KB of new log per agent; if a box is busier than that it simply
// catches up over the next ticks. Requires MySQL configured (config.php DB_*).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../dns-sync-core.php';

if (!db()) { fwrite(STDERR, "database not configured (set DB_* in config.php)\n"); exit(1); }

$ts = date('Y-m-d H:i:s');
foreach (all_agents() as $id => $a) {
    if (!is_online($id)) continue;
    $r = dns_sync_agent($id, '');
    fwrite(STDOUT, "[$ts] $id: " . json_encode($r) . "\n");
}
