-- rmdownloader — CDP independent-node schema.
-- Lets chnav.exe run WITHOUT the agent: it reverse-connects to cdp-node.php, pushes nav events +
-- status, and pulls its rules. These tables hold that data centrally (durable, cross-PC).
-- Import once on the VPS:  mysql -u root -p rmdownloader < website/cdp-schema.sql
-- Then GRANT the app user the same SELECT/INSERT/UPDATE/DELETE it has on the dns_* tables.

CREATE TABLE IF NOT EXISTS cdp_nodes (
  node_id     VARCHAR(80)  NOT NULL PRIMARY KEY,   -- MachineName + MachineGuid (like the agent id)
  name        VARCHAR(120) NOT NULL DEFAULT '',
  first_seen  DATETIME     NULL,
  last_seen   DATETIME     NULL,                   -- heartbeat; UI shows online if recent
  chrome      VARCHAR(64)  NOT NULL DEFAULT '',    -- Chrome version string from the debug port
  tabs        TEXT         NULL,                   -- JSON: current open page targets [url|title]
  running     TINYINT      NOT NULL DEFAULT 0      -- monitor self-reported running
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cdp_events (
  id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  node_id VARCHAR(80)     NOT NULL,
  ts      VARCHAR(19)     NOT NULL DEFAULT '',     -- 'YYYY-MM-DD HH:MM:SS' (node-local)
  type    VARCHAR(12)     NOT NULL DEFAULT '',     -- NAV/SPA/DOC/req/BLOCK/WARN/REPLACE/REDIRECT
  url     VARCHAR(1024)   NOT NULL DEFAULT '',
  title   VARCHAR(255)    NOT NULL DEFAULT '',
  KEY k_node_id   (node_id, id),
  KEY k_node_type (node_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cdp_rules (
  node_id    VARCHAR(80) NOT NULL PRIMARY KEY,     -- per-node rules; node_id '*' = global default
  rules      MEDIUMTEXT  NULL,                     -- blt.txt content
  version    INT         NOT NULL DEFAULT 0,       -- bumped on every save so chnav detects changes
  updated_at DATETIME    NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- If you locked the app user down (as dns-schema.sql does), also run e.g.:
--   GRANT SELECT, INSERT, UPDATE, DELETE ON rmdownloader.cdp_nodes  TO 'rmdownloader'@'localhost';
--   GRANT SELECT, INSERT, UPDATE, DELETE ON rmdownloader.cdp_events TO 'rmdownloader'@'localhost';
--   GRANT SELECT, INSERT, UPDATE, DELETE ON rmdownloader.cdp_rules  TO 'rmdownloader'@'localhost';
--   FLUSH PRIVILEGES;
