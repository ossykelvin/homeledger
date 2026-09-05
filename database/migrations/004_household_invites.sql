-- HomeLedger household invites. Safe for a live volume: does not touch money rows.
-- Stores a SHA-256 hex hash of the raw invite token, never the raw token.
--
-- Apply (from the project root, app already running). Do not use docker compose down -v.
--   Get-Content -Raw .\database\migrations\004_household_invites.sql | docker compose exec -T db mysql -u homeledger -pchange-this-password homeledger
--
-- If compose exec hangs after success, the SQL may still have run. Check with:
--   docker exec homeledger-db-1 mysql -u homeledger -pchange-this-password homeledger -e "SHOW TABLES LIKE 'household_invites'"

CREATE TABLE IF NOT EXISTS household_invites (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  household_id BIGINT UNSIGNED NOT NULL,
  invited_by_user_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  accepted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY household_invites_token_hash_unique (token_hash),
  KEY household_invites_household_expiry (household_id, expires_at),
  KEY household_invites_email (email),
  CONSTRAINT invite_household_fk FOREIGN KEY (household_id) REFERENCES households(id),
  CONSTRAINT invite_invited_by_user_fk FOREIGN KEY (invited_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB;
