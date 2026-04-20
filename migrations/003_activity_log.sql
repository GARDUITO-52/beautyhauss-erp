-- Migration 003 — Activity log for audit trail

CREATE TABLE IF NOT EXISTS activity_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED,
  user_name  VARCHAR(100),
  module     VARCHAR(50) NOT NULL,
  action     VARCHAR(50) NOT NULL,
  record_id  VARCHAR(50),
  label      VARCHAR(255),
  meta       JSON,
  ip         VARCHAR(45),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_module_action (module, action),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
