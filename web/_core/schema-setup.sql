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

-- A-Alerts v2: extensions for private independent API
CREATE TABLE IF NOT EXISTS api_kv (
  key_name VARCHAR(191) PRIMARY KEY, value_json LONGTEXT NOT NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS api_news_updates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, news_id VARCHAR(191) NOT NULL,
  title VARCHAR(255) NULL, body TEXT NOT NULL, source_url VARCHAR(1024) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX news_id_idx(news_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS api_feedback (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, target VARCHAR(191) NOT NULL,
  reason VARCHAR(80) NOT NULL, note TEXT NULL, status VARCHAR(32) NOT NULL DEFAULT 'open',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX target_idx(target,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS api_aircraft_positions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, aircraft_id VARCHAR(48) NOT NULL,
  captured_at DATETIME(3) NOT NULL, lat DECIMAL(10,6) NOT NULL,
  lon DECIMAL(10,6) NOT NULL, altitude_m INT NULL,
  UNIQUE KEY unique_aircraft_fix(aircraft_id,captured_at),
  INDEX aircraft_timeline(aircraft_id,captured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS api_incident_links (
  event_source VARCHAR(48) NOT NULL, event_external_id VARCHAR(191) NOT NULL,
  incident_key VARCHAR(191) NOT NULL,
  PRIMARY KEY(event_source,event_external_id),
  INDEX incident_idx(incident_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS api_metric_counts (
  kind VARCHAR(40) NOT NULL, day_utc DATE NOT NULL,
  hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY(kind,day_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
