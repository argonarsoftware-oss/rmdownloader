<?php
// Tool download page + streamer. The built binaries (chnav.exe, dnl.exe) live OUTSIDE the web root
// (chrome-nav/ and dns/ are siblings of website/), so we stream them through PHP.
//   GET download.php                 -> the page (login required), lists available tools
//   GET download.php?get=chnav       -> stream chnav.exe (auth: session login OR API_KEY via ?key=)
//   GET download.php?get=dnl         -> stream dnl.exe
// Auth on the stream allows API_KEY so nodes can pull it with curl; the page itself needs a login.
require_once __DIR__ . '/lib.php';
app_session();

// Whitelisted tools -> FIXED paths (no user-controlled path => no traversal).
function ord_tools() {
    return array(
        'chnav' => array('path' => __DIR__ . '/../chrome-nav/dist/chnav.exe', 'name' => 'chnav.exe',
                         'label' => 'Chrome navigation monitor / CDP node'),
        'dnl'   => array('path' => __DIR__ . '/../dns/dist/dnl.exe', 'name' => 'dnl.exe',
                         'label' => 'TinyDNS server'),
        'icafe9-server' => array('path' => __DIR__ . '/../icafe9-dist/Icafe9-Server-Setup.exe', 'name' => 'Icafe9-Server-Setup.exe',
                         'label' => 'Icafe9 Server — front-desk console installer (one per cafe)'),
        'icafe9-client' => array('path' => __DIR__ . '/../icafe9-dist/Icafe9-Client-Setup.exe', 'name' => 'Icafe9-Client-Setup.exe',
                         'label' => 'Icafe9 Client — customer-PC lock installer (every PC)'),
    );
}

$get = isset($_GET['get']) ? (string)$_GET['get'] : '';
if ($get !== '') {
    if (!api_authorized()) { http_response_code(401); header('Content-Type: text/plain'); echo 'Not authorized'; exit; }
    $tools = ord_tools();
    if (!isset($tools[$get])) { http_response_code(404); header('Content-Type: text/plain'); echo 'Unknown file'; exit; }
    $p = $tools[$get]['path'];
    if (!is_file($p)) { http_response_code(404); header('Content-Type: text/plain'); echo 'Not built/deployed yet'; exit; }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $tools[$get]['name'] . '"');
    header('Content-Length: ' . filesize($p));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    readfile($p);
    exit;
}

require_login();
$tools = ord_tools();
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'dos.argonar.co';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Downloads — Ordinal</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="brand">⬇ Ordinal Downloads</div>
  <div class="spacer"></div>
  <a class="btn ghost" href="index.php">🗂 Files</a>
  <a class="btn ghost" href="dns.php">🌐 DNS</a>
  <a class="btn ghost" href="cdp-nodes.php">🧭 CDP Nodes</a>
  <a class="btn ghost" href="terminal.php">💻 Terminal</a>
  <a class="btn ghost" href="icafe9.php">🎮 Icafe9</a>
  <a class="btn ghost" href="logout.php">Sign out</a>
</header>
<main style="max-width:840px;margin:20px auto;padding:0 16px">
  <h2 style="margin:6px 0">Tool downloads</h2>
  <p class="muted" style="margin-top:0">Built binaries served straight from the VPS repo — deploy these to your client PCs.</p>
  <?php foreach ($tools as $key => $t): $exists = is_file($t['path']); ?>
    <section class="dns-card" style="margin-bottom:14px">
      <div class="dns-head"><b><?php echo htmlspecialchars($t['name']); ?></b>
        <span class="muted" style="margin-left:8px"><?php echo htmlspecialchars($t['label']); ?></span></div>
      <div class="dns-body" style="padding:12px 14px">
        <?php if ($exists): ?>
          <p class="muted" style="margin:0 0 10px">
            <?php echo number_format(filesize($t['path'])); ?> bytes ·
            built <?php echo date('Y-m-d H:i', filemtime($t['path'])); ?> ·
            sha256 <code style="font-size:11px;word-break:break-all"><?php echo hash_file('sha256', $t['path']); ?></code>
          </p>
          <a class="btn" href="download.php?get=<?php echo urlencode($key); ?>">⬇ Download <?php echo htmlspecialchars($t['name']); ?></a>
          <div class="muted" style="font-size:12px;margin-top:10px">Scripted pull (with your API key):<br>
            <code>curl -OJ "https://<?php echo htmlspecialchars($host); ?>/download.php?get=<?php echo urlencode($key); ?>&amp;key=YOUR_API_KEY"</code>
          </div>
        <?php else: ?>
          <p class="muted" style="margin:0">Not built/committed yet.</p>
        <?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>
</main>
</body>
</html>
