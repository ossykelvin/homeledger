-- Explicit household owner. Safe for a live volume: does not touch money rows.
-- Backfills owner_user_id from the previous earliest-member rule, then uses that column.
-- Nullable so a household can be created before its first user, and so last-member
-- deletion can clear the owner before removing the user row.
--
-- Apply (from the project root, app already running). Do not use docker compose down -v.
--   Get-Content -Raw .\database\migrations\008_household_owner_user.sql | docker compose exec -T db mysql -u homeledger -pchange-this-password homeledger

ALTER TABLE households
  ADD COLUMN owner_user_id BIGINT UNSIGNED NULL AFTER public_code,
  ADD KEY households_owner_user_index (owner_user_id);

UPDATE households AS h
INNER JOIN users AS u ON u.household_id = h.id
LEFT JOIN users AS earlier
  ON earlier.household_id = h.id
 AND (
   earlier.created_at < u.created_at
   OR (earlier.created_at = u.created_at AND earlier.id < u.id)
 )
SET h.owner_user_id = u.id
WHERE h.owner_user_id IS NULL
  AND earlier.id IS NULL;

ALTER TABLE households
  ADD CONSTRAINT household_owner_user_fk FOREIGN KEY (owner_user_id) REFERENCES users(id);
