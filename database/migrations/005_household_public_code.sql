-- HomeLedger public household codes. Safe for a live volume: does not touch money rows.
-- Adds a nullable CHAR(19) code (16 alphanumeric characters in 4 groups of 4).
-- households.id stays the internal BIGINT primary key and all foreign keys stay numeric.
--
-- Apply (from the project root, app already running). Do not use docker compose down -v.
--   Get-Content -Raw .\database\migrations\005_household_public_code.sql | docker compose exec -T db mysql -u homeledger -pchange-this-password homeledger
--   docker compose exec -T app php scripts/backfill_household_codes.php
--
-- The PHP script fills every household with a CSPRNG code, then applies 005b
-- (NOT NULL and UNIQUE). Re-run the script if a row is still null.

ALTER TABLE households
  ADD COLUMN public_code CHAR(19) NULL AFTER name;
