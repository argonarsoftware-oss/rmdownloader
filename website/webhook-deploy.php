<?php
/**
 * GitHub Webhook Auto-Deploy
 * rmdownloader
 * Version: 1.0
 *
 * Only deploys when a commit message contains [deploy]. Normal pushes are ignored.
 * Mirrors the Argonar Construction webhook, adapted to this repo's layout:
 *   - Apache serves website/  but the git repo root is its PARENT (/var/www/rmdownloader),
 *     so this file lives in website/ (served at https://dos.argonar.co/webhook-deploy.php)
 *     while git fetch/reset target the repo root one level up.
 *   - The secret lives in config.php (git-ignored, survives `git reset --hard`), not inline.
 *   - config.php / agent.conf are git-ignored, so a hard reset never clobbers local secrets.
 *
 * Setup:
 * 1. This file deploys itself once it's on the server; the first time, pull manually.
 * 2. GitHub repo -> Settings -> Webhooks -> Add webhook
 *      - Payload URL:  https://dos.argonar.co/webhook-deploy.php
 *      - Content type: application/json
 *      - Secret:       (same value as WEBHOOK_SECRET in config.php)
 *      - Events:       Just the push event
 * 3. Set WEBHOOK_SECRET in config.php to match the GitHub secret.
 * 4. Let the web-server user (www-data) run git in the repo. Run ONCE on the server:
 *      sudo chown -R www-data:www-data /var/www/rmdownloader
 *      sudo -u www-data git config --global --add safe.directory /var/www/rmdownloader
 */

require_once __DIR__ . '/config.php';

// ============================================================
// CONFIGURATION
// ============================================================
// WEB_ROOT is the GIT REPO ROOT (parent of this website/ dir), not the Apache docroot.
define('WEB_ROOT', defined('DEPLOY_WEB_ROOT') ? DEPLOY_WEB_ROOT : dirname(__DIR__));
define('DEPLOY_KEYWORD', '[deploy]');
define('LOG_FILE', (defined('DATA_DIR') ? DATA_DIR : __DIR__ . '/data') . '/deploy.log');
define('ALLOWED_BRANCH', 'refs/heads/main');

// ============================================================
// HELPERS
// ============================================================
function verifySignature($payload, $signature) {
    if (empty($signature)) return false;
    if (!defined('WEBHOOK_SECRET') || WEBHOOK_SECRET === '') return false;  // unset secret = locked
    $hash = 'sha256=' . hash_hmac('sha256', $payload, WEBHOOK_SECRET);
    return hash_equals($hash, $signature);
}

function logMessage($message) {
    $dir = dirname(LOG_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $line = '[' . date('Y-m-d H:i:s') . "] {$message}\n";
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

// ============================================================
// PROCESS WEBHOOK
// ============================================================

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Read payload
$rawPayload = file_get_contents('php://input');

// Handle both JSON and form-urlencoded content types
$contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE']
             : (isset($_SERVER['HTTP_CONTENT_TYPE']) ? $_SERVER['HTTP_CONTENT_TYPE'] : '');

if (stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
    parse_str($rawPayload, $parsed);
    $jsonPayload = isset($parsed['payload']) ? $parsed['payload'] : '';
} else {
    $jsonPayload = $rawPayload;
}
// GitHub signs the EXACT bytes it sends (the raw body), regardless of content type.
$signaturePayload = $rawPayload;

// Verify GitHub signature
$signature = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '';

if (!verifySignature($signaturePayload, $signature)) {
    http_response_code(403);
    logMessage("REJECTED: Invalid signature. Content-Type: {$contentType}");
    exit('Forbidden');
}

// Parse payload
$data = json_decode($jsonPayload, true);
if (!$data) {
    http_response_code(400);
    logMessage("REJECTED: Invalid JSON. Content-Type: {$contentType}. Raw length: " . strlen($rawPayload));
    exit('Bad Request');
}

// Check branch
$ref = isset($data['ref']) ? $data['ref'] : '';
if ($ref !== ALLOWED_BRANCH) {
    http_response_code(200);
    logMessage("SKIPPED: Push to {$ref} (not main)");
    exit('Ignored: not main branch');
}

// Check for [deploy] keyword in any commit message
$shouldDeploy = false;
$deployCommit = '';
$commits = isset($data['commits']) ? $data['commits'] : array();

foreach ($commits as $commit) {
    $message = isset($commit['message']) ? $commit['message'] : '';
    if (stripos($message, DEPLOY_KEYWORD) !== false) {
        $shouldDeploy = true;
        $deployCommit = substr($message, 0, 80);
        break;
    }
}

if (!$shouldDeploy) {
    http_response_code(200);
    $count = count($commits);
    logMessage("SKIPPED: {$count} commit(s) pushed without [deploy] keyword");
    exit('OK: No [deploy] keyword found. Skipping deployment.');
}

// ============================================================
// DEPLOY: git fetch + hard reset to origin/main
// ============================================================
logMessage("DEPLOYING: \"{$deployCommit}\"");

$output = array();
$returnCode = 0;
$root = WEB_ROOT;

// Skip git's "dubious ownership" check (www-data running git in a root-owned tree).
putenv('GIT_CONFIG_GLOBAL=/dev/null');

exec('git -c safe.directory=' . escapeshellarg($root) . ' -C ' . escapeshellarg($root)
   . ' fetch origin main 2>&1', $output, $returnCode);

if ($returnCode === 0) {
    exec('git -c safe.directory=' . escapeshellarg($root) . ' -C ' . escapeshellarg($root)
       . ' reset --hard origin/main 2>&1', $output, $returnCode);
}

$outputStr = implode("\n", $output);

if ($returnCode === 0) {
    // Restore ownership and keep the command queue writable by Apache after the reset.
    exec('chown -R www-data:www-data ' . escapeshellarg($root) . ' 2>&1');
    exec('chmod -R 775 ' . escapeshellarg($root . '/website/data') . ' 2>&1');
    logMessage("SUCCESS: deploy completed\n{$outputStr}");
    http_response_code(200);
    echo "Deployed successfully.\n{$outputStr}";
} else {
    logMessage("FAILED: deploy failed (code {$returnCode})\n{$outputStr}");
    http_response_code(500);
    echo "Deploy failed.\n{$outputStr}";
}
