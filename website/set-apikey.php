<?php
/**
 * TEMPORARY one-shot API_KEY setter — DELETE after use.
 * Sets/replaces define('API_KEY', …) in config.php. Guarded by ?key=<token>; the value is
 * passed via ?val= at request time (never committed). Self-deletes on success.
 *   https://dos.argonar.co/set-apikey.php?key=<TOKEN>&val=<api-key>
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');

define('SETTER_TOKEN', 'a21506cf5145a77db686d80e4023c8f5');

$key = isset($_GET['key']) ? $_GET['key'] : '';
if (!hash_equals(SETTER_TOKEN, (string)$key)) { http_response_code(403); exit("forbidden\n"); }

$val = isset($_GET['val']) ? $_GET['val'] : '';
if (!preg_match('/^[A-Za-z0-9._-]{8,128}$/', $val)) {
    http_response_code(400); exit("val must be 8-128 chars of [A-Za-z0-9._-]\n");
}

$cfg = __DIR__ . '/config.php';
$src = @file_get_contents($cfg);
if ($src === false) { http_response_code(500); exit("cannot read config.php\n"); }

$line = "define('API_KEY', '" . $val . "');";
$count = 0;
$new = preg_replace("/define\\(\\s*'API_KEY'\\s*,.*?\\);/", $line, $src, 1, $count);
if ($count === 0) { $new = rtrim($src, "\n") . "\n" . $line . "\n"; }   // append if not present

$tmp = $cfg . '.tmp' . getmypid();
if (@file_put_contents($tmp, $new) === false || !@rename($tmp, $cfg)) {
    @unlink($tmp); http_response_code(500); exit("write failed — config.php not writable by www-data\n");
}

@unlink(__FILE__);
echo "OK: API_KEY set (" . ($count ? 'replaced' : 'appended') . "); setter self-deleted.\n";
