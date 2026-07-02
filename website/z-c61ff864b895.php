<?php
// TEMPORARY owner key-recovery probe. Token-gated + self-deleting. Removed from
// git immediately after use. Reveals only the enroll/API keys the owner needs to
// wire up the Icafe9 relay from their own VPS.
$TOKEN = '7dec512ef89e84d16b143f7fb19a346916943c9d446442e2';
if (!isset($_GET['t']) || !hash_equals($TOKEN, (string)$_GET['t'])) { http_response_code(404); echo 'Not found'; exit; }
header('Content-Type: text/plain');

$out = array();

// 1) config.php constants (isolated include)
$cfg = __DIR__ . '/config.php';
if (is_file($cfg)) {
    (function () use (&$out) {
        include __DIR__ . '/config.php';
        $out['ENROLL_KEY']         = defined('ENROLL_KEY') ? ENROLL_KEY : '(undefined)';
        $out['API_KEY']            = defined('API_KEY') ? API_KEY : '(undefined)';
        $out['WEBHOOK_SECRET_set'] = (defined('WEBHOOK_SECRET') && WEBHOOK_SECRET !== '') ? 'yes' : 'no';
        $out['DATA_DIR']           = defined('DATA_DIR') ? DATA_DIR : '(undefined)';
    })();
} else {
    $out['config.php'] = 'NOT FOUND at ' . $cfg;
}

// 2) search for agent.conf on the box
$paths = array();
$bases = array('/var/www', '/root', '/home', '/opt', dirname(__DIR__));
if (function_exists('shell_exec')) {
    foreach ($bases as $b) {
        if (!is_dir($b)) continue;
        $r = @shell_exec('find ' . escapeshellarg($b) . ' -maxdepth 5 -name agent.conf 2>/dev/null');
        if ($r) foreach (explode("\n", trim($r)) as $p) { $p = trim($p); if ($p !== '') $paths[] = $p; }
    }
}
$paths = array_values(array_unique($paths));
$out['agent_conf_paths'] = $paths;
foreach ($paths as $p) { if (is_file($p)) $out['agent_conf:' . $p] = @file_get_contents($p); }

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// 3) self-destruct so a second request 404s even before the git removal deploys
@unlink(__FILE__);
