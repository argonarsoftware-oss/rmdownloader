// Dedicated full-page DNS query log. Reads history from dns-log.php (MySQL) with keyset paging,
// triggers a fresh ingest via dns-sync.php, and lets you filter / page / clear.
'use strict';

var state = { agent: null };
var rows = [];
var cursor = null;        // next_before_id for "Load older"
var autoTimer = null;

function dnsLog(params, post) {
  var u = 'dns-log.php?agent=' + encodeURIComponent(state.agent || '');
  for (var k in params) u += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
  return fetch(u, { method: post ? 'POST' : 'GET', credentials: 'same-origin' }).then(function (r) {
    if (r.status === 401) { location.href = 'login.php'; throw new Error('auth'); }
    return r.json();
  });
}
function esc(s) { return String(s).replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); }
function msg(t) { document.getElementById('qMsg').textContent = t || ''; }

// "2026-06-24 13:20:56" (box-local) -> "Jun 24 at 1:20:56pm". Parsed by string so no TZ shifting.
var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
function fmtTs(s) {
  var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/.exec(String(s || ''));
  if (!m) return s || '';
  var h = +m[4], ap = h < 12 ? 'am' : 'pm', h12 = h % 12 || 12;
  return (MON[(+m[2]) - 1] || m[2]) + ' ' + (+m[3]) + ' at ' + h12 + ':' + m[5] + (m[6] ? ':' + m[6] : '') + ap;
}

var agentSel = document.getElementById('agentSel');
function fil() { return document.getElementById('qFilter').value.trim(); }
function lim() { return document.getElementById('qLimit').value; }

function rowHtml(r) {
  var disp = r[4] || '';
  var cls = /BLOCKED/.test(disp) ? 'd-block' : (/NXDOMAIN/.test(disp) ? 'd-nx' : (/^LOCAL/.test(disp) ? 'd-local' : 'd-fwd'));
  return '<tr><td class="l-time">' + esc(fmtTs(r[0])) + '</td><td class="l-client">' + esc(r[1] || '') +
    '</td><td>' + esc(r[2] || '') + '</td><td class="l-type">' + esc(r[3] || '') +
    '</td><td class="l-disp ' + cls + '">' + esc(disp) + '</td></tr>';
}
function render() {
  document.getElementById('qRows').innerHTML = rows.length
    ? rows.map(rowHtml).join('')
    : '<tr><td colspan="5" class="muted">no entries</td></tr>';
  document.getElementById('qMore').hidden = !cursor;
  document.getElementById('qCount').textContent = rows.length.toLocaleString() + ' shown';
}

function load(reset) {
  if (reset) cursor = null;
  var p = { action: 'query', q: fil(), limit: lim() };
  if (!reset && cursor) p.before_id = cursor;
  msg('Loading…');
  return dnsLog(p).then(function (d) {
    msg('');
    if (!d || !d.ok) {
      document.getElementById('qRows').innerHTML = '<tr><td colspan="5" class="muted">' +
        esc((d && d.db === false) ? 'MySQL not configured — query history needs the database' : ((d && d.error) || 'error')) + '</td></tr>';
      document.getElementById('qMore').hidden = true;
      return;
    }
    rows = reset ? (d.rows || []) : rows.concat(d.rows || []);
    cursor = d.next_before_id || null;
    render();
  });
}

// best-effort fresh ingest (auto-detects the DNS folder server-side), then show newest
function syncThenLoad() {
  return fetch('dns-sync.php?agent=' + encodeURIComponent(state.agent || ''), { credentials: 'same-origin' })
    .catch(function () {}).then(function () { return load(true); });
}

function clearHistory() {
  if (!confirm('Clear ALL stored query history for this PC? (the live log on the PC is unaffected)')) return;
  dnsLog({ action: 'clear' }, true).then(function (d) {
    if (d && d.ok) { rows = []; cursor = null; render(); msg('History cleared.'); }
    else { msg('Clear failed: ' + ((d && d.error) || '')); }
  });
}

function refreshHostInfo() {
  fetch('api.php?action=info&agent=' + encodeURIComponent(state.agent || ''), { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) { document.getElementById('hostinfo').textContent = d.ok ? (d.host + ' · ' + d.user) : ('⚠ ' + (d.error || 'offline')); })
    .catch(function () {});
}

function selectAgent(id) {
  state.agent = id;
  try { localStorage.setItem('rmd_agent', id); } catch (e) {}
  refreshHostInfo();
  syncThenLoad();
}

agentSel.onchange = function () { selectAgent(this.value); };
document.getElementById('qRefresh').onclick = syncThenLoad;
document.getElementById('qClear').onclick = clearHistory;
document.getElementById('qMore').onclick = function () { load(false); };
var ft = null;
document.getElementById('qFilter').oninput = function () { clearTimeout(ft); ft = setTimeout(function () { load(true); }, 300); };
document.getElementById('qLimit').onchange = function () { load(true); };
document.getElementById('qAuto').onchange = function () {
  if (this.checked) { autoTimer = setInterval(syncThenLoad, 5000); } else { clearInterval(autoTimer); autoTimer = null; }
};

// boot: populate the PC picker (shared 'rmd_agent' selection with dns.php)
fetch('api.php?action=agents', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
  agentSel.innerHTML = '';
  if (!d.ok || !d.agents.length) { msg('No PCs connected.'); return; }
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
