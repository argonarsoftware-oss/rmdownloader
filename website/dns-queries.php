<?php
require_once __DIR__ . '/lib.php';
require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Query Log — DNS</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="brand">📜 Query Log</div>
  <select id="agentSel" class="agent-select" title="DNS machine"></select>
  <div id="hostinfo" class="muted"></div>
  <div class="spacer"></div>
  <a class="btn ghost" href="dns.php">🌐 DNS</a>
  <a class="btn ghost" href="index.php">🗂 Files</a>
  <a class="btn ghost" href="logout.php">Sign out</a>
</header>

<div class="toolbar">
  <input type="text" id="qFilter" placeholder="filter domain / client / result…" style="min-width:300px">
  <label class="muted">Show
    <select id="qLimit" class="agent-select">
      <option value="200">200</option>
      <option value="500" selected>500</option>
      <option value="1000">1000</option>
    </select> rows/page</label>
  <span class="sep"></span>
  <label class="muted"><input type="checkbox" id="qAuto"> auto-refresh</label>
  <button class="btn" id="qRefresh">⟳ Refresh</button>
  <button class="btn danger" id="qClear">Clear history</button>
  <span class="spacer"></span>
  <span id="qCount" class="muted"></span>
</div>

<main>
  <div id="qAlert" class="alert" hidden style="margin:14px 16px 0"></div>
  <div class="log-wrap tall">
    <table class="loglist">
      <thead><tr><th class="l-time">Time</th><th class="l-client">Client</th><th>Domain</th><th class="l-type">Type</th><th class="l-disp">Result</th></tr></thead>
      <tbody id="qRows"></tbody>
    </table>
  </div>
  <div style="padding:12px;text-align:center"><button class="btn" id="qMore" hidden>Load older ↓</button></div>
  <div id="qMsg" class="status muted"></div>
</main>

<script src="assets/dns-queries.js"></script>
</body>
</html>
