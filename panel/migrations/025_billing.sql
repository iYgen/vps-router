-- Биллинг: тарифы (планы), подписчики (конечные пользователи), подписки и платежи.
-- Модель: Тариф → Подписка подписчика (активна до expires_at) → его устройства
-- (clients.subscriber_id). При просрочке устройства подписчика отключаются
-- (clients.revoked=1) и конфиг переприменяется. Фича-гейт: feature-billing.

CREATE TABLE IF NOT EXISTS billing_plans (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT NOT NULL,
    price         REAL NOT NULL DEFAULT 0,          -- цена за период
    currency      TEXT NOT NULL DEFAULT 'RUB',
    period_days   INTEGER NOT NULL DEFAULT 30,      -- длительность периода подписки
    device_limit  INTEGER NOT NULL DEFAULT 1,       -- сколько устройств разрешено
    traffic_gb    INTEGER,                          -- лимит трафика, ГБ (NULL = без лимита)
    enabled       INTEGER NOT NULL DEFAULT 1,
    sort_order    INTEGER NOT NULL DEFAULT 0,
    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS billing_subscribers (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,
    email       TEXT,                               -- для напоминаний о продлении
    note        TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS billing_subscriptions (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    subscriber_id  INTEGER NOT NULL REFERENCES billing_subscribers(id) ON DELETE CASCADE,
    plan_id        INTEGER REFERENCES billing_plans(id) ON DELETE SET NULL,
    status         TEXT NOT NULL DEFAULT 'active',  -- active | expired | cancelled
    started_at     TEXT NOT NULL DEFAULT (datetime('now')),
    expires_at     TEXT NOT NULL,                   -- дата/время окончания доступа
    auto_renew     INTEGER NOT NULL DEFAULT 0,
    reminded_at    TEXT,                            -- когда последний раз слали напоминание
    enforced_at    TEXT,                            -- когда последний раз применяли отключение
    created_at     TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_bsub_subscriber ON billing_subscriptions(subscriber_id);
CREATE INDEX IF NOT EXISTS idx_bsub_status ON billing_subscriptions(status, expires_at);

CREATE TABLE IF NOT EXISTS billing_payments (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    subscriber_id   INTEGER NOT NULL REFERENCES billing_subscribers(id) ON DELETE CASCADE,
    subscription_id INTEGER REFERENCES billing_subscriptions(id) ON DELETE SET NULL,
    plan_id         INTEGER REFERENCES billing_plans(id) ON DELETE SET NULL,
    amount          REAL NOT NULL DEFAULT 0,
    currency        TEXT NOT NULL DEFAULT 'RUB',
    method          TEXT NOT NULL DEFAULT 'manual', -- manual | yookassa | cryptocloud
    status          TEXT NOT NULL DEFAULT 'paid',   -- pending | paid | failed | cancelled
    period_days     INTEGER NOT NULL DEFAULT 0,     -- на сколько дней продлевает
    external_id     TEXT,                           -- id платежа во внешнем шлюзе
    comment         TEXT,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    paid_at         TEXT
);
CREATE INDEX IF NOT EXISTS idx_bpay_subscriber ON billing_payments(subscriber_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_bpay_external ON billing_payments(method, external_id);

-- Привязка существующих устройств (clients) к подписчику. NULL = без подписки
-- (устройства админа/без биллинга) — на такие устройства биллинг не влияет.
ALTER TABLE clients ADD COLUMN subscriber_id INTEGER REFERENCES billing_subscribers(id) ON DELETE SET NULL;
