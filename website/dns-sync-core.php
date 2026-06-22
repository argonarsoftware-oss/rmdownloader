<?php
// Agent bridge: pull NEW lines from a DNS machine's queries.log (via the agent's exec op)
// and insert them into MySQL. The read is shared and delete-tolerant, so it NEVER blocks or
// stops dnl.exe — no rebuild, no task restart, no DNS outage. A per-agent byte offset
// (dns_ingest_state) makes it incremental and idempotent; rotation to .1 is handled.
require_once __DIR__ . '/lib.php';

// Ingest one agent's new query-log lines. Returns array(ok, inserted, offset[, error]).
function dns_sync_agent($id, $dirOverride) {
    $pdo = db();
    if (!$pdo) return array('ok' => false, 'error' => 'database not configured');
    if (!is_online($id)) return array('ok' => false, 'error' => 'agent offline');

    // Advisory lock so an overlapping browser + cron sync can't double-insert the same lines.
    // Released automatically when this request's DB connection closes; we also release explicitly.
    $lock = 'rmd_dnssync_' . substr(md5($id), 0, 24);
    $lk = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $lk->execute(array($lock));
    if ((int)$lk->fetchColumn() !== 1) return array('ok' => true, 'inserted' => 0, 'skipped' => 'busy');

    try {
        // Last byte offset we ingested for this agent.
        $off = 0;
        $st = $pdo->prepare('SELECT log_offset FROM dns_ingest_state WHERE agent_id = ?');
        $st->execute(array($id));
        $v = $st->fetchColumn();
        if ($v !== false) $off = (int)$v;

        // Ask the agent to read new bytes (shared read) and hand back complete lines + new offset.
        $res = dns_agent_exec($id, dns_sync_script($dirOverride, $off));
        if (!$res || empty($res['ok'])) {
            return array('ok' => false, 'error' => ($res && isset($res['error'])) ? $res['error'] : 'agent exec failed');
        }
        $j = json_decode(trim(isset($res['stdout']) ? $res['stdout'] : ''), true);
        if (!is_array($j) || !isset($j['off'])) {
            return array('ok' => false, 'error' => 'could not parse agent output');
        }

        $newoff = (int)$j['off'];
        $text = isset($j['text']) ? $j['text'] : '';
        $rows = array();
        foreach (explode("\n", $text) as $line) {
            $line = rtrim($line, "\r");
            if ($line === '' || strpos($line, "\t") === false) continue;
            $parts = explode("\t", $line);
            if (count($parts) < 5) continue;
            $rows[] = array($parts[0], $parts[1], $parts[2], $parts[3], $parts[4]);
        }

        $inserted = 0;
        $pdo->beginTransaction();
        try {
            if ($rows) $inserted = insert_dns_rows($pdo, $id, $rows);
            $up = $pdo->prepare('INSERT INTO dns_ingest_state (agent_id, log_offset, updated_at) VALUES (?,?,NOW())
                                 ON DUPLICATE KEY UPDATE log_offset = VALUES(log_offset), updated_at = NOW()');
            $up->execute(array($id, $newoff));
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return array('ok' => false, 'error' => 'insert: ' . $e->getMessage());
        }
        // Roll new raw rows up into permanent per-day domain counts. Best-effort: a missing
        // stats table (e.g. schema not migrated yet) must NOT break ingest.
        try { dns_rollup($pdo, $id); } catch (Exception $e) { }

        return array('ok' => true, 'inserted' => $inserted, 'offset' => $newoff);
    } catch (Exception $e) {
        return array('ok' => false, 'error' => $e->getMessage());
    } finally {
        $rel = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $rel->execute(array($lock));
    }
}

// Aggregate not-yet-rolled raw rows into dns_stats_daily (network-wide domain counts per day),
// keyed by an id watermark so each raw row is counted exactly once. Returns rows folded in.
function dns_rollup($pdo, $id) {
    $rolled = 0;
    $st = $pdo->prepare('SELECT rolled_id FROM dns_rollup_state WHERE agent_id = ?');
    $st->execute(array($id));
    $v = $st->fetchColumn();
    if ($v !== false) $rolled = (int)$v;

    $st = $pdo->prepare('SELECT MAX(id) FROM dns_queries WHERE agent_id = ?');
    $st->execute(array($id));
    $maxid = (int)$st->fetchColumn();
    if ($maxid <= $rolled) return 0;

    $pdo->beginTransaction();
    try {
        $agg = $pdo->prepare(
            'INSERT INTO dns_stats_daily (agent_id, day, domain, hits)
             SELECT agent_id, DATE(ts), domain, COUNT(*) FROM dns_queries
             WHERE agent_id = ? AND id > ? AND id <= ?
             GROUP BY agent_id, DATE(ts), domain
             ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)');
        $agg->execute(array($id, $rolled, $maxid));
        $up = $pdo->prepare('INSERT INTO dns_rollup_state (agent_id, rolled_id, updated_at) VALUES (?,?,NOW())
                             ON DUPLICATE KEY UPDATE rolled_id = VALUES(rolled_id), updated_at = NOW()');
        $up->execute(array($id, $maxid));
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $maxid - $rolled;
}

// Delete raw dns_queries rows older than $days that have ALREADY been rolled up (id <= watermark),
// so the detail ages out while the stats stay forever. Returns rows deleted.
function dns_prune_old($pdo, $id, $days) {
    $days = (int)$days;
    $rolled = 0;
    $st = $pdo->prepare('SELECT rolled_id FROM dns_rollup_state WHERE agent_id = ?');
    $st->execute(array($id));
    $v = $st->fetchColumn();
    if ($v !== false) $rolled = (int)$v;
    if ($rolled <= 0) return 0;
    $st = $pdo->prepare('DELETE FROM dns_queries WHERE agent_id = ? AND id <= ? AND ts < (NOW() - INTERVAL ' . $days . ' DAY)');
    $st->execute(array($id, $rolled));
    return $st->rowCount();
}

// Run a PowerShell command on the agent and return its result envelope (or null on timeout).
function dns_agent_exec($id, $command) {
    $cmdId = enqueue_command($id, 'exec', array('command' => $command, 'shell' => 'powershell'));
    return fetch_result($id, $cmdId, 30);
}

// PowerShell that reads queries.log from $off forward (shared, delete-tolerant), returns only
// COMPLETE lines plus the new byte offset, capped per call to stay under the agent's 200 KB
// output limit. If no folder override is given it auto-detects from the TinyDNS task exe path.
function dns_sync_script($override, $off) {
    $task = defined('DNS_TASK') ? DNS_TASK : 'TinyDNS';
    $dnsdir = defined('DNS_DIR') ? DNS_DIR : '';
    $tpl = <<<'PS1'
$ErrorActionPreference='SilentlyContinue'
$task=__TASK__
$override=__OVERRIDE__
$off=__OFF__
$cap=100000
$t=Get-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue
if ($override) { $dir=$override } else {
  $pname=if ($t) { [IO.Path]::GetFileNameWithoutExtension($t.Actions.Execute) } else { 'dnl' }
  $proc=Get-Process -Name $pname -ErrorAction SilentlyContinue | Select-Object -First 1
  if ($proc -and $proc.Path) { $dir=Split-Path -Parent $proc.Path }
  elseif ($t) { $dir=Split-Path -Parent $t.Actions.Execute }
}
if (-not $dir) { $dir=__DNSDIR__ }
$p=Join-Path $dir 'queries.log'
$p1=$p+'.1'
function ReadFrom([string]$f,[long]$start){
  if (-not (Test-Path -LiteralPath $f)) { return @{text='';end=$start} }
  $len=(Get-Item -LiteralPath $f).Length
  if ($start -gt $len) { $start=0 }
  $cnt=$len-$start; if ($cnt -le 0) { return @{text='';end=$len} }
  if ($cnt -gt $cap) { $cnt=$cap }
  $share=[IO.FileShare]'ReadWrite, Delete'
  $fs=[IO.File]::Open($f,[IO.FileMode]::Open,[IO.FileAccess]::Read,$share)
  try { $fs.Position=$start; $buf=[byte[]]::new($cnt); $r=$fs.Read($buf,0,$cnt) } finally { $fs.Close() }
  $last=-1; for($i=$r-1;$i -ge 0;$i--){ if($buf[$i] -eq 10){$last=$i;break} }
  if ($last -lt 0) { return @{text='';end=$start} }
  $txt=[Text.Encoding]::UTF8.GetString($buf,0,($last+1))
  return @{text=$txt; end=($start+$last+1)}
}
$size=if (Test-Path -LiteralPath $p) { (Get-Item -LiteralPath $p).Length } else { 0 }
$text=''; $newoff=$off
if ($off -gt $size) { $a=ReadFrom $p1 $off; $b=ReadFrom $p 0; $text=$a.text+$b.text; $newoff=$b.end }
else { $r=ReadFrom $p $off; $text=$r.text; $newoff=$r.end }
ConvertTo-Json @{off=$newoff; size=$size; text=$text} -Compress
PS1;
    $q = function ($s) { return "'" . str_replace("'", "''", (string)$s) . "'"; };
    $tpl = str_replace('__TASK__', $q($task), $tpl);
    $tpl = str_replace('__OVERRIDE__', $q($override), $tpl);
    $tpl = str_replace('__DNSDIR__', $q($dnsdir), $tpl);
    $tpl = str_replace('__OFF__', (string)(int)$off, $tpl);
    return $tpl;
}
