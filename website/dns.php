<?php
require_once __DIR__ . '/lib.php';
require_login();
$dnsDir  = defined('DNS_DIR')  ? DNS_DIR  : 'C:\\Users\\Administrator\\Desktop\\dns\\dist';
$dnsTask = defined('DNS_TASK') ? DNS_TASK : 'TinyDNS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DNS Manager</title>
<link rel="stylesheet" href="assets/style.css">
<script>
  var DNS_DIR = <?php echo json_encode($dnsDir); ?>;
  var DNS_TASK = <?php echo json_encode($dnsTask); ?>;
</script>
</head>
<body>
<header class="topbar">
  <div class="brand">🌐 DNS Manager</div>
  <select id="agentSel" class="agent-select" title="DNS machine"></select>
  <div id="hostinfo" class="muted"></div>
  <div class="spacer"></div>
  <a class="btn ghost" href="index.php">🗂 Files</a>
  <a class="btn ghost" href="logout.php">Sign out</a>
</header>

<div class="toolbar">
  <label class="muted">DNS folder:</label>
  <input type="text" id="dnsDir" style="min-width:340px">
  <button class="btn" id="btnReload">Load</button>
  <span class="sep"></span>
  <span id="dnsStatus" class="pill">DNS: …</span>
  <button class="btn" id="btnStart">▶ Start</button>
  <button class="btn" id="btnStop">■ Stop</button>
  <button class="btn" id="btnRestart">⟳ Restart</button>
  <button class="btn ghost" id="btnStatus">Refresh</button>
</div>

<div class="ipbar">
  <span class="muted">Point other devices' DNS at this machine →</span>
  <span id="dnsIps" class="ip-list muted">detecting…</span>
</div>

<main class="dns-main">
  <section class="dns-card">
    <div class="dns-head">🚫 Blocked sites <span class="muted">→ 0.0.0.0 · one domain per line · <code>*.x</code> for subdomains</span>
      <span class="spacer"></span><button class="btn" id="saveBlock">Save</button></div>
    <textarea id="blockText" class="dns-edit" spellcheck="false" placeholder="ads.example.com&#10;*.doubleclick.net"></textarea>
  </section>

  <section class="dns-card">
    <div class="dns-head">🧭 Custom routing <span class="muted">domain &nbsp;→ &nbsp;IP <i>or</i> another domain (records.txt) · <code>*.x</code> wildcard · e.g. <code>youtube.com&nbsp;&nbsp;facebook.com</code></span>
      <span class="spacer"></span><button class="btn" id="saveRec">Save</button></div>
    <textarea id="recText" class="dns-edit" spellcheck="false" placeholder="example.local   192.168.1.10&#10;youtube.com     facebook.com&#10;*.dev.local     10.0.0.50"></textarea>
  </section>

  <section class="dns-card">
    <div class="dns-head">🔎 Test lookup
      <input type="text" id="lookupDomain" placeholder="example.local" style="margin-left:8px;width:220px">
      <button class="btn" id="btnLookup">Lookup</button></div>
    <pre id="lookupOut" class="term-out" style="min-height:70px;max-height:220px"></pre>
  </section>

  <section class="dns-card">
    <div class="dns-head">📜 Query log <span class="muted">what every PC looked up (newest first)</span>
      <input type="text" id="logFilter" placeholder="filter domain / IP…" style="margin-left:8px;width:220px">
      <span class="spacer"></span>
      <label class="muted" style="margin-right:8px"><input type="checkbox" id="logAuto"> auto-refresh</label>
      <button class="btn" id="btnLogRefresh">Refresh</button>
      <button class="btn danger" id="btnLogClear">Clear</button></div>
    <div class="log-wrap">
      <table class="loglist">
        <thead><tr><th class="l-time">Time</th><th class="l-client">Client</th><th>Domain</th><th class="l-type">Type</th><th class="l-disp">Result</th></tr></thead>
        <tbody id="logRows"></tbody>
      </table>
    </div>
  </section>
</main>
<div id="dnsMsg" class="status muted"></div>

<script src="assets/dns.js"></script>
</body>
</html>
