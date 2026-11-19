CREATE TABLE IF NOT EXISTS alerts (
  id CHAR(64) NOT NULL PRIMARY KEY,
  source VARCHAR(32) NOT NULL,
  source_id VARCHAR(191) NULL,
  occurred_at DATETIME(3) NULL,
  received_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  title VARCHAR(500) NOT NULL,
  body TEXT NULL,
  payload JSON NOT NULL,
  INDEX idx_source_received (source, received_at),
  INDEX idx_occurred (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS collector_status (
  source VARCHAR(32) NOT NULL PRIMARY KEY,
  last_attempt_at DATETIME(3) NULL,
  last_success_at DATETIME(3) NULL,
  last_error VARCHAR(500) NULL,
  inserted_last_run INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;