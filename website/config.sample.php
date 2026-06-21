<?php
// rmdownloader website configuration  --  SAMPLE.
// On the VPS:  cp config.sample.php config.php   then edit config.php.
// config.php is git-ignored so secrets are never committed and survive `git pull`.

// ---- Agent connection ----
// Where Agent.exe is reachable FROM THIS WEB SERVER.
//   * Reverse SSH tunnel (recommended for a home PC behind NAT):  http://127.0.0.1:8765
//   * Direct / port-forwarded Windows host with a public IP:      http://YOUR.WIN.IP:8765
define('AGENT_URL', 'http://127.0.0.1:8765');

// Must match the "token" in agent.conf exactly.
define('AGENT_TOKEN', 'CHANGE-THIS-TO-A-LONG-RANDOM-SECRET');

// ---- Website login ----
// Password to open the web UI. CHANGE THIS.
define('WEB_PASSWORD', 'admin');

// Session idle timeout in seconds.
define('SESSION_TIMEOUT', 1800);
