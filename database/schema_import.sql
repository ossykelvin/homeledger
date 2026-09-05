CREATE DATABASE IF NOT EXISTS koptryzt_homeledger
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE koptryzt_homeledger;

CREATE TABLE IF NOT EXISTS households (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  public_code CHAR(19) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  state_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY households_public_code_unique (public_code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  household_id BIGINT UNSIGNED NOT NULL,
  login VARCHAR(190) NOT NULL,
  display_name VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY users_login_unique (login),
  KEY users_household_index (household_id),
  CONSTRAINT user_household_fk FOREIGN KEY (household_id) REFERENCES households(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  login_key VARCHAR(190) NOT NULL,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY login_attempts_lookup (ip, attempted_at),
  KEY login_attempts_login (login_key, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS household_invites (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  household_id BIGINT UNSIGNED NOT NULL,
  invited_by_user_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  accepted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY household_invites_token_hash_unique (token_hash),
  KEY household_invites_household_expiry (household_id, expires_at),
  KEY household_invites_email (email),
  CONSTRAINT invite_household_fk FOREIGN KEY (household_id) REFERENCES households(id),
  CONSTRAINT invite_invited_by_user_fk FOREIGN KEY (invited_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  household_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  type ENUM('income', 'expense') NOT NULL,
  colour CHAR(7) NOT NULL DEFAULT '#8d83ff',
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY categories_household_name_type_unique (household_id, name, type),
  KEY categories_household_type_index (household_id, type, sort_order),
  CONSTRAINT category_household_fk FOREIGN KEY (household_id) REFERENCES households(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS recurring_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  household_id BIGINT UNSIGNED NOT NULL,
  type ENUM('income', 'expense') NOT NULL,
  description VARCHAR(160) NOT NULL,
  amount DECIMAL(13,2) UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  frequency ENUM('daily', 'weekly', 'monthly', 'yearly') NOT NULL,
  interval_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  start_date DATE NOT NULL,
  next_due_date DATE NOT NULL,
  end_date DATE NULL,
  notes VARCHAR(500) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT recurring_category_fk FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT recurring_household_fk FOREIGN KEY (household_id) REFERENCES households(id),
  KEY recurring_household_due_index (household_id, active, next_due_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  household_id BIGINT UNSIGNED NOT NULL,
  type ENUM('income', 'expense') NOT NULL,
  description VARCHAR(160) NOT NULL,
  amount DECIMAL(13,2) UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  transaction_date DATE NOT NULL,
  notes VARCHAR(500) NULL,
  source ENUM('manual', 'recurring') NOT NULL DEFAULT 'manual',
  recurring_entry_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT transaction_category_fk FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT transaction_recurring_fk FOREIGN KEY (recurring_entry_id) REFERENCES recurring_entries(id) ON DELETE SET NULL,
  CONSTRAINT transaction_household_fk FOREIGN KEY (household_id) REFERENCES households(id),
  UNIQUE KEY transaction_household_recurring_date_unique (household_id, recurring_entry_id, transaction_date),
  KEY transaction_recurring_entry_index (recurring_entry_id),
  KEY transaction_household_date_index (household_id, transaction_date),
  KEY transaction_household_type_date_index (household_id, type, transaction_date),
  KEY transaction_household_category_date_index (household_id, category_id, transaction_date)
) ENGINE=InnoDB;
