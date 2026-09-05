-- Household membership/invite change token for auto-refresh.
-- Safe for a live volume: does not touch money rows.
--
-- Apply (from the project root, app already running). Do not use docker compose down -v.
--   Get-Content -Raw .\database\migrations\006_household_state_version.sql | docker compose exec -T db mysql -u homeledger -pchange-this-password homeledger

ALTER TABLE households
  ADD COLUMN state_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER updated_at;
