<?php
require_once __DIR__ . '/lib.php';
require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CDP Nodes — Relma</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="brand">🧭 CDP Nodes</div>
  <select id="nodeSel" class="agent-select" title="chnav node"></select>
  <div id="nodeInfo" class="muted"></div>
  <div class="spacer"></div>
  <a class="btn ghost" href="index.php">🗂 Files</a>
  <a class="btn ghost" href="dns.php">🌐 DNS</a>
  <a class="btn ghost" href="terminal.php">💻 Terminal</a>
  <a class="btn ghost" href="logout.php">Sign out</a>
</header>

<div class="ipbar">
  <span id="nodeStatus" class="muted">select a node…</span>
  <span class="spacer"></span>
  <button class="btn ghost" id="btnRefresh">⟳ Refresh</button>
</div>

<main class="dns-main">
  <div class="alert info">
    🧭 <b>Independent chnav nodes.</b> These PCs run <code>chnav.exe</code> on their own — <b>no agent needed</b>.
    They push their navigation events here and pull their site rules from the server. Edit a node's rules
    below and chnav applies them within a few seconds (hot-reload).
  </div>

  <section class="dns-card">
    <div class="dns-head">🗂 Open tabs <span class="muted">last reported by this node</span>
      <span class="spacer"></span><span id="tabCount" class="muted"></span></div>
    <div id="tabList" class="ip-list" style="padding:8px 2px"><span class="muted">—</span></div>
  </section>

  <section class="dns-card">
    <div class="dns-head">🛡 Site rules <span class="muted">blt.txt · pulled by chnav (hot-reloads live)</span>
      <span class="spacer"></span><span id="rulesVer" class="muted" style="margin-right:8px"></span>
      <button class="btn" id="saveRules">Save</button></div>
    <textarea id="rulesText" class="dns-edit" spellcheck="false" placeholder="bet88.ph        redirect https://phkarera.com/&#10;*.casino.com    block&#10;ads.example.com warn Not allowed here"></textarea>
    <div class="muted" style="font-size:12px;padding:0 14px 12px">
      <code>domain action target</code> — actions: <b>redirect</b> &lt;url&gt; (URL changes), <b>block</b>, <b>warn</b> &lt;msg&gt;,
      <b>replace</b> &lt;url&gt; (keeps URL). Bare domain = block; <code>*.x</code> wildcard; a bare domain also covers subdomains.
    </div>
  </section>

  <section class="dns-card">
    <div class="dns-head">📡 Navigation feed <span class="muted">live, newest first · pushed by chnav</span>
      <input type="text" id="feedFilter" placeholder="filter url / type…" style="margin-left:8px;width:240px">
      <span class="spacer"></span>
      <label class="muted" style="margin-right:8px"><input type="checkbox" id="feedAuto"> auto-refresh</label>
      <button class="btn" id="btnFeedRefresh">Refresh</button>
      <button class="btn danger" id="btnFeedClear">Clear</button></div>
    <div id="feedAlert" class="alert" hidden style="margin:0 14px"></div>
    <div class="log-wrap">
      <table class="loglist">
        <thead><tr><th class="l-time">Time</th><th class="l-type">Type</th><th>URL</th></tr></thead>
        <tbody id="feedRows"></tbody>
      </table>
    </div>
    <div style="padding:10px 14px"><button class="btn ghost" id="feedMore" hidden>Load older ↓</button></div>
  </section>
</main>
<div id="msg" class="status muted"></div>

<script src="assets/cdp-nodes.js"></script>
</body>
</html>
