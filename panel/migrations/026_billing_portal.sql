-- Клиентский портал самообслуживания + реальный лимит трафика.
--  • подписчику нужен пароль для входа в портал;
--  • лимит трафика считается за текущий оплаченный период: traffic_period_start
--    сбрасывается при каждом продлении, traffic_blocked=1 ставится при превышении
--    (устройства отключаются, пока не начнётся новый период/продление).

ALTER TABLE billing_subscribers ADD COLUMN password_hash TEXT;

ALTER TABLE billing_subscriptions ADD COLUMN traffic_period_start TEXT;
ALTER TABLE billing_subscriptions ADD COLUMN traffic_blocked INTEGER NOT NULL DEFAULT 0;
