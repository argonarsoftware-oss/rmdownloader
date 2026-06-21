<?php
// rmdownloader website configuration  --  SAMPLE.
// On the VPS:  cp config.sample.php config.php   then edit config.php.
// config.php is git-ignored so secrets are never committed and survive `git pull`.

// ---- Agents (the client PCs you manage) ----
// One entry per machine. The agent reverse-connects to this site using its token;
// you do NOT set any URL or port per PC. The token maps the connection to its id.
//   id    = array key (short, [a-z0-9_])
//   name  = label shown in the PC picker
//   token = must match the "token" in that machine's agent.conf
function rm_agents() {
    return array(
        'pc1' => array('name' => 'Main PC',   'token' => 'CHANGE-THIS-TO-A-LONG-RANDOM-SECRET-1'),
        'pc2' => array('name' => 'Office PC', 'token' => 'CHANGE-THIS-TO-A-LONG-RANDOM-SECRET-2'),
        // Add more PCs here...
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
