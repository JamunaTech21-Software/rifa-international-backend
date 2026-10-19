-- Bumped on password change/reset; sessions created with an older version are rejected by the auth middleware.
ALTER TABLE admins ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER password_hash;
