-- Биллинг: внутренний баланс, допы (трафик/устройства) и смена тарифа с пересчётом.
--  • balance — внутренний счёт подписчика (возвраты/доплаты идут сюда);
--  • extra_traffic_gb / extra_devices — купленные допы ТЕКУЩЕГО периода (поверх тарифа);
--  • payments.purpose — на что платёж (подписка/пополнение/доп/смена);
--  • ledger — прозрачная история движения баланса.
-- Важно: смена тарифа НЕ сбрасывает traffic_period_start и израсходованный трафик.

ALTER TABLE billing_subscribers ADD COLUMN balance REAL NOT NULL DEFAULT 0;

ALTER TABLE billing_subscriptions ADD COLUMN extra_traffic_gb REAL NOT NULL DEFAULT 0;
ALTER TABLE billing_subscriptions ADD COLUMN extra_devices INTEGER NOT NULL DEFAULT 0;

ALTER TABLE billing_payments ADD COLUMN purpose TEXT NOT NULL DEFAULT 'subscription'; -- subscription | topup | addon | change

CREATE TABLE IF NOT EXISTS billing_balance_ledger (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    subscriber_id INTEGER NOT NULL REFERENCES billing_subscribers(id) ON DELETE CASCADE,
    amount        REAL NOT NULL,        -- + пополнение/возврат, − списание
    reason        TEXT,
    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_bledger_sub ON billing_balance_ledger(subscriber_id, id DESC);
