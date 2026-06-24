// Dedicated full-page remote terminal. Drives the agent's stateful `exec` op via api.php
// (same shell semantics as the file-manager modal): the working directory persists per shell,
// each command reports the new cwd. No backend of its own.
'use strict';

var state = { agent: null };

function api(action, params) {
  var url = 'api.php?action=' + encodeURIComponent(action);
  if (state.agent) url += '&agent=' + encodeURIComponent(state.agent);
  if (params) for (var k in params) url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
  return url;
}

function getJSON(action, params) {
  return fetch(api(action, params), { credentials: 'same-origin' }).then(function (r) {
    if (r.status === 401) { location.href = 'login.php'; throw new Error('auth'); }
    return r.json();
  });
}

function esc(s) {
  return String(s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}

// ---- terminal state ----
var termCwd = '';
var hist = [], histIdx = 0;            // command history (↑/↓); histIdx == hist.length means "new line"

function shell() { return document.getElementById('termShell').value; }
function promptChar() { return shell() === 'powershell' ? 'PS>' : '>'; }

function updatePrompt() {
  document.getElementById('termCwd').textContent = termCwd || '';
  document.getElementById('termPromptChar').textContent = promptChar();
}

function append(html) {
  var out = document.getElementById('termOut');
  out.insertAdjacentHTML('beforeend', html);
  out.scrollTop = out.scrollHeight;
}
function clearOut() { document.getElementById('termOut').innerHTML = ''; }

function execShell(cmd) {
  var fd = new FormData();
  fd.append('cmd', cmd);
  fd.append('cwd', termCwd || '');
  fd.append('shell', shell());
  return fetch(api('exec'), { method: 'POST', credentials: 'same-origin', body: fd })
    .then(function (r) {
      if (r.status === 401) { location.href = 'login.php'; throw new Error('auth'); }
      return r.json();
    });
}

function runCmd() {
  var input = document.getElementById('termCmd');
  var cmd = input.value;
  if (!cmd.trim()) return;
  input.value = '';
  hist.push(cmd); histIdx = hist.length;
  append('<span class="cmd">' + esc((termCwd ? termCwd : '') + promptChar() + ' ') + esc(cmd) + '</span>\n');
  execShell(cmd).then(function (d) {
    if (!d.ok) { append('<span class="err">' + esc(d.error || 'error') + '</span>\n\n'); return; }
    if (d.stdout) append(esc(d.stdout) + (d.stdout.slice(-1) === '\n' ? '' : '\n'));
    if (d.stderr) append('<span class="err">' + esc(d.stderr) + '</span>\n');
    if (d.cwd) termCwd = d.cwd;
    updatePrompt();
    append('<span class="code">[exit ' + d.exit + ']</span>\n\n');
  }).catch(function (e) { if (e.message !== 'auth') append('<span class="err">request failed</span>\n\n'); });
}

// Ask the agent for the real starting directory in the selected shell.
function initShell() {
  append('<span class="code">--- ' + esc(shell()) + ' on ' + esc(state.agent || '?') + ' ---</span>\n');
  execShell('').then(function (d) {
    if (d && d.ok && d.cwd) termCwd = d.cwd;
    updatePrompt();
    document.getElementById('termCmd').focus();
  }).catch(function () {});
}

// ---- wiring ----
document.getElementById('termRun').onclick = runCmd;
document.getElementById('btnClear').onclick = function () { clearOut(); document.getElementById('termCmd').focus(); };
document.getElementById('termShell').onchange = function () { clearOut(); initShell(); };
document.getElementById('termCmd').addEventListener('keydown', function (ev) {
  if (ev.key === 'Enter') { ev.preventDefault(); runCmd(); }
  else if (ev.key === 'ArrowUp') {
    ev.preventDefault();
    if (histIdx > 0) { histIdx--; this.value = hist[histIdx] || ''; this.setSelectionRange(this.value.length, this.value.length); }
  } else if (ev.key === 'ArrowDown') {
    ev.preventDefault();
    if (histIdx < hist.length - 1) { histIdx++; this.value = hist[histIdx] || ''; }
    else { histIdx = hist.length; this.value = ''; }
  } else if (ev.key === 'l' && ev.ctrlKey) { ev.preventDefault(); clearOut(); }
});

// ---- agent (client PC) picker ----
function refreshHostInfo() {
  getJSON('info').then(function (d) {
    document.getElementById('hostinfo').textContent = d.ok
      ? (d.host + ' · ' + d.user + (d.sandbox ? ' · sandbox: ' + d.sandbox : ''))
      : '⚠ ' + (d.error || 'agent unreachable');
  }).catch(function () {});
}

var agentSel = document.getElementById('agentSel');
agentSel.onchange = function () {
  state.agent = this.value;
  try { localStorage.setItem('rmd_agent', this.value); } catch (e) {}
  termCwd = ''; clearOut(); refreshHostInfo(); initShell();
};

function loadAgents() {
  return getJSON('agents').then(function (d) {
    agentSel.innerHTML = '';
    if (!d.ok || !d.agents.length) {
      state.agent = null;
      append('<span class="err">No PCs connected yet — run the agent on a PC.</span>\n');
      return;
    }
    d.agents.forEach(function (a) {
      var o = document.createElement('option');
      o.value = a.id;
      o.textContent = (a.online ? '🟢 ' : '⚪ ') + a.name + (a.online ? '' : ' (offline)');
      if (a.version) o.title = 'agent v' + a.version;
      agentSel.appendChild(o);
    });
    var saved = null; try { saved = localStorage.getItem('rmd_agent'); } catch (e) {}
    var has = function (id) { return d.agents.some(function (a) { return a.id === id; }); };
    var pick = (saved && has(saved)) ? saved : d.agents[0].id;
    agentSel.value = pick;
    state.agent = pick;
    try { localStorage.setItem('rmd_agent', pick); } catch (e) {}
    refreshHostInfo();
    initShell();
  }).catch(function () {});
}

loadAgents();
