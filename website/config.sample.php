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

// ---- Remote terminal ----
// Allow running shell commands on the agent PC from the web "Terminal" panel.
// Commands run as whatever user the agent runs as (SYSTEM if auto-started elevated).
// Set false to disable command execution entirely.
define('ALLOW_EXEC', true);
