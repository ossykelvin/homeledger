-- Continues 003 on a volume where ADD COLUMN already ran.
-- Applied on the original live Docker volume after 003 stopped mid-run.
--
--   Get-Content -Raw .\database\migrations\003b_finish_transaction_household.sql | docker compose exec -T db mysql -u homeledger -pchange-this-password homeledger

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
