-- Безопасность входа: сброс пароля на email, журнал с категориями
-- (действия/авторизации/блокировки), блокировка IP после серии неудачных
-- попыток входа.

ALTER TABLE users ADD COLUMN email TEXT;

CREATE TABLE IF NOT EXISTS password_resets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL, -- sha256(токена) — сам токен никогда не хранится
    expires_at TEXT NOT NULL,
    used_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets(user_id);

ALTER TABLE audit_log ADD COLUMN category TEXT NOT NULL DEFAULT 'action'; -- action|auth|block
ALTER TABLE audit_log ADD COLUMN ip TEXT;

CREATE TABLE IF NOT EXISTS login_blocks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip TEXT NOT NULL,
    blocked_until TEXT NOT NULL,
    reason TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_login_blocks_ip ON login_blocks(ip, blocked_until);
