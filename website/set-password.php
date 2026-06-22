<?php
/**
 * TEMPORARY one-shot password setter — DELETE after use.
 *
 * Shipped to the VPS via the [deploy] webhook, run once, then it self-deletes and
 * you remove it from the repo with a second [deploy] commit.
 *
 *   Usage:  https://dos.argonar.co/set-password.php?key=<TOKEN>&pw=<urlencoded-new-password>
 *
 * Safety: requires the secret `key` below (so a public URL can't be abused); the new
 * password is passed at request time, so it never enters git history; writes config.php
 * atomically; and unlinks itself on success.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');

define('SETTER_TOKEN', 'b7198f5fbbf2352a482a3aacb7c3d3cb');

$key = isset($_GET['key']) ? $_GET['key'] : '';
if (!hash_equals(SETTER_TOKEN, $key)) {
    http_response_code(403);
    exit("forbidden\n");
}

$pw = isset($_GET['pw']) ? $_GET['pw'] : '';
if ($pw === '') { http_response_code(400); exit("missing pw (pass ?pw=<new password>)\n"); }
if (strlen($pw) > 256) { http_response_code(400); exit("pw too long\n"); }

$cfg = __DIR__ . '/config.php';
$src = @file_get_contents($cfg);
if ($src === false) { http_response_code(500); exit("cannot read config.php\n"); }

// Replace the existing WEB_PASSWORD define (single line). addcslashes keeps the PHP string valid.
$escaped = "define('WEB_PASSWORD', '" . addcslashes($pw, "\\'") . "');";
$count = 0;
$new = preg_replace("/define\\(\\s*'WEB_PASSWORD'\\s*,.*?\\);/", $escaped, $src, 1, $count);
if ($count === 0) { http_response_code(500); exit("WEB_PASSWORD line not found in config.php\n"); }

// Atomic write (temp + rename), so a failed write can't truncate config.php.
$tmp = $cfg . '.tmp' . getmypid();
if (@file_put_contents($tmp, $new) === false || !@rename($tmp, $cfg)) {
    @unlink($tmp);
    http_response_code(500);
    exit("write failed — check that config.php is writable by www-data\n");
}

// Shrink the exposure window: remove this file from disk immediately.
// (Also delete it from the repo with a second [deploy] commit, or the next
//  `git reset --hard` would restore it from origin.)
@unlink(__FILE__);

echo "OK: WEB_PASSWORD updated. This setter has self-deleted.\n";
echo "Now remove it from the repo too: delete website/set-password.php and push a [deploy] commit.\n";
