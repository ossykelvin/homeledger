-- HomeLedger auth v1. Safe for a live volume: CREATE TABLE IF NOT EXISTS only.
-- Does not alter categories, transactions, or recurring_entries.
--
-- Apply (from the project root, app already running):
--   Get-Content -Raw .\database\migrations\002_create_users.sql | docker compose exec -T db mysql -u homeledger -pchange-this-password homeledger
--
-- Rollback (does not touch money tables):
--   DROP TABLE IF EXISTS login_attempts;
--   DROP TABLE IF EXISTS users;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  login VARCHAR(190) NOT NULL,
  display_name VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY users_login_unique (login)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  login_key VARCHAR(190) NOT NULL,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY login_attempts_lookup (ip, attempted_at),
  KEY login_attempts_login (login_key, attempted_at)
) ENGINE=InnoDB;
