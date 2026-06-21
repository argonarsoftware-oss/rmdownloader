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
dnsDirInput.value = DNS_DIR;

function dir() { return dnsDirInput.value.replace(/[\\\/]+$/, ''); }
function recPath() { return dir() + '\\records.txt'; }
function blockPath() { return dir() + '\\blocklist.txt'; }
function logPath() { return dir() + '\\queries.log'; }

function execCmd(cmd, shell) { return post('exec', null, { cmd: cmd, shell: shell || 'cmd' }); }

function loadFile(path, ta) {
  msg('Loading ' + path + ' …');
  return getJSON('read', { path: path }).then(function (d) {
    document.getElementById(ta).value = d.ok ? d.content : '';
    msg(d.ok ? '' : ('Could not read ' + path + ': ' + (d.error || '')));
  });
}
function saveFile(path, ta, label) {
  msg('Saving ' + label + ' …');
  return post('save', { path: path }, { content: document.getElementById(ta).value })
    .then(function (d) { msg(d.ok ? (label + ' saved — DNS hot-reloads it live.') : ('Error: ' + (d.error || ''))); });
}

function refreshStatus() {
  var el = document.getElementById('dnsStatus');
  el.textContent = 'DNS: …';
  execCmd('schtasks /query /tn "' + DNS_TASK + '" /fo list').then(function (d) {
    var s = 'unknown';
    if (d.ok && /Status:\s*Running/i.test(d.stdout)) s = '🟢 running';
    else if (d.ok && /Status:\s*Ready/i.test(d.stdout)) s = '🔴 stopped';
    else if ((d.stdout || '') .match(/ERROR/i) || (d.stderr || '').match(/ERROR/i)) s = '⚠ no task';
    el.textContent = 'DNS: ' + s;
  });
}

// ---- query log ----
function loadLog() {
  var tbody = document.getElementById('logRows');
  execCmd("Get-Content -LiteralPath '" + logPath() + "' -Tail 400 -Encoding utf8 -ErrorAction SilentlyContinue", 'powershell')
    .then(function (d) {
      if (!d.ok) { tbody.innerHTML = '<tr><td colspan="5" class="muted">' + esc(d.error || 'error') + '</td></tr>'; return; }
      var lines = (d.stdout || '').split(/\r?\n/).filter(function (l) { return l.indexOf('\t') > -1; });
      logData = lines.map(function (l) { return l.split('\t'); }).reverse(); // newest first
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
function loadIps() {
  var el = document.getElementById('dnsIps');
  el.textContent = 'detecting…';
  var ps = "Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | " +
    "Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } | " +
    "ForEach-Object { $_.IPAddress + '|' + $_.InterfaceAlias }";
  execCmd(ps, 'powershell').then(function (d) {
    var ips = (d.stdout || '').split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean);
    if (!ips.length) { el.textContent = 'unknown'; return; }
    el.innerHTML = '';
    ips.forEach(function (line) {
      var parts = line.split('|');
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
  });
}

function loadAll() {
  loadFile(blockPath(), 'blockText');
  loadFile(recPath(), 'recText');
  refreshStatus();
  loadIps();
  loadLog();
}

function refreshHostInfo() {
  getJSON('info').then(function (d) {
    document.getElementById('hostinfo').textContent = d.ok ? (d.host + ' · ' + d.user) : ('⚠ ' + (d.error || 'offline'));
  });
}

// ---- wiring ----
agentSel.onchange = function () {
  state.agent = this.value;
  try { localStorage.setItem('rmd_agent', this.value); } catch (e) {}
  refreshHostInfo();
  loadAll();
};
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
  state.agent = pick;
  agentSel.value = pick;
  try { localStorage.setItem('rmd_agent', pick); } catch (e) {}
  refreshHostInfo();
  loadAll();
});
