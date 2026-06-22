<?php
// rmdownloader website configuration  --  SAMPLE.
// On the VPS:  cp config.sample.php config.php   then edit config.php.
// config.php is git-ignored so secrets are never committed and survive `git pull`.

// ---- Auto-enroll (zero-touch onboarding) ----
// Set ONE shared key here and put the same value as the token in the agent bundle's
// agent.conf. Then just copy the agent to any PC and run it — it auto-registers itself
// (by machine id, shown as the hostname) and appears in the PC picker. No per-PC config,
// no editing this file again.
//   Security: only someone with this key can register an agent, and an agent can only
//   expose its OWN machine. Use a long random value and keep the site on HTTPS.
//   Leave '' to disable auto-enroll (then use the static rm_agents() list below).
define('ENROLL_KEY', 'CHANGE-THIS-TO-A-LONG-RANDOM-ENROLL-KEY');

// ---- Static agents (optional) ----
// Pin specific PCs with their own per-PC token (alternative to auto-enroll). Can be empty.
//   id => array('name' => label, 'token' => that PC's agent token)
function rm_agents() {
    return array(
        // 'pc1' => array('name' => 'Main PC', 'token' => 'a-per-pc-token'),
    );
}

// Where the file-based command queue lives. Must be writable by Apache (www-data).
define('DATA_DIR', __DIR__ . '/data');

// ---- Website login (browser) ----
define('WEB_PASSWORD', 'admin');     // CHANGE THIS
define('SESSION_TIMEOUT', 1800);     // idle timeout, seconds

// ---- API key (programmatic access, e.g. curl / Claude Code) ----
// When set, api.php also accepts requests authenticated WITHOUT a browser login by
// passing the key as ?key=<API_KEY> or the header  X-Api-Key: <API_KEY>.
// Leave '' to disable key access (browser login only). Use a long random value over HTTPS.
//   e.g.  GET api.php?action=list&agent=pc1&path=C:\&key=<API_KEY>
define('API_KEY', '');

// ---- GitHub auto-deploy webhook (website/webhook-deploy.php) ----
// Shared secret that must match the GitHub webhook's "Secret" field. The webhook only
// runs `git fetch + reset --hard origin/main` when a pushed commit message contains [deploy]
// AND the request signature (X-Hub-Signature-256) verifies against this value.
// Leave '' to DISABLE the webhook entirely (all requests are rejected). Use a long random value.
define('WEBHOOK_SECRET', '');
// Optional override of the git repo root the webhook deploys (defaults to the parent of website/).
// define('DEPLOY_WEB_ROOT', '/var/www/rmdownloader');

// ---- Remote terminal ----
// Allow running shell commands on the agent PC from the web "Terminal" panel.
// Commands run as whatever user the agent runs as (SYSTEM if auto-started elevated).
// Set false to disable command execution entirely.
define('ALLOW_EXEC', true);

// ---- DNS manager (TinyDNS) ----
// Default folder on the agent PC holding records.txt / blocklist.txt / dnl.exe,
// and the scheduled-task name. Both are editable per-PC in the DNS page.
define('DNS_DIR', 'C:\\Users\\Administrator\\Desktop\\dns\\dist');
define('DNS_TASK', 'TinyDNS');

// ---- MySQL (DNS query-log history) ----
// The DNS page reads query history from here (dns-log.php). Rows are fed in by an
// "agent bridge": the VPS reads only the NEW bytes of queries.log through the agent's
// exec op (a shared read that never stops dnl.exe) and inserts them — see dns-sync.php /
// cron/dns-sync.php. Leave DB_NAME or DB_USER '' to disable the database entirely; the
// DNS page then falls back to tailing queries.log live via the agent.
// One-time setup on the VPS: import website/dns-schema.sql, e.g.
//   mysql -u root -p < /var/www/rmdownloader/website/dns-schema.sql
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'rmdownloader');
define('DB_USER', 'rmdownloader');
define('DB_PASS', '');
