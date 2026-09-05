-- First-time signup email confirmation. Safe for a live volume: does not touch money rows.
-- Existing users are marked verified so nobody is locked out.
-- Stores a SHA-256 hex hash of the raw confirm token, never the raw token.
--
-- Apply (from the project root, app already running). Do not use docker compose down -v.
--   Get-Content -Raw .\database\migrations\007_email_confirmation.sql | docker compose exec -T db mysql -u homeledger -pchange-this-password homeledger

ALTER TABLE users
  ADD COLUMN email_verified_at DATETIME NULL AFTER locked_until,
  ADD COLUMN email_confirm_token_hash CHAR(64) NULL AFTER email_verified_at,
  ADD COLUMN email_confirm_expires_at DATETIME NULL AFTER email_confirm_token_hash,
  ADD UNIQUE KEY users_email_confirm_token_hash_unique (email_confirm_token_hash);

UPDATE users
  SET email_verified_at = NOW()
  WHERE email_verified_at IS NULL;
