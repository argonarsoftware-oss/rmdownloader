<?php
// Remote developer console for Icafe9. Login-gated (site session). Lets the
// developer pick a connected cafe and drive its LIVE engine through the relay.
// Reuses the exact admin renderer (icafe9-console/) with the relay shim.
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/icafe9-relay-lib.php';
require_login();

$nodes = ic9_nodes();
$current = isset($_GET['node']) ? ic9_safe_id($_GET['node']) : '';
if ($current === '' && count($nodes)) {
    // default to the first online cafe, else the first known one
    foreach ($nodes as $n) { if ($n['online']) { $current = $n['id']; break; } }
    if ($current === '') $current = $nodes[0]['id'];
}
$currentName = '';
foreach ($nodes as $n) { if ($n['id'] === $current) { $currentName = $n['name']; break; } }

// Build the console HTML from the shared renderer, transformed for the relay.
$html = @file_get_contents(__DIR__ . '/icafe9-console/index.html');
if ($html === false) { http_response_code(500); echo 'Console assets missing'; exit; }

// Widen CSP for the relay fetches, point assets at icafe9-console/, and load the
// relay shim before app.js.
$html = preg_replace(
    '/<meta http-equiv="Content-Security-Policy"[^>]*>/',
    '<meta http-equiv="Content-Security-Policy" content="default-src \'self\'; style-src \'self\' \'unsafe-inline\'; connect-src \'self\'" />',
    $html
);
// Cache-bust assets by their mtime so a browser can never keep serving a stale
// console (the empty-fallback bug was a cached relay-api.js / IC9_NODE).
$ver = function ($f) { $p = __DIR__ . '/icafe9-console/' . $f; return is_file($p) ? filemtime($p) : '1'; };
$html = str_replace('href="style.css"', 'href="icafe9-console/style.css?v=' . $ver('style.css') . '"', $html);

// Inject cafe context + the picker + the relay shim just before app.js.
$nodesJson = json_encode($nodes);
$inject = '<script>'
    . 'window.IC9_NODE=' . json_encode($current) . ';'
    . 'window.IC9_NODE_NAME=' . json_encode($currentName) . ';'
    . 'window.IC9_NODES=' . $nodesJson . ';'
    . '</script>'
    . '<script src="icafe9-console/relay-api.js?v=' . $ver('relay-api.js') . '"></script>'
    . "\n  <script src=\"icafe9-console/app.js?v=" . $ver('app.js') . "\"></script>";
$html = str_replace('<script src="app.js"></script>', $inject, $html);

// Floating cafe picker overlay (fixed, so it never disturbs the console layout).
$picker = '<div id="ic9-remote-bar" style="position:fixed;top:8px;left:50%;transform:translateX(-50%);z-index:9999;'
    . 'background:#0a246a;color:#fff;border:1px solid #fff;border-radius:20px;padding:4px 10px;font:12px Tahoma,sans-serif;'
    . 'box-shadow:0 3px 12px rgba(0,0,0,.4);display:flex;align-items:center;gap:8px;">'
    . '<span style="font-weight:700;letter-spacing:.4px;">🔴 REMOTE OVERRIDE</span>'
    . '<select id="ic9-node-sel" style="background:#fff;color:#1a2230;border:none;border-radius:10px;padding:2px 6px;font:12px Tahoma;">';
if (!count($nodes)) {
    $picker .= '<option value="">— no cafes connected —</option>';
} else {
    foreach ($nodes as $n) {
        $sel = ($n['id'] === $current) ? ' selected' : '';
        $dot = $n['online'] ? '🟢' : '⚪';
        $picker .= '<option value="' . htmlspecialchars($n['id']) . '"' . $sel . '>'
            . $dot . ' ' . htmlspecialchars($n['name']) . '</option>';
    }
}
$picker .= '</select>'
    . '<a href="index.php" style="color:#cdd;text-decoration:none;">✕</a>'
    . '</div>'
    . '<script>document.getElementById("ic9-node-sel").addEventListener("change",function(){'
    . 'location.href="icafe9.php?node="+encodeURIComponent(this.value);});</script>';

$html = str_replace('</body>', $picker . "\n</body>", $html);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, must-revalidate');
echo $html;
