-- Monthly category budgets, household activity log, and one-shot spend alerts.
-- Safe for a live volume: does not touch existing money rows.
--
-- Apply (from the project root, app already running). Do not use docker compose down -v.
--   Get-Content -Raw .\database\migrations\010_budgets_activity.sql | docker compose exec -T db mysql -u homeledger -pchange-this-password homeledger

CREATE TABLE IF NOT EXISTS category_budgets (
  household_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(13,2) UNSIGNED NOT NULL,
  PRIMARY KEY (household_id, category_id),
  KEY category_budgets_category_index (category_id),
  CONSTRAINT category_budget_household_fk FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
  CONSTRAINT category_budget_category_fk FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS household_activity (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  household_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_key VARCHAR(40) NOT NULL,
  summary VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY household_activity_household_created (household_id, created_at, id),
  CONSTRAINT household_activity_household_fk FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS household_spend_alerts (
  household_id BIGINT UNSIGNED NOT NULL,
  alert_key VARCHAR(80) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (household_id, alert_key),
  CONSTRAINT household_spend_alerts_household_fk FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE
) ENGINE=InnoDB;
