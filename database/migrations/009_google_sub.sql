-- Optional Google account link. Safe for a live volume: does not touch money rows.
-- Unique and nullable so existing password users keep working. Multiple NULLs are allowed.
-- Backfill is not required.
--
-- Apply (from the project root, app already running). Do not use docker compose down -v.
--   Get-Content -Raw .\database\migrations\009_google_sub.sql | docker compose exec -T db mysql -u homeledger -pchange-this-password homeledger

ALTER TABLE users
  ADD COLUMN google_sub VARCHAR(255) NULL AFTER password_hash,
  ADD UNIQUE KEY users_google_sub_unique (google_sub);
