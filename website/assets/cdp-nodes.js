// Independent chnav nodes UI — reads node status / nav feed / rules from MySQL via cdp-log.php
// (data pushed by chnav with no agent). Rules saved here land in cdp_rules; chnav pulls them.
'use strict';

var state = { node: null, nodes: [], cursor: null, feed: [], autoTimer: null };

function cdp(params, post) {
  var u = 'cdp-log.php?';
  var first = true;
  for (var k in params) { u += (first ? '' : '&') + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); first = false; }
  return fetch(u, { method: post ? 'POST' : 'GET', credentials: 'same-origin', body: post || undefined })
    .then(function (r) { if (r.status === 401) { location.href = 'login.php'; throw new Error('auth'); } return r.json(); });
}
function form(obj) { var f = new FormData(); for (var k in obj) f.append(k, obj[k]); return f; }
function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function msg(t) { document.getElementById('msg').textContent = t || ''; }

// "2026-06-24 14:06:31" -> "Jun 24 at 2:06:31pm"  (string-parsed, no TZ shift)
var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
function fmtTs(s) {
  var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/.exec(String(s || ''));
  if (!m) return s || '';
  var h = +m[4], ap = h < 12 ? 'am' : 'pm', h12 = h % 12 || 12;
  return (MON[(+m[2]) - 1] || m[2]) + ' ' + (+m[3]) + ' at ' + h12 + ':' + m[5] + (m[6] ? ':' + m[6] : '') + ap;
}

