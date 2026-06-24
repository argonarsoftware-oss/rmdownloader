<?php
// Keyless DNS-ingest maintenance endpoint (URL-driven) — companion to the keyless exec endpoint.
// Fixes a stuck/stale MySQL ingest for one agent: optionally resets the byte offset, then loops
// dns_sync_agent() until the DB catches up to the live queries.log, and reports DB stats.
//
//   m-<slug>.php?agent=<id|name>[&reset=1][&dir=C:\Viewers][&status=1]
//     reset=1  -> set dns_ingest_state.log_offset = 0 first (re-read the current log from the top)
//     dir=...  -> folder override (default: agent auto-detects the running dnl.exe folder)
//     status=1 -> just report current offset + row stats, sync nothing
//
// SECURITY: same model as the exec endpoint — no key/login, authorization is possession of this
// unguessable filename. Safe ONLY while the repo is PRIVATE (delete before going public). HTTPS only.
require_once __DIR__ . '/dns-sync-core.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');

function mm_resolve_agent($want) {
    $agents = all_agents();
    if ($want === null || $want === '') return null;
    if (isset($agents[$want])) return $want;
    foreach ($agents as $aid => $a) {
        if (strcasecmp($aid, $want) === 0) return $aid;
        if (isset($a['name']) && strcasecmp($a['name'], $want) === 0) return $aid;
    }
    foreach ($agents as $aid => $a) { if (stripos($aid, $want) === 0) return $aid; }
    return null;
}

$pdo = db();
if (!$pdo) { http_response_code(500); echo "DB not configured (DB_* in config.php)\n"; exit; }

$want = isset($_REQUEST['agent']) ? $_REQUEST['agent'] : '';
$id = mm_resolve_agent($want);
if ($id === null) {
    http_response_code(404);
    echo ($want === '' ? "no &agent= given. agents:\n" : "no agent matches '" . $want . "'. agents:\n");
    foreach (all_agents() as $aid => $a) echo '  ' . (is_online($aid) ? '*' : '.') . ' ' . $aid . '  ' . $a['name'] . "\n";
    exit;
}

function mm_stats($pdo, $id) {
    $o = '?'; $cnt = '?'; $maxts = '?';
    try { $s = $pdo->prepare('SELECT log_offset FROM dns_ingest_state WHERE agent_id=?'); $s->execute(array($id)); $v = $s->fetchColumn(); $o = ($v === false) ? '(none)' : $v; } catch (Exception $e) {}
    try { $s = $pdo->prepare('SELECT COUNT(*), MAX(ts) FROM dns_queries WHERE agent_id=?'); $s->execute(array($id)); $r = $s->fetch(PDO::FETCH_NUM); $cnt = $r[0]; $maxts = $r[1]; } catch (Exception $e) {}
    return "offset=$o  rows=$cnt  newest_ts=$maxts";
}

echo "agent: $id\n";
echo "online: " . (is_online($id) ? 'yes' : 'NO') . "\n";
echo "before: " . mm_stats($pdo, $id) . "\n";

if (isset($_REQUEST['status'])) { exit; }

if (!is_online($id)) { http_response_code(503); echo "agent offline — can't sync\n"; exit; }

$dir = isset($_REQUEST['dir']) ? $_REQUEST['dir'] : '';

// 1) optional offset reset
if (isset($_REQUEST['reset'])) {
    $up = $pdo->prepare('INSERT INTO dns_ingest_state (agent_id, log_offset, updated_at) VALUES (?,0,NOW())
                         ON DUPLICATE KEY UPDATE log_offset = 0, updated_at = NOW()');
    $up->execute(array($id));
    echo "offset reset -> 0\n";
}

// 2) loop the sync until caught up (no new rows) or we run out of time budget
@set_time_limit(110);
$deadline = microtime(true) + 90;
$total = 0; $iter = 0;
while (microtime(true) < $deadline && $iter < 60) {
    $iter++;
    $r = dns_sync_agent($id, $dir);
    if (empty($r['ok'])) { echo "iter $iter: ERROR " . (isset($r['error']) ? $r['error'] : '?') . "\n"; break; }
    if (isset($r['skipped'])) { echo "iter $iter: skipped (" . $r['skipped'] . ") — another sync holds the lock; retrying\n"; usleep(500000); continue; }
    $ins = isset($r['inserted']) ? (int)$r['inserted'] : 0;
    $total += $ins;
    echo "iter $iter: +$ins rows (offset=" . (isset($r['offset']) ? $r['offset'] : '?') . ")\n";
    if ($ins === 0) break;     // caught up
}

echo "TOTAL inserted: $total over $iter iteration(s)\n";
echo "after:  " . mm_stats($pdo, $id) . "\n";
