// CDP — Chrome Navigation + content regulation. Drives the agent's exec op to run
// chnav.exe on a selected PC (monitor every tab AND enforce site rules), and tails
// its output back into the page. Same no-PHP-backend design as the DNS Manager:
// everything is PowerShell through api.php?action=exec.
'use strict';

var state = { agent: null };
var feedData = [];
var autoTimer = null;
var rulesUndo = [];        // stack of previous blt.txt snapshots (per selected PC) for multi-level undo
var savedRulesText = '';   // what we believe is currently on disk (blt.txt)

// ---- shared API helpers (same shape as dns.js / app.js) ----
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
function escAttr(s) { return esc(s).replace(/"/g, '&quot;'); }
function msg(t) { document.getElementById('cdpMsg').textContent = t || ''; }
function toast(text, ok) {
  var t = document.getElementById('cdpToast');
  if (!t) { t = document.createElement('div'); t.id = 'cdpToast'; document.body.appendChild(t); }
  t.textContent = (ok ? '✓ ' : '✕ ') + text;
  t.className = 'toast ' + (ok ? 'ok' : 'err') + ' show';
  clearTimeout(toast._t);
  toast._t = setTimeout(function () { t.className = 'toast ' + (ok ? 'ok' : 'err'); }, 2800);
}
function execCmd(cmd, shell) { return post('exec', null, { cmd: cmd, shell: shell || 'powershell' }); }
function psStr(s) { return "'" + String(s).replace(/'/g, "''") + "'"; }

var agentSel = document.getElementById('agentSel');
var dirInput = document.getElementById('cdpDir');
var portInput = document.getElementById('cdpPort');
dirInput.placeholder = CDP_DIR;
portInput.value = CDP_PORT;

function dir() { return dirInput.value.trim().replace(/[\\\/]+$/, ''); }
function port() { var p = parseInt(portInput.value, 10); return (p > 0 && p < 65536) ? p : CDP_PORT; }

// ---- dynamic folder detection (relative to the chnav.exe at runtime) ----
//   1. explicit override field, 2. a RUNNING chnav.exe's own folder,
//   3. next to the agent's own exe, 4. configured CDP_DIR / ~\Desktop\chrome-nav.
function detectDirLines(override) {
  return [
    "$dir=" + psStr(override || ''),
    "if (-not $dir) {",
    "  $m=Get-Process chnav -ErrorAction SilentlyContinue | Where-Object { $_.Path } | Select-Object -First 1",
    "  if ($m) { $dir=Split-Path -Parent $m.Path }",
    "}",
    "if (-not $dir) {",
    "  $ag=Get-Process Agent,agentsvc -ErrorAction SilentlyContinue | Where-Object { $_.Path } | Select-Object -First 1",
    "  if ($ag) { $c=Split-Path -Parent $ag.Path; if (Test-Path -LiteralPath (Join-Path $c 'chnav.exe')) { $dir=$c } }",
    "}",
    "if (-not $dir) { foreach ($c in @(" + psStr(CDP_DIR) + ",(Join-Path $env:USERPROFILE 'Desktop\\chrome-nav'))) { if ($c -and (Test-Path -LiteralPath (Join-Path $c 'chnav.exe'))) { $dir=$c; break } } }",
    "if (-not $dir) { $dir=" + psStr(CDP_DIR) + " }"
  ];
}

function cacheKey() { return 'rmd_cdpdir_' + (state.agent || ''); }
function cacheDir(d) { try { if (d) localStorage.setItem(cacheKey(), d); } catch (e) {} }

// ---- status pill ----
function setStatus(running) {
  document.getElementById('cdpStatus').textContent = 'monitor: ' + (running ? '🟢 running' : '🔴 stopped');
}

// ---- monitor control scripts ----
function startScript() {
  var lines = ["$ErrorActionPreference='SilentlyContinue'"]
    .concat(detectDirLines(dir()))
    .concat([
      "$exe=Join-Path $dir 'chnav.exe'",
      "if (-not (Test-Path -LiteralPath $exe)) { 'noexe' } else {",
      "  Get-Process chnav -ErrorAction SilentlyContinue | Stop-Process -Force",
      "  $log=Join-Path $dir 'nav.log'",
      "  Remove-Item -LiteralPath $log -ErrorAction SilentlyContinue",
      "  $a=@('--port','" + port() + "')",
      // --block enables regulation (browser-level, all tabs) from blt.txt in the folder.
      "  $a+=@('--block',(Join-Path $dir 'blt.txt'))"
    ]);
  if (document.getElementById('optRequests').checked) lines.push("  $a+='--requests'");
  // --persist = always-on enforcement (re-seize Chrome, kill non-regulated instances).
  if (document.getElementById('optEnforce') && document.getElementById('optEnforce').checked) lines.push("  $a+='--persist'");
  lines.push(
    "  Start-Process -FilePath $exe -ArgumentList $a -RedirectStandardOutput $log -RedirectStandardError (Join-Path $dir 'nav.err') -WindowStyle Hidden",
    "  Start-Sleep -Milliseconds 500",
    "  if (@(Get-Process chnav -ErrorAction SilentlyContinue).Count -gt 0) { 'started' } else { 'failed' }",
    "}"
  );
  return lines.join("\n");
}
function stopScript() {
  return "Get-Process chnav -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue\n'stopped'";
}

function startMonitor() {
  msg('Starting monitor… (Chrome will relaunch)');
  execCmd(startScript()).then(function (d) {
    var s = (d.stdout || '').trim();
    if (s.indexOf('noexe') >= 0) { toast('chnav.exe not found on this PC', false); msg('Deploy chnav.exe next to the agent, or set the folder field.'); return; }
    if (s.indexOf('started') >= 0) { toast('Monitor started', true); } else { toast('Monitor failed to start (see nav.err)', false); }
    setTimeout(loadAll, 1500);
  });
}
function stopMonitor() {
  msg('Stopping monitor…');
  execCmd(stopScript()).then(function () { toast('Monitor stopped', true); setTimeout(loadAll, 800); });
}

// ---- navigation feed (tail of nav.log) ----
var KNOWN = { NAV: 1, SPA: 1, DOC: 1, req: 1, BLOCK: 1, WARN: 1, REPLACE: 1, REDIRECT: 1 };
function parseFeed(text) {
  var out = [];
  var lines = (text || '').split(/\r?\n/);
  for (var i = 0; i < lines.length; i++) {
    var m = lines[i].match(/^\[(\d\d:\d\d:\d\d)\]\s+(\S+)\s*(.*)$/);
    if (!m) continue;
    var tag = m[2], rest = m[3];
    if (KNOWN[tag]) out.push({ time: m[1], type: tag, url: rest });
    else out.push({ time: m[1], type: 'info', url: (tag + ' ' + rest).trim() });
  }
  return out.reverse(); // newest first
}
function renderFeed() {
  var tbody = document.getElementById('feedRows');
  var f = document.getElementById('feedFilter').value.toLowerCase();
  var rows = '';
  for (var i = 0; i < feedData.length; i++) {
    var r = feedData[i];
    if (f && (r.type + ' ' + r.url).toLowerCase().indexOf(f) === -1) continue;
    var cls = 'd-' + r.type.toLowerCase();
    rows += '<tr><td class="l-time">' + esc(r.time) + '</td><td class="l-type ' + cls + '">' + esc(r.type) +
      '</td><td>' + esc(r.url) + '</td></tr>';
  }
  tbody.innerHTML = rows || '<tr><td colspan="3" class="muted">no navigation yet — Start the monitor and browse on the PC</td></tr>';
}
function clearFeed() {
  if (!confirm('Clear the navigation log (nav.log) on this PC?')) return;
  var log = (dir() || CDP_DIR) + '\\nav.log';
  execCmd("if (Test-Path -LiteralPath '" + log.replace(/'/g, "''") + "') { Clear-Content -LiteralPath '" + log.replace(/'/g, "''") + "' }")
    .then(function () { feedData = []; renderFeed(); toast('Feed cleared', true); });
}

// ---- open tabs ----
function renderTabs(targets) {
  var el = document.getElementById('tabList');
  if (!targets || !targets.length) { el.innerHTML = '<span class="muted">— no debug port / no tabs —</span>'; document.getElementById('targetCount').textContent = ''; return; }
  el.innerHTML = '';
  targets.forEach(function (line) {
    var parts = String(line).split('|');
    var url = parts[0], title = parts.slice(1).join('|') || url;
    var chip = document.createElement('span');
    chip.className = 'tab-chip';
    chip.title = url;
    chip.innerHTML = '<b>' + esc(title) + '</b>';
    el.appendChild(chip);
  });
  document.getElementById('targetCount').textContent = targets.length + ' tab(s)';
}

// ---- site rules editor (block / warn / replace) -> blt.txt ----
// Redirect is the primary action (transparent — the URL changes to the target).
var RULE_ACTIONS = [['redirect', 'Redirect to'], ['block', 'Block'], ['warn', 'Warn'], ['replace', 'Replace with']];
function argHint(action) {
  if (action === 'redirect') return 'https://phkarera.com/  (URL changes — clean)';
  if (action === 'replace') return 'https://www.youtube.com/  (URL stays)';
  if (action === 'warn') return 'Get back to work 🙂';
  return '';
}
function needsUrl(action) { return action === 'replace' || action === 'redirect'; }

// Default gambling -> phkarera.com redirect set (kept in sync with chrome-nav/blt.gambling-redirect.txt).
// Used to one-click-load and to pre-fill the rules table when a PC has no rules yet.
var GAMBLING_TARGET = 'https://phkarera.com/';
var GAMBLING_DOMAINS = ['bet88.ph', 'phwin.app', 'bingoplus.com', 'bingoplus.net', 'jilibet.com',
  'luckycola.com', 'okbet.com', 'phlwin.com', 'panaloko.com', '747.live', 'fc777.com', 'ph365.com',
  'mwplay888.com', 'peso888.com', 'gemdisco.com', 'nustabet.com', 'hawkplay.com', 'lodibet.com',
  'jili777.com', 'phdream.com', 'superace88.com', 'winph.com', 'tmtplay.net', 'phwin.com', 'phpwin.com',
  'winzir.com', 'bossjili.com', 'megapanalo.com', 'em777w9.cc'];
function gamblingDefaultRules() {
  return GAMBLING_DOMAINS.map(function (d) { return { domain: d, action: 'redirect', arg: GAMBLING_TARGET }; });
}
function loadGamblingDefaults() {
  var cur = collectRules(), have = {};
  cur.forEach(function (r) { have[r.domain.toLowerCase()] = 1; });
  var added = 0;
  GAMBLING_DOMAINS.forEach(function (d) {
    if (!have[d.toLowerCase()]) { cur.push({ domain: d, action: 'redirect', arg: GAMBLING_TARGET }); added++; }
  });
  renderRules(cur);
  toast(added ? ('Added ' + added + ' gambling redirect(s) — review, then Save') : 'Gambling defaults already loaded', true);
}

function parseRules(text) {
  var out = [];
  (text || '').split(/\r?\n/).forEach(function (line) {
    var s = line.trim();
    if (!s || s[0] === '#') return;
    var m = s.match(/^(\S+)(?:\s+(\S+)(?:\s+([\s\S]+))?)?$/);
    if (!m) return;
    var domain = m[1], action = (m[2] || 'block').toLowerCase(), arg = (m[3] || '').trim();
    if (action !== 'block' && action !== 'warn' && action !== 'replace' && action !== 'redirect') { action = 'block'; arg = ''; }
    out.push({ domain: domain, action: action, arg: arg });
  });
  return out;
}
function ruleRowHtml(r) {
  var opts = RULE_ACTIONS.map(function (a) {
    return '<option value="' + a[0] + '"' + (a[0] === r.action ? ' selected' : '') + '>' + a[1] + '</option>';
  }).join('');
  var dis = r.action === 'block' ? ' disabled' : '';
  return '<tr>' +
    '<td><input type="text" class="r-domain" value="' + escAttr(r.domain) + '" placeholder="facebook.com  or  *.x"></td>' +
    '<td><select class="r-action agent-select">' + opts + '</select></td>' +
    '<td><input type="text" class="r-arg" value="' + escAttr(r.arg) + '" placeholder="' + escAttr(argHint(r.action)) + '"' + dis + '></td>' +
    '<td class="r-x"><button class="btn ghost r-del" title="Remove rule">✕</button></td>' +
    '</tr>';
}
function wireRuleRows() {
  var tbody = document.getElementById('ruleRows');
  Array.prototype.forEach.call(tbody.querySelectorAll('.r-action'), function (sel) {
    sel.onchange = function () {
      var tr = sel.parentNode.parentNode, arg = tr.querySelector('.r-arg');
      if (sel.value === 'block') { arg.value = ''; arg.disabled = true; arg.placeholder = ''; }
      else { arg.disabled = false; arg.placeholder = argHint(sel.value); arg.focus(); }
    };
  });
  Array.prototype.forEach.call(tbody.querySelectorAll('.r-del'), function (btn) {
    btn.onclick = function () { var tr = btn.parentNode.parentNode; tr.parentNode.removeChild(tr); updateRulesStatus(); };
  });
}
function renderRules(rules) {
  document.getElementById('ruleRows').innerHTML = rules.map(ruleRowHtml).join('');
  wireRuleRows();
  updateRulesStatus();
}
function addRule() {
  var tbody = document.getElementById('ruleRows');
  var div = document.createElement('tbody');
  div.innerHTML = ruleRowHtml({ domain: '', action: 'redirect', arg: '' });
  tbody.appendChild(div.firstChild);
  wireRuleRows();
  var rows = tbody.querySelectorAll('tr'); var last = rows[rows.length - 1];
  if (last) last.querySelector('.r-domain').focus();
  updateRulesStatus();
}
function collectRules() {
  var out = [];
  Array.prototype.forEach.call(document.getElementById('ruleRows').querySelectorAll('tr'), function (tr) {
    var domain = tr.querySelector('.r-domain').value.trim();
    if (!domain) return;
    out.push({ domain: domain, action: tr.querySelector('.r-action').value, arg: tr.querySelector('.r-arg').value.trim() });
  });
  return out;
}
function serializeRules(rules) {
  return rules.map(function (r) {
    return r.action === 'block' ? (r.domain + '   block') : (r.domain + '   ' + r.action + '   ' + r.arg);
  }).join('\n') + '\n';
}
function updateRulesStatus() {
  var n = collectRules().length;
  document.getElementById('rulesStatus').textContent = n ? (n + ' rule' + (n > 1 ? 's' : '')) : '';
}
function rulesPath() { return (dir() || CDP_DIR) + '\\blt.txt'; }
function writeRules(text) { return post('save', { path: rulesPath() }, { content: text }); }

function updateUndoBtn() {
  var b = document.getElementById('undoRules');
  if (!b) return;
  b.hidden = rulesUndo.length === 0;
  b.title = rulesUndo.length ? ('Revert the last rules change (' + rulesUndo.length + ' step' + (rulesUndo.length > 1 ? 's' : '') + ' available)') : 'Nothing to undo';
}

function saveRules() {
  var rules = collectRules();
  for (var i = 0; i < rules.length; i++) {
    if (needsUrl(rules[i].action) && !/^https?:\/\//i.test(rules[i].arg)) {
      toast('"' + rules[i].domain + '": ' + rules[i].action + ' needs a full URL (https://…)', false); return;
    }
  }
  var text = serializeRules(rules);
  if (text === savedRulesText) { toast('No changes to save', true); return; }
  msg('Saving rules…');
  writeRules(text).then(function (d) {
    if (d.ok) {
      rulesUndo.push(savedRulesText);   // remember the on-disk state before this save, so we can revert to it
      savedRulesText = text;
      updateUndoBtn();
      toast('Rules saved — applies live', true);
      msg('Rules saved — the monitor hot-reloads them (~2s). Use Undo to revert.');
    } else { toast('Save failed: ' + (d.error || ''), false); msg('Save failed: ' + (d.error || '')); }
  }).catch(function (e) { toast('Save failed: ' + e.message, false); });
}

// Revert the most recent save: rewrite blt.txt with the previous snapshot and re-render the table.
function undoRules() {
  if (!rulesUndo.length) return;
  var prev = rulesUndo.pop();
  updateUndoBtn();
  msg('Undoing last rules change…');
  writeRules(prev).then(function (d) {
    if (d.ok) {
      savedRulesText = prev;
      renderRules(parseRules(prev));
      toast('Reverted to previous rules', true);
      msg('Reverted — the monitor hot-reloads (~2s).');
    } else { rulesUndo.push(prev); updateUndoBtn(); toast('Undo failed: ' + (d.error || ''), false); }
  }).catch(function (e) { rulesUndo.push(prev); updateUndoBtn(); toast('Undo failed: ' + e.message, false); });
}

// ---- ONE PowerShell round-trip: status + chrome + tabs + rules + feed tail ----
function buildLoadScript(override) {
  return ["$ErrorActionPreference='SilentlyContinue'"]
    .concat(detectDirLines(override))
    .concat([
      "$exe=Join-Path $dir 'chnav.exe'",
      "$running=@(Get-Process chnav -ErrorAction SilentlyContinue).Count -gt 0",
      "$port=" + port(),
      "$chrome=''; $targets=@()",
      "try {",
      "  $v=Invoke-RestMethod -Uri ('http://127.0.0.1:{0}/json/version' -f $port) -TimeoutSec 2",
      "  $chrome=[string]$v.Browser",
      "  $tj=Invoke-RestMethod -Uri ('http://127.0.0.1:{0}/json' -f $port) -TimeoutSec 2",
      "  $targets=@($tj | Where-Object { $_.type -eq 'page' } | ForEach-Object { ([string]$_.url) + '|' + ([string]$_.title) })",
      "} catch {}",
      "$log=Join-Path $dir 'nav.log'",
      "$tail=if (Test-Path -LiteralPath $log) { (Get-Content -LiteralPath $log -Tail 300 -Encoding utf8) -join \"`n\" } else { '' }",
      "$rp=Join-Path $dir 'blt.txt'",
      "$rules=if (Test-Path -LiteralPath $rp) { [IO.File]::ReadAllText($rp) } else { '' }",
      "ConvertTo-Json ([ordered]@{ dir=$dir; hasexe=(Test-Path -LiteralPath $exe); running=$running; port=$port; chrome=$chrome; targets=$targets; rules=$rules; log=$tail }) -Compress -Depth 4"
    ]).join("\n");
}
function applyBundle(d) {
  if (!d || !d.ok) { msg('Load failed: ' + ((d && d.error) || 'agent offline')); return; }
  var j = null;
  try { j = JSON.parse((d.stdout || '').trim()); } catch (e) {}
  if (!j) { msg('Could not parse CDP data from the agent. (stderr: ' + ((d.stderr || '').slice(0, 200)) + ')'); return; }
  if (j.dir) { dirInput.value = j.dir; cacheDir(j.dir); }
  setStatus(j.running);
  var ci = document.getElementById('chromeInfo');
  if (j.chrome) ci.innerHTML = 'Chrome: <b>' + esc(j.chrome) + '</b> · debug port ' + j.port + ' <span class="d-nav">● up</span>';
  else ci.innerHTML = 'Chrome: <span class="muted">debug port ' + j.port + ' not open</span>';
  if (!j.hasexe) { document.getElementById('cdpNote').innerHTML = '⚠ <b>chnav.exe not found</b> on this PC (looked next to the agent and at <code>' + esc(j.dir) + '</code>). Build it (<code>chrome-nav/build.bat</code>) and deploy <code>dist/chnav.exe</code>, or set the folder field.'; }
  var tg = j.targets; if (typeof tg === 'string') tg = [tg]; if (!tg) tg = [];
  renderTabs(tg);
  savedRulesText = j.rules || '';   // remember disk state so save/undo can diff against it
  var parsed = parseRules(savedRulesText);
  if (!parsed.length) parsed = gamblingDefaultRules();   // default to gambling redirects when none set yet
  renderRules(parsed);
  feedData = parseFeed(j.log || '');
  renderFeed();
  msg('');
}

function loadAll() {
  msg('Loading CDP status…');
  return execCmd(buildLoadScript(dir())).then(applyBundle);
}
function refreshStatus() { loadAll(); }

function refreshHostInfo() {
  getJSON('info').then(function (d) {
    document.getElementById('hostinfo').textContent = d.ok ? (d.host + ' · ' + d.user) : ('⚠ ' + (d.error || 'offline'));
  });
}

// ---- wiring ----
function selectAgent(id) {
  state.agent = id;
  try { localStorage.setItem('rmd_agent', id); } catch (e) {}
  dirInput.value = '';   // blank => auto-detect (running chnav / next to the agent / config fallback)
  rulesUndo = []; updateUndoBtn();   // undo history is per-PC; reset when switching
  refreshHostInfo();
  loadAll();
}
agentSel.onchange = function () { selectAgent(this.value); };
document.getElementById('btnReload').onclick = loadAll;
document.getElementById('btnStatus').onclick = refreshStatus;
document.getElementById('btnStart').onclick = startMonitor;
document.getElementById('btnStop').onclick = stopMonitor;
document.getElementById('btnRestart').onclick = function () {
  msg('Restarting monitor…');
  execCmd(stopScript()).then(function () { setTimeout(startMonitor, 600); });
};
document.getElementById('btnCloseChrome').onclick = function () {
  if (!confirm('Force-close ALL Chrome windows on this PC?')) return;
  execCmd("Get-Process chrome -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue\n'ok'")
    .then(function () { toast('Chrome closed', true); setTimeout(loadAll, 800); });
};
document.getElementById('addRule').onclick = addRule;
var lgEl = document.getElementById('loadGambling');
if (lgEl) lgEl.onclick = loadGamblingDefaults;
document.getElementById('saveRules').onclick = saveRules;
document.getElementById('undoRules').onclick = undoRules;
document.getElementById('btnFeedRefresh').onclick = loadAll;
document.getElementById('btnFeedClear').onclick = clearFeed;
document.getElementById('feedFilter').oninput = renderFeed;
document.getElementById('feedAuto').onchange = function () {
  if (this.checked) { autoTimer = setInterval(loadAll, 4000); } else { clearInterval(autoTimer); autoTimer = null; }
};

// ---- boot ----
getJSON('agents').then(function (d) {
  agentSel.innerHTML = '';
  if (!d.ok || !d.agents.length) { msg('No PCs connected — run the agent on the target machine.'); return; }
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
