<?php
require_once __DIR__ . '/lib.php';
require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Relma File Manager</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="brand">🗂 Relma File Manager</div>
  <select id="agentSel" class="agent-select" title="Client PC"></select>
  <button class="btn ghost" id="btnRemovePc" title="Remove this PC from the list">✕</button>
  <div id="hostinfo" class="muted"></div>
  <div class="spacer"></div>
  <a class="btn ghost" href="dns.php">🌐 DNS</a>
  <a class="btn ghost" href="cdp.php">🧭 CDP</a>
  <a class="btn ghost" href="logout.php">Sign out</a>
</header>

<div class="toolbar">
  <button class="btn" id="btnUp">⬆ Up</button>
  <button class="btn" id="btnDrives">💾 Drives</button>
  <button class="btn" id="btnRefresh">⟳ Refresh</button>
  <span class="sep"></span>
  <button class="btn" id="btnNewFolder">＋ New folder</button>
  <label class="btn" for="fileInput">⬆ Upload</label>
  <input type="file" id="fileInput" multiple hidden>
  <button class="btn" id="btnTerminal">&gt;_ Terminal</button>
  <span class="spacer"></span>
  <input type="text" id="filter" placeholder="Filter…">
</div>

<nav class="breadcrumb" id="breadcrumb"></nav>

<main>
  <table class="filelist" id="filelist">
    <thead>
      <tr><th class="c-name">Name</th><th class="c-size">Size</th><th class="c-mod">Modified</th><th class="c-act">Actions</th></tr>
    </thead>
    <tbody id="rows"></tbody>
  </table>
  <div id="status" class="status muted"></div>
</main>

<!-- Terminal modal -->
<div class="modal" id="terminal" hidden>
  <div class="modal-card wide">
    <div class="modal-head">
      <span>Terminal
        <select id="termShell" class="agent-select" title="Shell">
          <option value="cmd">cmd</option>
          <option value="powershell">PowerShell</option>
        </select>
        <span id="termCwd" class="muted"></span>
      </span>
      <button class="x" data-close>×</button>
    </div>
    <pre id="termOut" class="term-out"></pre>
    <div class="term-input">
      <span class="term-prompt" id="termPromptChar">&gt;</span>
      <input type="text" id="termCmd" placeholder="type a command, Enter to run" spellcheck="false" autocomplete="off">
      <button class="btn" id="termRun">Run</button>
    </div>
  </div>
</div>

<!-- Text editor modal -->
<div class="modal" id="editor" hidden>
  <div class="modal-card wide">
    <div class="modal-head"><span id="editorTitle"></span><button class="x" data-close>×</button></div>
    <textarea id="editorText" spellcheck="false"></textarea>
    <div class="modal-foot">
      <button class="btn" id="btnSave">Save</button>
      <button class="btn ghost" data-close>Close</button>
      <span id="editorMsg" class="muted"></span>
    </div>
  </div>
</div>

<script src="assets/app.js"></script>
</body>
</html>
