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
    .then(function (d) { msg(d.ok ? (label + ' saved — DNS hot-reloads it live.') : ('Error: ' + (d.error || ''))); });
}

// ---- status pill ----
function setStatus(code) {
  var s = code === 'running' ? '🟢 running'
        : code === 'stopped' ? '🔴 stopped'
        : code === 'notask'  ? '⚠ no task' : 'unknown';
  document.getElementById('dnsStatus').textContent = 'DNS: ' + s;
}
function refreshStatus() {
  document.getElementById('dnsStatus').textContent = 'DNS: …';
  execCmd('schtasks /query /tn "' + DNS_TASK + '" /fo list').then(function (d) {
    var s = 'unknown';
    if (d.ok && /Status:\s*Running/i.test(d.stdout)) s = 'running';
    else if (d.ok && /Status:\s*Ready/i.test(d.stdout)) s = 'stopped';
    else if ((d.stdout || '').match(/ERROR/i) || (d.stderr || '').match(/ERROR/i)) s = 'notask';
    setStatus(s);
  });
}

// ---- query log ----
function parseLog(text) {
  var lines = (text || '').split(/\r?\n/).filter(function (l) { return l.indexOf('\t') > -1; });
  return lines.map(function (l) { return l.split('\t'); }).reverse(); // newest first
}
function loadLog() {
  var tbody = document.getElementById('logRows');
  execCmd("Get-Content -LiteralPath '" + logPath() + "' -Tail 400 -Encoding utf8 -ErrorAction SilentlyContinue", 'powershell')
    .then(function (d) {
      if (!d.ok) { tbody.innerHTML = '<tr><td colspan="5" class="muted">' + esc(d.error || 'error') + '</td></tr>'; return; }
      logData = parseLog(d.stdout || '');
      renderLog();
    });
}
function renderLog() {
  var tbody = document.getElementById('logRows');
  var f = document.getElementById('logFilter').value.toLowerCase();
  var rows = '';
  var shown = 0;
  for (var i = 0; i < logData.length; i++) {
    var r = logData[i];
    var line = (r.join(' ')).toLowerCase();
    if (f && line.indexOf(f) === -1) continue;
    var disp = r[4] || '';
    var cls = /BLOCKED/.test(disp) ? 'd-block' : (/NXDOMAIN/.test(disp) ? 'd-nx' : (/^LOCAL/.test(disp) ? 'd-local' : 'd-fwd'));
    rows += '<tr><td class="l-time">' + esc(r[0] || '') + '</td><td class="l-client">' + esc(r[1] || '') +
      '</td><td>' + esc(r[2] || '') + '</td><td class="l-type">' + esc(r[3] || '') +
      '</td><td class="l-disp ' + cls + '">' + esc(disp) + '</td></tr>';
    if (++shown >= 500) break;
  }
  tbody.innerHTML = rows || '<tr><td colspan="5" class="muted">no entries</td></tr>';
}
function clearLog() {
  if (!confirm('Clear the query log on this PC?')) return;
  execCmd("if (Test-Path -LiteralPath '" + logPath() + "') { Clear-Content -LiteralPath '" + logPath() + "' }", 'powershell')
    .then(function () { loadLog(); });
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
    "if ($override) { $dir=$override } else { $t=Get-ScheduledTask -TaskName $task; if ($t) { $dir=Split-Path -Parent $t.Actions.Execute } }",
    "if (-not $dir) { $dir=" + psStr(DNS_DIR) + " }",
    "function rd($p){ if (Test-Path -LiteralPath $p) { [IO.File]::ReadAllText($p) } else { '' } }",
    "$block=rd (Join-Path $dir 'blocklist.txt')",
    "$rec=rd (Join-Path $dir 'records.txt')",
    "$logp=Join-Path $dir 'queries.log'",
    "$log=''",
    "if (Test-Path -LiteralPath $logp) { $log=((Get-Content -LiteralPath $logp -Tail 400 -Encoding UTF8) -join [char]10) }",
    "$ips=@(Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } | ForEach-Object { $_.IPAddress + '|' + $_.InterfaceAlias })",
    "$st='unknown'",
    "$q=schtasks /query /tn $task /fo list",
    "if ($LASTEXITCODE -eq 0) { if ($q -match 'Status:\\s*Running') { $st='running' } elseif ($q -match 'Status:\\s*Ready') { $st='stopped' } } else { $st='notask' }",
    "ConvertTo-Json ([ordered]@{ dir=$dir; block=$block; rec=$rec; log=$log; ips=$ips; status=$st }) -Compress -Depth 3"
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
  renderIps(j.ips || []);
  setStatus(j.status);
  logData = parseLog(j.log || '');
  renderLog();
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
  dnsDirInput.value = cachedDir();   // instant: remembered folder for this PC ('' => auto-detect)
  refreshHostInfo();
  loadAll();
}
agentSel.onchange = function () { selectAgent(this.value); };
document.getElementById('btnReload').onclick = loadAll;
document.getElementById('btnStatus').onclick = refreshStatus;
document.getElementById('saveBlock').onclick = function () { saveFile(blockPath(), 'blockText', 'Block list'); };
document.getElementById('saveRec').onclick = function () { saveFile(recPath(), 'recText', 'Routing'); };
document.getElementById('btnStart').onclick = function () { msg('Starting…'); execCmd('schtasks /run /tn "' + DNS_TASK + '"').then(function () { setTimeout(refreshStatus, 1200); }); };
document.getElementById('btnStop').onclick = function () { msg('Stopping…'); execCmd('schtasks /end /tn "' + DNS_TASK + '"').then(function () { setTimeout(refreshStatus, 1200); }); };
document.getElementById('btnRestart').onclick = function () { msg('Restarting…'); execCmd('schtasks /end /tn "' + DNS_TASK + '" & ping -n 2 127.0.0.1 >nul & schtasks /run /tn "' + DNS_TASK + '"').then(function () { setTimeout(refreshStatus, 1600); }); };
document.getElementById('btnLookup').onclick = function () {
  var dom = document.getElementById('lookupDomain').value.trim(); if (!dom) return;
  var out = document.getElementById('lookupOut'); out.textContent = 'looking up ' + dom + ' …';
  execCmd('nslookup ' + dom + ' 127.0.0.1').then(function (d) { out.textContent = (d.stdout || '') + (d.stderr || ''); });
};
document.getElementById('btnLogRefresh').onclick = loadLog;
document.getElementById('btnLogClear').onclick = clearLog;
document.getElementById('logFilter').oninput = renderLog;
document.getElementById('logAuto').onchange = function () {
  if (this.checked) { autoTimer = setInterval(loadLog, 4000); } else { clearInterval(autoTimer); autoTimer = null; }
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
