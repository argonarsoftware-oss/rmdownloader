-- rmdownloader — DNS query-log history schema (MySQL / MariaDB)
--
-- One-time setup on the VPS. As root (or any user that can CREATE DATABASE/USER):
--   mysql -u root -p < /var/www/rmdownloader/website/dns-schema.sql
-- then set DB_NAME / DB_USER / DB_PASS in website/config.php to match.
--
-- Adjust the database name / user / password below to taste before importing.

CREATE DATABASE IF NOT EXISTS rmdownloader
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create a least-privilege app user (edit the password!). Safe to re-run.
CREATE USER IF NOT EXISTS 'rmdownloader'@'127.0.0.1' IDENTIFIED BY 'CHANGE-ME';
CREATE USER IF NOT EXISTS 'rmdownloader'@'localhost' IDENTIFIED BY 'CHANGE-ME';
GRANT SELECT, INSERT, UPDATE, DELETE ON rmdownloader.* TO 'rmdownloader'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON rmdownloader.* TO 'rmdownloader'@'localhost';
FLUSH PRIVILEGES;

USE rmdownloader;

-- Every DNS query the TinyDNS server resolves, pushed up by dnl.exe --ingest-url.
-- agent_id matches the rmdownloader agent's id (MachineName-MachineGuid) so the DNS
-- page can filter history by the selected PC.
CREATE TABLE IF NOT EXISTS dns_queries (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id    VARCHAR(120)    NOT NULL,
  ts          DATETIME        NOT NULL,           -- query time (server-local, from the log)
  client      VARCHAR(45)     NOT NULL,           -- client IP (IPv4/IPv6)
  domain      VARCHAR(255)    NOT NULL,
  qtype       VARCHAR(16)     NOT NULL,           -- A / AAAA / HTTPS / …
  disposition VARCHAR(64)     NOT NULL,           -- BLOCKED / LOCAL … / FWD / NXDOMAIN …
  PRIMARY KEY (id),
  KEY idx_agent_ts   (agent_id, id),
  KEY idx_agent_dom  (agent_id, domain),
  KEY idx_agent_disp (agent_id, disposition)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-agent byte offset into queries.log, so the bridge ingests only NEW lines and
-- never re-inserts. Reset to 0 automatically when the log rotates/shrinks.
CREATE TABLE IF NOT EXISTS dns_ingest_state (
  agent_id   VARCHAR(120)    NOT NULL,
  log_offset BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME        NOT NULL,
  PRIMARY KEY (agent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permanent rollup: network-wide visit counts per domain per day. Raw dns_queries rows
-- get aggregated into this (hits += COUNT) then pruned, so this stays tiny forever and
-- answers "what does this network visit most" over any date range.
CREATE TABLE IF NOT EXISTS dns_stats_daily (
  agent_id VARCHAR(120)    NOT NULL,
  day      DATE            NOT NULL,
  domain   VARCHAR(255)    NOT NULL,
  hits     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (agent_id, day, domain),
  KEY idx_agent_day (agent_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Per-agent rollup watermark: the highest dns_queries.id already folded into dns_stats_daily,
-- so each raw row is counted exactly once and pruning never drops un-rolled rows.
CREATE TABLE IF NOT EXISTS dns_rollup_state (
  agent_id   VARCHAR(120)    NOT NULL,
  rolled_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME        NOT NULL,
  PRIMARY KEY (agent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
