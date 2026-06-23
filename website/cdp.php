<?php
require_once __DIR__ . '/lib.php';
require_login();
$cdpDir  = defined('CDP_DIR')  ? CDP_DIR  : 'C:\\Users\\Administrator\\Desktop\\chrome-nav';
$cdpPort = defined('CDP_PORT') ? CDP_PORT : 9222;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CDP — Chrome Navigation</title>
<link rel="stylesheet" href="assets/style.css">
<script>
  var CDP_DIR  = <?php echo json_encode($cdpDir); ?>;
  var CDP_PORT = <?php echo json_encode((int)$cdpPort); ?>;
</script>
</head>
<body>
<header class="topbar">
  <div class="brand">🧭 CDP — Chrome Navigation</div>
  <select id="agentSel" class="agent-select" title="Client PC"></select>
  <div id="hostinfo" class="muted"></div>
  <div class="spacer"></div>
  <a class="btn ghost" href="index.php">🗂 Files</a>
  <a class="btn ghost" href="dns.php">🌐 DNS</a>
  <a class="btn ghost" href="logout.php">Sign out</a>
</header>

<div class="toolbar">
  <label class="muted">chrome-nav folder:</label>
  <input type="text" id="cdpDir" style="min-width:320px">
  <label class="muted">port</label>
  <input type="text" id="cdpPort" style="width:64px">
  <label class="muted" title="Also log every request URL (Network.requestWillBeSent)"><input type="checkbox" id="optRequests"> requests</label>
  <label class="muted" title="Always-on: re-seize Chrome if closed, and kill any non-regulated Chrome so the rules can't be escaped"><input type="checkbox" id="optEnforce"> 🔒 enforce</label>
  <button class="btn" id="btnReload">Load</button>
  <span class="sep"></span>
  <span id="cdpStatus" class="pill">monitor: …</span>
  <button class="btn" id="btnStart">▶ Start</button>
  <button class="btn" id="btnStop">■ Stop</button>
  <button class="btn" id="btnRestart">⟳ Restart</button>
  <button class="btn ghost" id="btnStatus">Refresh</button>
</div>

<div class="ipbar">
  <span id="chromeInfo" class="muted">Chrome: detecting…</span>
  <span class="spacer"></span>
  <button class="btn ghost danger" id="btnCloseChrome" title="Force-close every Chrome process on the PC">✕ Close Chrome</button>
</div>

<main class="dns-main">
  <div class="alert info" id="cdpNote">
    🧭 <b>Navigation monitor + content regulation.</b> Start launches <code>chnav.exe</code> on the selected PC — it
    <b>relaunches Chrome with a dedicated debug profile</b> (closing existing Chrome windows), streams every page load here
    (<code>NAV</code>/<code>SPA</code>/<code>DOC</code>/<code>req</code>), and enforces the <b>site rules below</b> across
    all tabs. On machines you administer.
  </div>

  <section class="dns-card">
    <div class="dns-head">🛡 Site rules
      <span class="muted">per domain · <code>*.x</code> wildcard · hot-reloads live</span>
      <span class="spacer"></span><span id="rulesStatus" class="muted" style="margin-right:8px"></span>
      <button class="btn" id="addRule">＋ Add rule</button>
      <button class="btn ghost" id="undoRules" title="Nothing to undo" hidden>↶ Undo</button>
      <button class="btn" id="saveRules">Save</button></div>
    <table class="rules-table">
      <thead><tr><th class="r-dom">Domain</th><th class="r-actcol">Action</th><th>Target&nbsp;/&nbsp;message</th><th class="r-x"></th></tr></thead>
      <tbody id="ruleRows"></tbody>
    </table>
    <div class="muted" style="font-size:12px;margin-top:8px">
      <b>Block</b> → warning page &nbsp;·&nbsp; <b>Warn</b> → your custom message &nbsp;·&nbsp;
      <b>Replace with</b> → serve another site under the typed address, <i>URL stays</i> (e.g. <code>facebook.com → youtube.com</code>); assets render via an injected <code>&lt;base&gt;</code> &nbsp;·&nbsp;
      <b>Redirect to</b> → send to another site, <i>URL changes</i> (e.g. gambling → <code>https://phkarera.com/</code>).
      Saved as <code>blt.txt</code>; hot-reloaded live. <i>Replace keeps the address bar (great for a spoof); Redirect is a clean real navigation. Replace can still hit CORS for a target's own API calls — fine for content pages.</i>
    </div>
  </section>

  <section class="dns-card">
    <div class="dns-head">🗂 Open tabs <span class="muted">page targets currently attached on the debug port</span>
      <span class="spacer"></span><span id="targetCount" class="muted"></span></div>
    <div id="tabList" class="ip-list" style="padding:8px 2px"><span class="muted">—</span></div>
  </section>

  <section class="dns-card">
    <div class="dns-head">📡 Navigation feed <span class="muted">live, newest first · tail of nav.log</span>
      <input type="text" id="feedFilter" placeholder="filter url / type…" style="margin-left:8px;width:240px">
      <span class="spacer"></span>
      <label class="muted" style="margin-right:8px"><input type="checkbox" id="feedAuto"> auto-refresh</label>
      <button class="btn" id="btnFeedRefresh">Refresh</button>
      <button class="btn danger" id="btnFeedClear">Clear</button></div>
    <div class="log-wrap">
      <table class="loglist">
        <thead><tr><th class="l-time">Time</th><th class="l-type">Type</th><th>URL</th></tr></thead>
        <tbody id="feedRows"></tbody>
      </table>
    </div>
  </section>

</main>
<div id="cdpMsg" class="status muted"></div>

<script src="assets/cdp.js"></script>
</body>
</html>
