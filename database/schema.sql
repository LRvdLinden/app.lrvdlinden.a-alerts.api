-- A-Alerts private parallel database (MySQL 8/MariaDB 10.5+)
CREATE TABLE IF NOT EXISTS api_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source VARCHAR(48) NOT NULL,
  external_id VARCHAR(191) NOT NULL,
  kind VARCHAR(48) NOT NULL,
  title VARCHAR(512) NOT NULL DEFAULT '',
  occurred_at DATETIME(3) NULL,
  first_seen_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_seen_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  fingerprint CHAR(64) NOT NULL,
  payload LONGTEXT NOT NULL,
  UNIQUE KEY uniq_event(source, external_id),
  INDEX source_seen(source,last_seen_at),
  INDEX kind_seen(kind,last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS api_collector_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source VARCHAR(48) NOT NULL,
  started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  finished_at DATETIME(3) NULL,
  ok TINYINT(1) NOT NULL DEFAULT 0,
  total INT NOT NULL DEFAULT 0,
  inserted INT NOT NULL DEFAULT 0,
  changed INT NOT NULL DEFAULT 0,
  error_code VARCHAR(80) NULL,
  INDEX run_source(source,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
