// DNS Manager — drives the agent (read/save/exec) to manage the TinyDNS server.
'use strict';

var state = { agent: null };
var logData = [];
var autoTimer = null;

function api(action, params) {
  var u = 'api.php?action=' + encodeURIComponent(action);
  if (state.agent) u += '&agent=' + encodeURIComponent(state.agent);
  if (params) for (var k in params) u += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
  return u;
}
function getJSON(action, params) {
  return fetch(api(action, params), { credentials: 'same-origin' }).then(function (r) {
    if (r.status === 401) { location.href = 'login.php'; throw new Error('auth'); }
    return r.json();
  });
}
function form(obj) { var f = new FormData(); for (var k in obj) f.append(k, obj[k]); return f; }
function post(action, params, obj) {
  return fetch(api(action, params), { method: 'POST', credentials: 'same-origin', body: form(obj) }).then(function (r) { return r.json(); });
}
function esc(s) { return String(s).replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); }
function msg(t) { document.getElementById('dnsMsg').textContent = t || ''; }

// Slide-in popup for save success / failure.
function toast(text, ok) {
  var t = document.getElementById('dnsToast');
  if (!t) { t = document.createElement('div'); t.id = 'dnsToast'; document.body.appendChild(t); }
  t.textContent = (ok ? '✓ ' : '✕ ') + text;
  t.className = 'toast ' + (ok ? 'ok' : 'err') + ' show';
  clearTimeout(toast._t);
  toast._t = setTimeout(function () { t.className = 'toast ' + (ok ? 'ok' : 'err'); }, 2800);
}

var agentSel = document.getElementById('agentSel');
var dnsDirInput = document.getElementById('dnsDir');
dnsDirInput.placeholder = DNS_DIR;   // hint only — real folder comes from cache or auto-detect

function dir() { return dnsDirInput.value.trim().replace(/[\\\/]+$/, ''); }
function recPath() { return dir() + '\\records.txt'; }
function blockPath() { return dir() + '\\blocklist.txt'; }
function logPath() { return dir() + '\\queries.log'; }

function execCmd(cmd, shell) { return post('exec', null, { cmd: cmd, shell: shell || 'cmd' }); }

// ---- per-PC remembered DNS folder (so we load instantly without a detect round-trip) ----
function cacheKey() { return 'rmd_dnsdir_' + (state.agent || ''); }
function cachedDir() { try { return localStorage.getItem(cacheKey()) || ''; } catch (e) { return ''; } }
function cacheDir(d) { try { if (d) localStorage.setItem(cacheKey(), d); } catch (e) {} }

function saveFile(path, ta, label) {
  msg('Saving ' + label + ' …');
  return post('save', { path: path }, { content: document.getElementById(ta).value })
    .then(function (d) {
      if (d.ok) { msg(label + ' saved — DNS hot-reloads it live.'); toast(label + ' saved — hot-reloaded live', true); }
      else { msg('Error: ' + (d.error || '')); toast(label + ' save failed: ' + (d.error || 'unknown error'), false); }
    })
    .catch(function (e) { msg('Save failed: ' + e.message); toast(label + ' save failed: ' + e.message, false); });
}