// host of a url or "url|title" tab string, www-stripped
function hostOf(u) {
  u = String(u || '').split('|')[0];
  var m = /^[a-z][a-z0-9+.-]*:\/\/([^\/:?#]+)/i.exec(u);
  var h = m ? m[1] : u.split(/[\/:?#]/)[0];
  return (h || '').replace(/^www\./i, '');
}
// per-browser device labels (no server storage — locked-down DB user can't ALTER)
function aliasMap() { try { return JSON.parse(localStorage.getItem('rmd_cdpalias') || '{}') || {}; } catch (e) { return {}; } }
function aliasGet(id) { return aliasMap()[id] || ''; }
function aliasSet(id, v) { var m = aliasMap(); if (v) m[id] = v; else delete m[id]; try { localStorage.setItem('rmd_cdpalias', JSON.stringify(m)); } catch (e) {} }

// ---- node rail (sidebar device picker) ----
function loadNodes(preferId) {
  return cdp({ action: 'nodes' }).then(function (d) {
    if (!d.ok || !d.nodes || !d.nodes.length) {
      state.nodes = []; state.node = null;
      renderRail();
      msg(d && d.db === false ? 'MySQL not configured — independent nodes need the database.' : 'No chnav nodes have reported yet.');
      return;
    }
    state.nodes = d.nodes;
    var saved = null; try { saved = localStorage.getItem('rmd_cdpnode'); } catch (e) {}
    var prefer = preferId || state.node || saved;
    var has = function (id) { return d.nodes.some(function (n) { return n.id === id; }); };
    var pick = (prefer && has(prefer)) ? prefer : d.nodes[0].id;
    if (pick !== state.node) {
      selectNode(pick);          // selection changed → load that node's rules + feed
    } else {
      renderRail();              // same node on refresh → update statuses in place,
      renderStatus();            // WITHOUT reloading rules (no clobber) or resetting feed paging
    }
  });
}

function renderRail() {
  var el = document.getElementById('nodeRailList');
  if (!state.nodes.length) { el.innerHTML = '<div class="rail-empty muted">no nodes</div>'; return; }
  var html = '';
  state.nodes.forEach(function (n) {
    var on = n.online;
    var alias = aliasGet(n.id);
    var label = alias || n.name || n.id;

    // open-tab hosts (unique), 🎲 if the server flagged a gambling tab
    var hosts = [];
    (n.tabs || []).forEach(function (t) {
      var h = hostOf(typeof t === 'string' ? t : (t && t[0]) || '');
      if (h && hosts.indexOf(h) < 0) hosts.push(h);
    });
    var tabsLine = hosts.length
      ? (n.gl ? '🎲 ' : '') + hosts.slice(0, 3).join(' · ') + (hosts.length > 3 ? ' +' + (hosts.length - 3) : '')
      : '(no tabs)';
    var health = 'Chrome ' + (n.chrome || '—') + ' · chnav ' + (n.running ? 'on' : 'off') + ' · ' + (on ? 'online' : agoStr(n.age));
    var lastHost = hostOf(n.last_url);

    html += '<div class="rail-node' + (n.id === state.node ? ' selected' : '') + '" data-id="' + esc(n.id) + '" title="' + esc(n.id) + '">' +
      '<div class="rn-top"><div class="rn-name">' + (on ? '🟢' : '⚪') + ' <span>' + esc(label) + '</span></div>' +
        '<button class="rn-edit" data-alias="' + esc(n.id) + '" title="Label this device (this browser only)">✎</button></div>' +
      (alias ? '<div class="rn-id">' + esc(n.name || n.id) + '</div>' : '') +
      '<div class="rn-line rn-health">' + esc(health) + '</div>' +
      '<div class="rn-line rn-tabs' + (n.gl ? ' gl' : '') + '">' + esc(tabsLine) + '</div>' +
      (lastHost ? '<div class="rn-line rn-last">→ ' + esc(lastHost) + '</div>' : '') +
      '</div>';
  });
  el.innerHTML = html;
}

function agoStr(age) {
  age = +age || 0;
  if (age < 90) return age + 's ago';
  if (age < 5400) return Math.round(age / 60) + 'm ago';
  if (age < 172800) return Math.round(age / 3600) + 'h ago';
  return Math.round(age / 86400) + 'd ago';
}

function curNode() {
  for (var i = 0; i < state.nodes.length; i++) if (state.nodes[i].id === state.node) return state.nodes[i];
  return null;
}

function selectNode(id) {
  state.node = id;
  try { localStorage.setItem('rmd_cdpnode', id); } catch (e) {}
  renderRail();
  renderStatus();
  loadRules();
  loadFeed(true);
}

function renderStatus() {
  var n = curNode();
  if (!n) return;
  document.getElementById('nodeInfo').textContent = n.id;
  var st = (n.online ? '🟢 online' : '⚪ ' + agoStr(n.age)) +
    '   ·   chnav: ' + (n.running ? 'running' : 'stopped') +
    '   ·   Chrome: ' + (n.chrome || '—');
  document.getElementById('nodeStatus').textContent = st;
  var tabs = n.tabs || [];
  document.getElementById('tabCount').textContent = tabs.length + ' tab(s)';
  var tl = document.getElementById('tabList');
  if (!tabs.length) { tl.innerHTML = '<span class="muted">—</span>'; return; }
  tl.innerHTML = '';
  tabs.forEach(function (t) {
    var p = String(t).split('|');
    var chip = document.createElement('span');
    chip.className = 'tab-chip';
    chip.innerHTML = '<b>' + esc((p[1] || p[0] || '').slice(0, 60)) + '</b>';
    chip.title = p[0] || '';
    tl.appendChild(chip);
  });
}

// ---- rules editor ----
function loadRules() {
  cdp({ action: 'rules', node: state.node }).then(function (d) {
    if (!d.ok) return;
    document.getElementById('rulesText').value = d.rules || '';
    document.getElementById('rulesVer').textContent = 'v' + (d.version || 0);
  });
}
document.getElementById('saveRules').onclick = function () {
  if (!state.node) return;
  msg('Saving rules…');
  cdp({ action: 'saverules', node: state.node }, form({ rules: document.getElementById('rulesText').value })).then(function (d) {
    if (d.ok) { document.getElementById('rulesVer').textContent = 'v' + d.version; msg('Rules saved — chnav will pull them shortly.'); }
    else { msg('Save failed: ' + (d.error || '')); }
  });
};

// ---- collapsible Site rules (default collapsed, choice persisted) ----
(function () {
  var card = document.getElementById('rulesCard'), head = document.getElementById('rulesHead');
  if (!card || !head) return;
  try { if (localStorage.getItem('rmd_cdprules_open') === '1') card.classList.remove('collapsed'); } catch (e) {}
  head.addEventListener('click', function (e) {
    if (e.target.closest('button')) return; // don't toggle when hitting Save
    var open = card.classList.toggle('collapsed') === false;
    try { localStorage.setItem('rmd_cdprules_open', open ? '1' : '0'); } catch (e2) {}
  });
})();

// ---- navigation feed ----
var KNOWN = { NAV: 1, SPA: 1, DOC: 1, req: 1, BLOCK: 1, WARN: 1, REPLACE: 1, REDIRECT: 1 };
function loadFeed(reset) {
  if (!state.node) return;
  if (reset) state.cursor = null;
  var p = { action: 'feed', node: state.node, q: document.getElementById('feedFilter').value.trim(), limit: 300 };
  if (!reset && state.cursor) p.before_id = state.cursor;
  return cdp(p).then(function (d) {
    if (!d.ok) { msg('Feed error: ' + (d.error || '')); return; }
    state.feed = reset ? (d.rows || []) : state.feed.concat(d.rows || []);
    state.cursor = d.next_before_id || null;
    renderFeed();
  });
}
function renderFeed() {
  var rows = '';
  var gl = 0;
  state.feed.forEach(function (r) {
    var type = r[1] || '', cls = KNOWN[type] ? 'd-' + type.toLowerCase() : 'd-info';
    var isGl = r[5] ? 1 : 0; if (isGl) gl++;
    var badge = isGl ? ' <span class="gtag">🎲 GL</span>' : '';
    rows += '<tr' + (isGl ? ' class="g-row"' : '') + '><td class="l-time">' + esc(fmtTs(r[0])) +
      '</td><td class="l-type ' + cls + '">' + esc(type) + '</td><td>' + esc(r[2] || '') + badge + '</td></tr>';
  });
  document.getElementById('feedRows').innerHTML = rows || '<tr><td colspan="3" class="muted">no events yet</td></tr>';
  document.getElementById('feedMore').hidden = !state.cursor;
  var al = document.getElementById('feedAlert');
  if (gl > 0) { al.className = 'alert warn'; al.innerHTML = '🎲 <b>GL related activity</b> — ' + gl + ' navigation(s) to GL domains in this view.'; al.hidden = false; }
  else { al.hidden = true; }
}

// ---- wiring ----
document.getElementById('nodeRailList').addEventListener('click', function (e) {
  var edit = e.target.closest('.rn-edit');
  if (edit) {
    e.stopPropagation();
    var aid = edit.getAttribute('data-alias');
    var v = prompt('Label for this device (blank to clear). Saved in this browser only.', aliasGet(aid));
    if (v !== null) { aliasSet(aid, v.trim()); renderRail(); }
    return;
  }
  var row = e.target.closest('[data-id]');
  if (row) { var id = row.getAttribute('data-id'); if (id && id !== state.node) selectNode(id); }
});
document.getElementById('btnRefresh').onclick = function () { loadNodes(); };
document.getElementById('btnFeedRefresh').onclick = function () { loadFeed(true); };
document.getElementById('feedMore').onclick = function () { loadFeed(false); };
var ft = null;
document.getElementById('feedFilter').oninput = function () { clearTimeout(ft); ft = setTimeout(function () { loadFeed(true); }, 300); };
document.getElementById('feedAuto').onchange = function () {
  // live mode: refresh node statuses in place + pull newest feed (no rules clobber)
  if (this.checked) { state.autoTimer = setInterval(function () { loadNodes(); loadFeed(true); }, 5000); }
  else { clearInterval(state.autoTimer); state.autoTimer = null; }
};
document.getElementById('btnFeedClear').onclick = function () {
  if (!state.node || !confirm('Clear stored navigation events for this node?')) return;
  cdp({ action: 'clear', node: state.node }, form({})).then(function (d) {
    if (d.ok) { state.feed = []; renderFeed(); msg('Cleared.'); } else { msg('Clear failed: ' + (d.error || '')); }
  });
};

loadNodes();
