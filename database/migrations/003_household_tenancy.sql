-- HomeLedger household tenancy. Safe for a live volume: keeps all money rows.
-- Assigns existing categories, transactions, recurring entries and users to one household.
--
-- Apply (from the project root, app already running). Do not use docker compose down -v.
--   Get-Content -Raw .\database\migrations\003_household_tenancy.sql | docker compose exec -T db mysql -u homeledger -pchange-this-password homeledger
--
-- Rollback is not practical once money rows are keyed by household. Restore from backup if needed.

CREATE TABLE IF NOT EXISTS households (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO households (name)
SELECT 'Home' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM households LIMIT 1);

SET @homeledger_household_id = (SELECT id FROM households ORDER BY id ASC LIMIT 1);

ALTER TABLE categories
  ADD COLUMN household_id BIGINT UNSIGNED NULL AFTER id;

ALTER TABLE recurring_entries
  ADD COLUMN household_id BIGINT UNSIGNED NULL AFTER id;

ALTER TABLE transactions
  ADD COLUMN household_id BIGINT UNSIGNED NULL AFTER id;

ALTER TABLE users
  ADD COLUMN household_id BIGINT UNSIGNED NULL AFTER id;

UPDATE categories SET household_id = @homeledger_household_id WHERE household_id IS NULL;
UPDATE recurring_entries SET household_id = @homeledger_household_id WHERE household_id IS NULL;
UPDATE transactions SET household_id = @homeledger_household_id WHERE household_id IS NULL;
UPDATE users SET household_id = @homeledger_household_id WHERE household_id IS NULL;

ALTER TABLE categories
  MODIFY household_id BIGINT UNSIGNED NOT NULL,
  DROP INDEX categories_name_type_unique,
  ADD UNIQUE KEY categories_household_name_type_unique (household_id, name, type),
  ADD KEY categories_household_type_index (household_id, type, sort_order),
  ADD CONSTRAINT category_household_fk FOREIGN KEY (household_id) REFERENCES households(id);

ALTER TABLE recurring_entries
  MODIFY household_id BIGINT UNSIGNED NOT NULL,
  DROP INDEX recurring_due_index,
  ADD KEY recurring_household_due_index (household_id, active, next_due_date),
  ADD CONSTRAINT recurring_household_fk FOREIGN KEY (household_id) REFERENCES households(id);

ALTER TABLE transactions DROP FOREIGN KEY transaction_recurring_fk;

ALTER TABLE transactions
  MODIFY household_id BIGINT UNSIGNED NOT NULL,
  DROP INDEX transaction_recurring_date_unique,
  ADD UNIQUE KEY transaction_household_recurring_date_unique (household_id, recurring_entry_id, transaction_date),
  ADD KEY transaction_recurring_entry_index (recurring_entry_id),
  ADD KEY transaction_household_date_index (household_id, transaction_date),
  ADD KEY transaction_household_type_date_index (household_id, type, transaction_date),
  ADD KEY transaction_household_category_date_index (household_id, category_id, transaction_date),
  ADD CONSTRAINT transaction_recurring_fk FOREIGN KEY (recurring_entry_id) REFERENCES recurring_entries(id) ON DELETE SET NULL,
  ADD CONSTRAINT transaction_household_fk FOREIGN KEY (household_id) REFERENCES households(id);

ALTER TABLE users
  MODIFY household_id BIGINT UNSIGNED NOT NULL,
  ADD KEY users_household_index (household_id),
  ADD CONSTRAINT user_household_fk FOREIGN KEY (household_id) REFERENCES households(id);
