-- Two-factor auth (TOTP) for panel login. Secret is stored base32 (encrypted at
-- rest via App\Secrets in the Auth layer), recovery codes as password_hash'ed
-- one-time strings (JSON array). Safe ALTERs only.
ALTER TABLE users ADD COLUMN totp_secret TEXT;
ALTER TABLE users ADD COLUMN totp_enabled INTEGER NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN totp_recovery TEXT;
