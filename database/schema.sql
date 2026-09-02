CREATE DATABASE IF NOT EXISTS homeledger
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE homeledger;

CREATE TABLE IF NOT EXISTS categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  type ENUM('income', 'expense') NOT NULL,
  colour CHAR(7) NOT NULL DEFAULT '#8d83ff',
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY categories_name_type_unique (name, type)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS recurring_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
  KEY recurring_due_index (active, next_due_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
  UNIQUE KEY transaction_recurring_date_unique (recurring_entry_id, transaction_date),
  KEY transaction_date_index (transaction_date),
  KEY transaction_type_date_index (type, transaction_date),
  KEY transaction_category_date_index (category_id, transaction_date)
) ENGINE=InnoDB;

INSERT INTO categories (name, type, colour, sort_order) VALUES
  ('Salary', 'income', '#c7f36b', 10),
  ('Freelance', 'income', '#6ce5d4', 20),
  ('Benefits', 'income', '#8d83ff', 30),
  ('Investment', 'income', '#70a1ff', 40),
  ('Other income', 'income', '#a8b3c2', 90),
  ('Housing', 'expense', '#8d83ff', 10),
  ('Groceries', 'expense', '#6ce5d4', 20),
  ('Utilities', 'expense', '#70a1ff', 30),
  ('Transport', 'expense', '#ffc857', 40),
  ('Health', 'expense', '#ff826b', 50),
  ('Entertainment', 'expense', '#d176ff', 60),
  ('Subscriptions', 'expense', '#73d2de', 70),
  ('Other expense', 'expense', '#a8b3c2', 90)
ON DUPLICATE KEY UPDATE colour = VALUES(colour), sort_order = VALUES(sort_order);
