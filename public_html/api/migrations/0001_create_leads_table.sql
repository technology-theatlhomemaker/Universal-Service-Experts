-- 0001 — initial leads queue table.
-- Applied automatically by /api/migrate.php?token=<admin_token>.

CREATE TABLE IF NOT EXISTS leads (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  payload         LONGTEXT      NOT NULL,
  status          ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_attempt_at DATETIME      NULL,
  last_error      TEXT          NULL,
  sent_at         DATETIME      NULL,
  source_page     VARCHAR(255)  NULL,
  ip_address      VARCHAR(45)   NULL,
  INDEX idx_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
