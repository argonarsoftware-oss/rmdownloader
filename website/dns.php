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
  <a class="btn ghost" href="cdp.php">🧭 CDP</a>
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
    <div class="dns-head">🛰 Upstream resolver
      <span class="muted">where non-local lookups are forwarded · comma-separated · applying restarts DNS (~1s)</span>
      <span class="spacer"></span><button class="btn" id="saveUpstream">Apply &amp; restart</button></div>
    <div class="upstream-row">
      <input type="text" id="upstreamText" placeholder="185.228.168.10,185.228.168.11  (CleanBrowsing — default)">
      <select id="upstreamPreset" class="agent-select" title="Preset resolvers">
        <option value="">— preset —</option>
        <option value="185.228.168.10,185.228.168.11">CleanBrowsing — family filter (current default)</option>
        <option value="185.228.168.168,185.228.169.168">CleanBrowsing — security only</option>
        <option value="1.1.1.3,1.0.0.3">Cloudflare — malware + adult block</option>
        <option value="1.1.1.2,1.0.0.2">Cloudflare — malware block</option>
        <option value="1.1.1.1,1.0.0.1">Cloudflare — unfiltered</option>
        <option value="94.140.14.14,94.140.15.15">AdGuard — ad/tracker block</option>
        <option value="9.9.9.9,149.112.112.112">Quad9 — security</option>
        <option value="208.67.222.123,208.67.220.123">OpenDNS FamilyShield</option>
        <option value="8.8.8.8,8.8.4.4">Google — unfiltered</option>
      </select>
    </div>
    <div class="muted" style="font-size:12px;margin-top:6px">Leave blank to reset to the built-in CleanBrowsing default. The DNS server restarts to apply (records/blocklist edits don't need this — they hot-reload).</div>
  </section>

  <section class="dns-card">
    <div class="dns-head">🔎 Test lookup
      <input type="text" id="lookupDomain" placeholder="example.local" style="margin-left:8px;width:220px">
      <button class="btn" id="btnLookup">Lookup</button></div>
    <pre id="lookupOut" class="term-out" style="min-height:70px;max-height:220px"></pre>
  </section>

  <section class="dns-card">
    <div class="dns-head">📊 Top sites <span class="muted">most-visited domains on this network (kept forever)</span>
      <select id="statsRange" class="agent-select" style="margin-left:8px">
        <option value="1">Today</option>
        <option value="7" selected>Last 7 days</option>
        <option value="30">Last 30 days</option>
        <option value="0">All time</option>
      </select>
      <label class="muted" style="margin-left:8px"><input type="checkbox" id="statsGroup" checked> group subdomains</label>
      <input type="text" id="statsFilter" placeholder="filter domain…" style="margin-left:8px;width:200px">
      <span class="spacer"></span>
      <span id="statsTotal" class="muted" style="margin-right:8px"></span>
      <button class="btn" id="btnStatsRefresh">Refresh</button></div>
    <div id="statsAlert" class="alert" hidden></div>
    <div class="log-wrap">
      <table class="loglist statlist">
        <thead><tr><th class="l-rank">#</th><th>Domain</th><th class="l-hits">Visits</th></tr></thead>
        <tbody id="statRows"></tbody>
      </table>
    </div>
  </section>

  <section class="dns-card">
    <div class="dns-head">📜 Query log <span class="muted">recent raw lookups (newest first)</span>
      <a class="btn ghost" href="dns-queries.php" title="Open the full-page query log with paging">⤢ Full log</a>
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
