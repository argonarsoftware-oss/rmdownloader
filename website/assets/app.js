// Front-end for the remote file manager. Talks only to api.php (same origin).
'use strict';

var state = { agent: null, path: '', parent: null, entries: [] };

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

function postJSON(action, params, body) {
  return fetch(api(action, params), { method: 'POST', credentials: 'same-origin', body: body })
    .then(function (r) { return r.json(); });
}

function esc(s) {
  return String(s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}

function humanSize(n) {
  if (n < 0) return '';
  var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
  while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
  return (i === 0 ? n : n.toFixed(1)) + ' ' + u[i];
}

function setStatus(msg) { document.getElementById('status').textContent = msg || ''; }

function load(path) {
  setStatus('Loading…');
  getJSON('list', { path: path || '' }).then(function (d) {
    if (!d.ok) { setStatus(d.error || 'Error'); return; }
    state.path = d.path; state.parent = d.parent; state.entries = d.entries || [];
    render();
    setStatus(state.entries.length + ' item(s)');
  }).catch(function (e) { if (e.message !== 'auth') setStatus('Cannot reach agent.'); });
}

function renderBreadcrumb() {
  var bc = document.getElementById('breadcrumb');
  bc.innerHTML = '';
  var drives = document.createElement('a');
  drives.className = 'crumb'; drives.textContent = 'Drives';
  drives.onclick = function () { load(''); };
  bc.appendChild(drives);
  if (!state.path) return;
  // Split a Windows path like C:\a\b into clickable crumbs.
  var parts = state.path.replace(/\\+$/, '').split('\\');
  var acc = '';
  for (var i = 0; i < parts.length; i++) {
    if (parts[i] === '') continue;
    acc += parts[i] + '\\';
    (function (full, label) {
      var a = document.createElement('a');
      a.className = 'crumb'; a.textContent = label;
      a.onclick = function () { load(full); };
      bc.appendChild(a);
    })(acc, parts[i]);
  }
}

function render() {
  renderBreadcrumb();
  var rows = document.getElementById('rows');
  var filter = document.getElementById('filter').value.toLowerCase();
  rows.innerHTML = '';
  state.entries.forEach(function (e) {
    if (filter && e.name.toLowerCase().indexOf(filter) === -1) return;
    var isDir = e.type === 'dir' || e.type === 'drive';
    var tr = document.createElement('tr');

    var icon = e.type === 'drive' ? '💾' : (isDir ? '📁' : '📄');
    // Show the volume label next to a drive letter when the agent reports one
    // (older agents omit e.label -> we just show the letter).
    var label = (e.type === 'drive' && e.label) ? ' <span class="muted">(' + esc(e.label) + ')</span>' : '';
    var nameCell = '<div class="name-cell ' + (isDir ? 'clickable' : '') + '">' +
      '<span class="icon">' + icon + '</span><span>' + esc(e.name) + label + '</span></div>';

    var acts = '';
    if (!isDir) acts += '<button class="iconbtn" data-act="download" title="Download">⬇</button>';
    if (!isDir) acts += '<button class="iconbtn" data-act="view" title="View / edit">✎</button>';
    if (e.type !== 'drive') acts += '<button class="iconbtn" data-act="rename" title="Rename">✏</button>';
    if (e.type !== 'drive') acts += '<button class="iconbtn del" data-act="delete" title="Delete">🗑</button>';

    tr.innerHTML =
      '<td>' + nameCell + '</td>' +
      '<td class="c-size">' + (isDir ? '' : humanSize(e.size)) + '</td>' +
      '<td class="c-mod">' + esc(e.modified || '') + '</td>' +
      '<td class="c-act"><span class="row-act">' + acts + '</span></td>';

    var nc = tr.querySelector('.name-cell');
    if (isDir) nc.onclick = function () { load(e.path); };

    tr.querySelectorAll('[data-act]').forEach(function (b) {
      b.onclick = function (ev) { ev.stopPropagation(); doAction(b.getAttribute('data-act'), e); };
    });
    rows.appendChild(tr);
  });
}

function doAction(act, e) {
  if (act === 'download') {
    window.location = api('download', { path: e.path });
  } else if (act === 'view') {
    openEditor(e);
  } else if (act === 'rename') {
    var nn = prompt('Rename to:', e.name);
    if (!nn || nn === e.name) return;
    postJSON('rename', { path: e.path }, formData({ newName: nn })).then(function (d) {
      if (!d.ok) alert(d.error); load(state.path);
    });
  } else if (act === 'delete') {
    if (!confirm('Delete "' + e.name + '"' + (e.type === 'dir' ? ' and all its contents' : '') + '?')) return;
    postJSON('delete', { path: e.path }).then(function (d) {
      if (!d.ok) alert(d.error); load(state.path);
    });
  }
}

function formData(obj) {
  var f = new FormData();
  for (var k in obj) f.append(k, obj[k]);
  return f;
}

// ---- editor ----
var editorPath = null;
function openEditor(e) {
  setStatus('Opening ' + e.name + '…');
  getJSON('read', { path: e.path }).then(function (d) {
    if (!d.ok) { alert(d.error); setStatus(''); return; }
    editorPath = e.path;
    document.getElementById('editorTitle').textContent = e.name;
    document.getElementById('editorText').value = d.content;
    document.getElementById('editorMsg').textContent = '';
    document.getElementById('editor').hidden = false;
    setStatus('');
  });
}
function closeEditor() { document.getElementById('editor').hidden = true; editorPath = null; }

// ---- terminal ----
var termCwd = '';

function termShell() { return document.getElementById('termShell').value; }
function promptChar() { return termShell() === 'powershell' ? 'PS>' : '>'; }

function updateTermPrompt() {
  document.getElementById('termCwd').textContent = termCwd || '';
  document.getElementById('termPromptChar').textContent = promptChar();
}

function execShell(cmd) {
  var fd = new FormData();
  fd.append('cmd', cmd);
  fd.append('cwd', termCwd || state.path || '');
  fd.append('shell', termShell());
  return fetch(api('exec'), { method: 'POST', credentials: 'same-origin', body: fd })
    .then(function (r) { return r.json(); });
}

function openTerminal() {
  termCwd = state.path || '';
  document.getElementById('terminal').hidden = false;
  updateTermPrompt();
  // ask the agent for the real starting directory in the selected shell
  execShell('').then(function (d) { if (d.ok && d.cwd) { termCwd = d.cwd; updateTermPrompt(); } });
  document.getElementById('termCmd').focus();
}
function closeTerminal() { document.getElementById('terminal').hidden = true; }

function termAppend(html) {
  var out = document.getElementById('termOut');
  out.insertAdjacentHTML('beforeend', html);
  out.scrollTop = out.scrollHeight;
}

function runCmd() {
  var input = document.getElementById('termCmd');
  var cmd = input.value.trim();
  if (!cmd) return;
  input.value = '';
  termAppend('<span class="cmd">' + esc(termCwd + promptChar() + ' ') + esc(cmd) + '</span>\n');
  execShell(cmd).then(function (d) {
    if (!d.ok) { termAppend('<span class="err">' + esc(d.error || 'error') + '</span>\n\n'); return; }
    if (d.stdout) termAppend(esc(d.stdout) + (d.stdout.slice(-1) === '\n' ? '' : '\n'));
    if (d.stderr) termAppend('<span class="err">' + esc(d.stderr) + '</span>\n');
    if (d.cwd) termCwd = d.cwd;
    updateTermPrompt();
    termAppend('<span class="code">[exit ' + d.exit + ']</span>\n\n');
  });
}

// ---- toolbar wiring ----
document.getElementById('btnUp').onclick = function () { if (state.parent) load(state.parent); else load(''); };
document.getElementById('btnDrives').onclick = function () { load(''); };
document.getElementById('btnRefresh').onclick = function () { load(state.path); };
document.getElementById('filter').oninput = render;

document.getElementById('btnNewFolder').onclick = function () {
  if (!state.path) { alert('Open a drive first.'); return; }
  var name = prompt('New folder name:');
  if (!name) return;
  postJSON('mkdir', { path: state.path }, formData({ name: name })).then(function (d) {
    if (!d.ok) alert(d.error); load(state.path);
  });
};

document.getElementById('fileInput').onchange = function () {
  if (!state.path) { alert('Open a drive/folder first.'); this.value = ''; return; }
  var files = Array.prototype.slice.call(this.files);
  var input = this;
  var i = 0;
  function next() {
    if (i >= files.length) { input.value = ''; load(state.path); return; }
    var f = files[i++];
    setStatus('Uploading ' + f.name + ' (' + i + '/' + files.length + ')…');
    postJSON('upload', { path: state.path }, formData({ file: f })).then(function (d) {
      if (!d.ok) alert('Upload failed: ' + (d.error || f.name));
      next();
    });
  }
  next();
};

document.getElementById('btnSave').onclick = function () {
  if (!editorPath) return;
  var content = document.getElementById('editorText').value;
  document.getElementById('editorMsg').textContent = 'Saving…';
  postJSON('save', { path: editorPath }, formData({ content: content })).then(function (d) {
    document.getElementById('editorMsg').textContent = d.ok ? 'Saved.' : ('Error: ' + d.error);
    if (d.ok) load(state.path);
  });
};

document.querySelectorAll('#editor [data-close]').forEach(function (b) { b.onclick = closeEditor; });
document.getElementById('editor').addEventListener('click', function (ev) {
  if (ev.target === this) closeEditor();
});

// terminal wiring
document.getElementById('btnTerminal').onclick = openTerminal;
document.getElementById('termRun').onclick = runCmd;
document.getElementById('termShell').onchange = function () {
  termAppend('<span class="code">--- switched to ' + esc(termShell()) + ' ---</span>\n');
  execShell('').then(function (d) { if (d.ok && d.cwd) { termCwd = d.cwd; } updateTermPrompt(); });
  document.getElementById('termCmd').focus();
};
document.getElementById('termCmd').addEventListener('keydown', function (ev) {
  if (ev.key === 'Enter') { ev.preventDefault(); runCmd(); }
});
document.querySelectorAll('#terminal [data-close]').forEach(function (b) { b.onclick = closeTerminal; });
document.getElementById('terminal').addEventListener('click', function (ev) {
  if (ev.target === this) closeTerminal();
});

// ---- agent (client PC) picker ----
function refreshHostInfo() {
  getJSON('info').then(function (d) {
    document.getElementById('hostinfo').textContent = d.ok
      ? (d.host + ' · ' + d.user + (d.sandbox ? ' · sandbox: ' + d.sandbox : ''))
      : '⚠ ' + (d.error || 'agent unreachable');
  });
}

var agentSel = document.getElementById('agentSel');
agentSel.onchange = function () {
  state.agent = this.value;
  try { localStorage.setItem('rmd_agent', this.value); } catch (e) {}
  refreshHostInfo();
  load('');               // reset to drive list on the newly selected PC
};

// ---- boot ----
function loadAgents(preferId) {
  return getJSON('agents').then(function (d) {
    agentSel.innerHTML = '';
    if (!d.ok || !d.agents.length) { state.agent = null; setStatus('No PCs connected yet — run the agent on a PC.'); return; }
    d.agents.forEach(function (a) {
      var o = document.createElement('option');
      o.value = a.id;
      o.textContent = (a.online ? '🟢 ' : '⚪ ') + a.name + (a.online ? '' : ' (offline)');
      if (a.version) o.title = 'agent v' + a.version;   // older agents report no version
      agentSel.appendChild(o);
    });
    var has = function (id) { return d.agents.some(function (a) { return a.id === id; }); };
    var saved = null; try { saved = localStorage.getItem('rmd_agent'); } catch (e) {}
    var prefer = preferId || saved;
    var pick = (prefer && has(prefer)) ? prefer : d.agents[0].id;
    agentSel.value = pick;
    state.agent = pick;
    try { localStorage.setItem('rmd_agent', pick); } catch (e) {}
    refreshHostInfo();
    load('');
  });
}

document.getElementById('btnRemovePc').onclick = function () {
  if (!state.agent) return;
  var opt = agentSel.options[agentSel.selectedIndex];
  var label = opt ? opt.textContent : state.agent;
  if (!confirm('Remove ' + label + ' from the list?\n(If its agent is still running, it will re-appear on the next check.)')) return;
  var fd = new FormData();
  fd.append('id', state.agent);
  fetch(api('removeagent'), { method: 'POST', credentials: 'same-origin', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) { if (!d.ok) { alert(d.error || 'error'); return; } loadAgents(); });
};

loadAgents();
