<?php
// rmdownloader website configuration  --  SAMPLE.
// On the VPS:  cp config.sample.php config.php   then edit config.php.
// config.php is git-ignored so secrets are never committed and survive `git pull`.

// ---- Agents (the client PCs you manage) ----
// One entry per machine. 'id' is the array key (keep it short, [a-z0-9_]).
//   url   = where Agent.exe is reachable FROM THIS WEB SERVER
//   token = must match the "token" in that machine's agent.conf
//
// Reverse SSH tunnels (recommended): give each PC its own VPS port, e.g.
//   PC1:  ssh -N -R 8765:127.0.0.1:8765 you@vps   -> url http://127.0.0.1:8765
//   PC2:  ssh -N -R 8766:127.0.0.1:8765 you@vps   -> url http://127.0.0.1:8766
function rm_agents() {
    return array(
        'pc1' => array(
            'name'  => 'Main PC',
            'url'   => 'http://127.0.0.1:8765',
            'token' => 'CHANGE-THIS-TO-A-LONG-RANDOM-SECRET-1',
        ),
        'pc2' => array(
            'name'  => 'Office PC',
            'url'   => 'http://127.0.0.1:8766',
            'token' => 'CHANGE-THIS-TO-A-LONG-RANDOM-SECRET-2',
        ),
        // Add more PCs here...
    );
}

// ---- Website login ----
define('WEB_PASSWORD', 'admin');     // CHANGE THIS
define('SESSION_TIMEOUT', 1800);     // idle timeout, seconds
