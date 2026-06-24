<?php
require_once __DIR__ . '/lib.php';
require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Terminal — Relma</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="term-body">
<header class="topbar">
  <div class="brand">💻 Terminal</div>
  <select id="agentSel" class="agent-select" title="Client PC"></select>
  <div id="hostinfo" class="muted"></div>
  <div class="spacer"></div>
  <a class="btn ghost" href="index.php">🗂 Files</a>
  <a class="btn ghost" href="dns.php">🌐 DNS</a>
  <a class="btn ghost" href="cdp-nodes.php">🧭 CDP Nodes</a>
  <a class="btn ghost" href="logout.php">Sign out</a>
</header>

<div class="toolbar">
  <label class="muted">Shell</label>
  <select id="termShell" class="agent-select" title="Shell">
    <option value="cmd">cmd</option>
    <option value="powershell">PowerShell</option>
  </select>
  <span id="termCwd" class="muted"></span>
  <span class="spacer"></span>
  <span class="muted" style="font-size:12px">Enter to run · ↑/↓ history</span>
  <button class="btn ghost" id="btnClear">Clear</button>
</div>

<div class="term-shell">
  <pre id="termOut" class="term-out"></pre>
  <div class="term-input">
    <span class="term-prompt" id="termPromptChar">&gt;</span>
    <input type="text" id="termCmd" placeholder="type a command, Enter to run" spellcheck="false" autocomplete="off">
    <button class="btn" id="termRun">Run</button>
  </div>
</div>

<script src="assets/terminal.js"></script>
</body>
</html>
