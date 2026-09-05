-- Step 2 of household public codes. Run only after every household has a public_code.
-- scripts/backfill_household_codes.php applies this itself after filling rows.
--
--   Get-Content -Raw .\database\migrations\005b_household_public_code_unique.sql | docker compose exec -T db mysql -u homeledger -pchange-this-password homeledger
--
-- Do not use docker compose down -v.

ALTER TABLE households
  MODIFY public_code CHAR(19) NOT NULL,
  ADD UNIQUE KEY households_public_code_unique (public_code);