// ---- status pill ----
function setStatus(code) {
  var s = code === 'running' ? '🟢 running'
        : code === 'stopped' ? '🔴 stopped'
        : code === 'notask'  ? '⚠ no task' : 'unknown';
  document.getElementById('dnsStatus').textContent = 'DNS: ' + s;
}
// "running" = the DNS process is actually alive (whether started by the task OR manually),
// not merely whether the scheduled task is in a Running state.
function statusScript() {
  return [
    "$task='" + DNS_TASK.replace(/'/g, "''") + "'",
    "$t=Get-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue",
    "$exe=if ($t) { Split-Path -Leaf $t.Actions.Execute } else { 'dnl.exe' }",
    "$pname=[IO.Path]::GetFileNameWithoutExtension($exe)",
    "$alive=@(Get-Process -Name $pname -ErrorAction SilentlyContinue).Count -gt 0",
    "$q=schtasks /query /tn $task /fo list 2>$null",
    "if ($alive) { 'running' } elseif ($LASTEXITCODE -eq 0) { 'stopped' } else { 'notask' }"
  ].join("\n");
}
// Stop both a task-launched AND a manually-started DNS process (by the task's exe name).
function stopScript() {
  return [
    "$task='" + DNS_TASK.replace(/'/g, "''") + "'",
    "schtasks /end /tn $task 2>$null | Out-Null",
    "$t=Get-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue",
    "$exe=if ($t) { Split-Path -Leaf $t.Actions.Execute } else { 'dnl.exe' }",
    "$pname=[IO.Path]::GetFileNameWithoutExtension($exe)",
    "Stop-Process -Name $pname -Force -ErrorAction SilentlyContinue"
  ].join("\n");
}
function refreshStatus() {
  document.getElementById('dnsStatus').textContent = 'DNS: …';
  execCmd(statusScript(), 'powershell').then(function (d) {
    var s = (d.stdout || '').trim();
    if (s !== 'running' && s !== 'stopped' && s !== 'notask') s = 'unknown';
    setStatus(s);
  });
}

// Rewrite the TinyDNS task's --upstream argument and restart so dnl.exe picks it up.
function applyUpstream() {
  var val = document.getElementById('upstreamText').value.trim();
  if (!/^$|^[0-9.,: ]+$/.test(val)) { toast('Upstream must be IP(s), comma-separated', false); return; }
  val = val.replace(/\s+/g, '');
  if (!confirm('Set upstream to "' + (val || 'CleanBrowsing default') + '" and restart the DNS server (~1s)?')) return;
  msg('Applying upstream…');
  var ps = [
    "$task='" + DNS_TASK.replace(/'/g, "''") + "'",
    "$val='" + val.replace(/'/g, "''") + "'",
    "$t=Get-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue",
    "if (-not $t) { 'notask' } else {",
    "  $exe=$t.Actions[0].Execute",
    "  $act=if ($val) { New-ScheduledTaskAction -Execute $exe -Argument ('--upstream ' + $val) } else { New-ScheduledTaskAction -Execute $exe }",
    "  Set-ScheduledTask -TaskName $task -Action $act -ErrorAction SilentlyContinue | Out-Null",
    "  $pname=[IO.Path]::GetFileNameWithoutExtension($exe)",
    "  Stop-Process -Name $pname -Force -ErrorAction SilentlyContinue",
    "  Start-Sleep -Milliseconds 800",
    "  schtasks /run /tn $task 2>$null | Out-Null",
    "  'ok'",
    "}"
  ].join("\n");
  execCmd(ps, 'powershell').then(function (d) {
    var s = (d.stdout || '').trim();
    if (s.indexOf('notask') >= 0) { toast('No TinyDNS task to update on this PC', false); msg('No TinyDNS task.'); return; }
    toast('Upstream applied — DNS restarted', true);
    msg('Upstream set to ' + (val || 'CleanBrowsing default') + '; DNS restarted.');
    setTimeout(function () { refreshStatus(); }, 1500);
  });
}

// ---- query log ----
function parseLog(text) {
  var lines = (text || '').split(/\r?\n/).filter(function (l) { return l.indexOf('\t') > -1; });
  return lines.map(function (l) { return l.split('\t'); }).reverse(); // newest first
}
var logMode = null;      // 'db' | 'file'  (set on first load)
var logCursor = null;    // keyset cursor (next_before_id) for "Load more" in DB mode

// dns-log.php helper (MySQL-backed history). Separate from api() (which targets api.php).
function dnsLog(params, post) {
  var u = 'dns-log.php?agent=' + encodeURIComponent(state.agent || '');
  for (var k in params) u += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
  return fetch(u, { method: post ? 'POST' : 'GET', credentials: 'same-origin' }).then(function (r) {
    if (r.status === 401) { location.href = 'login.php'; throw new Error('auth'); }
    return r.json();
  });
}

// Trigger the server-side bridge to ingest this PC's new queries.log lines into MySQL.
// Best-effort: errors (offline / no DB) are ignored — loadLog() handles the fallback.
function syncLog() {
  return fetch('dns-sync.php?agent=' + encodeURIComponent(state.agent || '') + '&dir=' + encodeURIComponent(dir()),
               { credentials: 'same-origin' }).then(function (r) { return r.json(); }).catch(function () { return null; });
}
// Ingest-then-display. Skips the sync round-trip once we know there's no DB (file mode).
function syncThenLoad() {
  if (logMode === 'file') return loadLog();
  return syncLog().then(function () { return loadLog(); });
}

// ---- top sites (permanent rollup; MySQL only) ----
function loadStats() {
  var tbody = document.getElementById('statRows');
  if (!tbody) return;
  var days = document.getElementById('statsRange').value;
  var q = document.getElementById('statsFilter').value.trim();
  var group = document.getElementById('statsGroup').checked ? 'base' : 'full';
  dnsLog({ action: 'stats', days: days, q: q, group: group, limit: 50 }).then(function (d) {
    var alertEl = document.getElementById('statsAlert');
    if (!d || !d.ok) {
      tbody.innerHTML = '<tr><td colspan="3" class="muted">' +
        esc((d && d.db === false) ? 'Top sites need MySQL (not configured)' : ((d && d.error) || 'error')) + '</td></tr>';
      document.getElementById('statsTotal').textContent = '';
      if (alertEl) alertEl.hidden = true;
      return;
    }
    // Gambling alert banner (info/warning) — driven by the server-side summary over the whole range.
    var g = d.gambling || { count: 0, visits: 0, sites: [] };
    if (alertEl) {
      if (g.count > 0) {
        var names = (g.sites || []).map(function (s) { return esc(s[0]); });
        var shown = names.slice(0, 8).join(', ');
        if (names.length > 8) shown += ', +' + (names.length - 8) + ' more';
        alertEl.className = 'alert warn';
        alertEl.innerHTML = '🎲 <b>GL related activity</b> — ' + g.count +
          ' site(s), ' + (g.visits || 0).toLocaleString() + ' visits: ' + shown;
        alertEl.hidden = false;
      } else {
        alertEl.hidden = true;
      }
    }
    var top = d.top || [];
    var max = top.length ? top[0][1] : 0;
    var rows = '';
    for (var i = 0; i < top.length; i++) {
      var dom = top[i][0], hits = top[i][1], gambling = top[i][2];
      var pct = max ? Math.round(hits * 100 / max) : 0;
      var tag = gambling ? ' <span class="gtag">🎲 GL</span>' : '';
      rows += '<tr' + (gambling ? ' class="g-row"' : '') + '><td class="l-rank">' + (i + 1) + '</td>' +
        '<td><span class="bar" style="width:' + pct + '%"></span><span class="bar-label">' + esc(dom) + tag + '</span></td>' +
        '<td class="l-hits">' + hits.toLocaleString() + '</td></tr>';
    }
    tbody.innerHTML = rows || '<tr><td colspan="3" class="muted">no data yet — let some queries roll up</td></tr>';
    document.getElementById('statsTotal').textContent = (d.total || 0).toLocaleString() + ' visits';
  });
}

// File-tail fallback — used only when MySQL isn't configured: read queries.log via the agent.
function loadLogFile() {
  var tbody = document.getElementById('logRows');
  execCmd("Get-Content -LiteralPath '" + logPath() + "' -Tail 400 -Encoding utf8 -ErrorAction SilentlyContinue", 'powershell')
    .then(function (d) {
      if (!d.ok) { tbody.innerHTML = '<tr><td colspan="5" class="muted">' + esc(d.error || 'error') + '</td></tr>'; return; }
      logMode = 'file'; logCursor = null;
      logData = parseLog(d.stdout || '');
      renderLog();
    });
}

// Load the newest page from the DB; transparently fall back to the file tail if no DB.
function loadLog() {
  logCursor = null;
  var q = document.getElementById('logFilter').value.trim();
  return dnsLog({ action: 'query', q: q, limit: 200 }).then(function (d) {
    if (d && d.ok && d.db) {
      logMode = 'db';
      logData = d.rows || [];
      logCursor = d.next_before_id || null;
      renderLog();
    } else if (d && d.db === false) {
      loadLogFile();                         // DB off -> agent file tail
    } else {
      document.getElementById('logRows').innerHTML =
        '<tr><td colspan="5" class="muted">' + esc((d && d.error) || 'error') + '</td></tr>';
    }
  }).catch(function () { loadLogFile(); });
}

// Append the next older page (DB mode only).
function loadMore() {
  if (logMode !== 'db' || !logCursor) return;
  var q = document.getElementById('logFilter').value.trim();
  dnsLog({ action: 'query', q: q, limit: 200, before_id: logCursor }).then(function (d) {
    if (d && d.ok && d.db) {
      logData = logData.concat(d.rows || []);
      logCursor = d.next_before_id || null;
      renderLog();
    }
  });
}

function renderLog() {
  var tbody = document.getElementById('logRows');
  // DB rows arrive already server-filtered; in file mode filter the tail client-side.
  var f = (logMode === 'db') ? '' : document.getElementById('logFilter').value.toLowerCase();
  var rows = '';
  var shown = 0;
  for (var i = 0; i < logData.length; i++) {
    var r = logData[i];
    if (f && (r.join(' ')).toLowerCase().indexOf(f) === -1) continue;
    var disp = r[4] || '';
    var cls = /BLOCKED/.test(disp) ? 'd-block' : (/NXDOMAIN/.test(disp) ? 'd-nx' : (/^LOCAL/.test(disp) ? 'd-local' : 'd-fwd'));
    rows += '<tr><td class="l-time">' + esc(r[0] || '') + '</td><td class="l-client">' + esc(r[1] || '') +
      '</td><td>' + esc(r[2] || '') + '</td><td class="l-type">' + esc(r[3] || '') +
      '</td><td class="l-disp ' + cls + '">' + esc(disp) + '</td></tr>';
    shown++;
  }
  if (logMode === 'db' && logCursor) {
    rows += '<tr class="log-more"><td colspan="5"><button class="btn ghost" id="logMore">Load older ↓</button></td></tr>';
  }
  tbody.innerHTML = rows || '<tr><td colspan="5" class="muted">no entries</td></tr>';
  var more = document.getElementById('logMore');
  if (more) more.onclick = loadMore;
}

function clearLog() {
  if (logMode === 'db') {
    if (!confirm('Clear the stored query history for this PC? (the live log file on the PC is unaffected)')) return;
    dnsLog({ action: 'clear' }, true).then(function (d) {
      if (d && d.ok) { toast('Query history cleared', true); loadLog(); }
      else { toast('Clear failed: ' + ((d && d.error) || 'error'), false); }
    });
  } else {
    if (!confirm('Clear the query log on this PC?')) return;
    execCmd("if (Test-Path -LiteralPath '" + logPath() + "') { Clear-Content -LiteralPath '" + logPath() + "' }", 'powershell')
      .then(function () { loadLogFile(); });
  }
}

// ---- this machine's IP addresses (what clients point their DNS at) ----
function renderIps(ips) {
  var el = document.getElementById('dnsIps');
  if (!ips || !ips.length) { el.textContent = 'unknown'; return; }
  el.innerHTML = '';
  ips.forEach(function (line) {
    var parts = String(line).split('|');
    var ip = parts[0], alias = parts[1] || '';
    var chip = document.createElement('span');
    chip.className = 'ip-chip';
    chip.title = 'Click to copy';
    chip.innerHTML = '<b>' + esc(ip) + '</b>' + (alias ? ' <span class="muted">· ' + esc(alias) + '</span>' : '') + ' <span class="copy">⧉</span>';
    chip.onclick = function () {
      navigator.clipboard.writeText(ip).then(function () {
        var c = chip.querySelector('.copy'); var old = c.textContent;
        c.textContent = '✓ copied'; setTimeout(function () { c.textContent = old; }, 1200);
      });
    };
    el.appendChild(chip);
  });
}

// ---- ONE PowerShell round-trip that returns everything the page needs ----
// Folder, IP list, task status, blocklist + records text and the query-log tail come back
// as a single JSON blob — instead of 5-6 separate commands each cold-starting powershell.exe.
// If no folder override is given, the agent finds it from the TinyDNS task's exe path itself.
function psStr(s) { return "'" + String(s).replace(/'/g, "''") + "'"; }
function buildLoadScript(override) {
  return [
    "$ErrorActionPreference='SilentlyContinue'",
    "$task=" + psStr(DNS_TASK),
    "$override=" + psStr(override || ''),
    // Auto-detect the DNS folder RELATIVE TO THE RUNNING dnl.exe: prefer the live process's own
    // path, then the TinyDNS task's exe path, then the configured fallback. (Override wins if given.)
    "$t=Get-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue",
    "if ($override) { $dir=$override } else {",
    "  $pname=if ($t) { [IO.Path]::GetFileNameWithoutExtension($t.Actions.Execute) } else { 'dnl' }",
    "  $proc=Get-Process -Name $pname -ErrorAction SilentlyContinue | Select-Object -First 1",
    "  if ($proc -and $proc.Path) { $dir=Split-Path -Parent $proc.Path }",
    "  elseif ($t) { $dir=Split-Path -Parent $t.Actions.Execute }",
    "}",
    "if (-not $dir) { $dir=" + psStr(DNS_DIR) + " }",
    // NB: read inline with [IO.File]::ReadAllText — do NOT define a helper named 'rd',
    // it's a built-in alias for Remove-Item (aliases outrank functions) and would DELETE the files.
    "$bp=Join-Path $dir 'blocklist.txt'",
    "$rp=Join-Path $dir 'records.txt'",
    "$block=if (Test-Path -LiteralPath $bp) { [IO.File]::ReadAllText($bp) } else { '' }",
    "$rec=if (Test-Path -LiteralPath $rp) { [IO.File]::ReadAllText($rp) } else { '' }",
    "$ips=@(Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } | ForEach-Object { $_.IPAddress + '|' + $_.InterfaceAlias })",
    // status = is the DNS process actually alive (task- OR manually-started), not just task state.
    "if (-not $t) { $t=Get-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue }",
    "$exe=if ($t) { Split-Path -Leaf $t.Actions.Execute } else { 'dnl.exe' }",
    "$pname=[IO.Path]::GetFileNameWithoutExtension($exe)",
    "$alive=@(Get-Process -Name $pname -ErrorAction SilentlyContinue).Count -gt 0",
    "$st='unknown'",
    "$q=schtasks /query /tn $task /fo list 2>$null",
    "if ($alive) { $st='running' } elseif ($LASTEXITCODE -eq 0) { $st='stopped' } else { $st='notask' }",
    // current upstream forwarder = the --upstream value in the task args (blank = built-in default)
    "$ua=if ($t -and $t.Actions -and $t.Actions[0].Arguments) { [string]$t.Actions[0].Arguments } else { '' }",
    "$up=''; if ($ua -match '--upstream\\s+(\\S+)') { $up=$matches[1] }",
    "ConvertTo-Json ([ordered]@{ dir=$dir; block=$block; rec=$rec; ips=$ips; status=$st; upstream=$up }) -Compress -Depth 3"
  ].join("\n");
}
function applyBundle(d) {
  if (!d || !d.ok) { msg('Load failed: ' + ((d && d.error) || 'agent offline')); return; }
  var j = null;
  try { j = JSON.parse((d.stdout || '').trim()); } catch (e) {}
  if (!j) { msg('Could not parse DNS data from the agent.'); return; }
  if (j.dir) { dnsDirInput.value = j.dir; cacheDir(j.dir); }
  document.getElementById('blockText').value = j.block || '';
  document.getElementById('recText').value = j.rec || '';
  var upEl = document.getElementById('upstreamText');
  if (upEl && typeof j.upstream !== 'undefined') upEl.value = j.upstream || '';
  renderIps(j.ips || []);
  setStatus(j.status);
  // ingest new queries (+ roll up), show history, then refresh the top-sites stats
  Promise.resolve(syncThenLoad()).then(loadStats);
  msg('');
}

function loadAll() {
  msg('Loading DNS data…');
  // dir() = whatever's in the field (cached folder, or user override). Empty => agent auto-detects.
  return execCmd(buildLoadScript(dir()), 'powershell').then(applyBundle);
}

function refreshHostInfo() {
  getJSON('info').then(function (d) {
    document.getElementById('hostinfo').textContent = d.ok ? (d.host + ' · ' + d.user) : ('⚠ ' + (d.error || 'offline'));
  });
}

// ---- wiring ----
function selectAgent(id) {
  state.agent = id;
  try { localStorage.setItem('rmd_agent', id); } catch (e) {}
  dnsDirInput.value = '';   // blank => auto-detect relative to the running dnl.exe (no stale cache)
  refreshHostInfo();
  loadAll();
}
agentSel.onchange = function () { selectAgent(this.value); };
document.getElementById('btnReload').onclick = loadAll;
document.getElementById('btnStatus').onclick = refreshStatus;
document.getElementById('saveBlock').onclick = function () { saveFile(blockPath(), 'blockText', 'Block list'); };
document.getElementById('saveRec').onclick = function () { saveFile(recPath(), 'recText', 'Routing'); };
document.getElementById('saveUpstream').onclick = applyUpstream;
document.getElementById('upstreamPreset').onchange = function () { if (this.value) document.getElementById('upstreamText').value = this.value; this.selectedIndex = 0; };
document.getElementById('btnStart').onclick = function () { msg('Starting…'); execCmd('schtasks /run /tn "' + DNS_TASK + '"').then(function () { setTimeout(refreshStatus, 1200); }); };
document.getElementById('btnStop').onclick = function () { msg('Stopping…'); execCmd(stopScript() + "\n'stopped'", 'powershell').then(function () { setTimeout(refreshStatus, 1200); }); };
document.getElementById('btnRestart').onclick = function () { msg('Restarting…'); execCmd(stopScript() + "\nStart-Sleep -Milliseconds 700\nschtasks /run /tn $task 2>$null | Out-Null\n'ok'", 'powershell').then(function () { setTimeout(refreshStatus, 1800); }); };
document.getElementById('btnLookup').onclick = function () {
  var dom = document.getElementById('lookupDomain').value.trim(); if (!dom) return;
  var out = document.getElementById('lookupOut'); out.textContent = 'looking up ' + dom + ' …';
  execCmd('nslookup ' + dom + ' 127.0.0.1').then(function (d) { out.textContent = (d.stdout || '') + (d.stderr || ''); });
};
document.getElementById('btnLogRefresh').onclick = syncThenLoad;
document.getElementById('btnLogClear').onclick = clearLog;
var logFilterTimer = null;
document.getElementById('logFilter').oninput = function () {
  clearTimeout(logFilterTimer);
  // DB mode: filter runs server-side over ALL history (debounced). File mode: client-side.
  if (logMode === 'db') logFilterTimer = setTimeout(loadLog, 300);
  else renderLog();
};
document.getElementById('logAuto').onchange = function () {
  if (this.checked) { autoTimer = setInterval(syncThenLoad, 5000); } else { clearInterval(autoTimer); autoTimer = null; }
};
document.getElementById('btnStatsRefresh').onclick = loadStats;
document.getElementById('statsRange').onchange = loadStats;
document.getElementById('statsGroup').onchange = loadStats;
var statsFilterTimer = null;
document.getElementById('statsFilter').oninput = function () {
  clearTimeout(statsFilterTimer); statsFilterTimer = setTimeout(loadStats, 300);
};

// ---- boot ----
getJSON('agents').then(function (d) {
  agentSel.innerHTML = '';
  if (!d.ok || !d.agents.length) { msg('No PCs connected — run the agent on the DNS machine.'); return; }
  d.agents.forEach(function (a) {
    var o = document.createElement('option');
    o.value = a.id;
    o.textContent = (a.online ? '🟢 ' : '⚪ ') + a.name + (a.online ? '' : ' (offline)');
    agentSel.appendChild(o);
  });
  var saved = null; try { saved = localStorage.getItem('rmd_agent'); } catch (e) {}
  var pick = (saved && d.agents.some(function (a) { return a.id === saved; })) ? saved : d.agents[0].id;
  agentSel.value = pick;
  selectAgent(pick);
});
