-- Android-приложение VPS Router: вход по QR без пароля.
--   app_pairings — одноразовые коды из QR на странице «Приложение» (живут 10 минут);
--   app_tokens   — постоянные токены привязанных телефонов (можно отозвать в панели).
-- Хранятся только SHA-256 кодов и токенов, сами значения панель не помнит.

CREATE TABLE IF NOT EXISTS app_pairings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    code_hash   TEXT NOT NULL UNIQUE,
    client_id   INTEGER,              -- устройство панели, которым станет телефон (NULL — выбрать в приложении)
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    expires_at  TEXT NOT NULL,
    used_at     TEXT
);

CREATE TABLE IF NOT EXISTS app_tokens (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL,
    token_hash    TEXT NOT NULL UNIQUE,
    name          TEXT NOT NULL,
    client_id     INTEGER,
    created_at    TEXT NOT NULL DEFAULT (datetime('now')),
    last_used_at  TEXT,
    last_ip       TEXT,
    revoked_at    TEXT
);
CREATE INDEX IF NOT EXISTS idx_app_tokens_user ON app_tokens(user_id, revoked_at);
