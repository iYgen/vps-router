-- Сброс пароля подписчика (клиентский портал): токен + срок действия.
ALTER TABLE billing_subscribers ADD COLUMN reset_token TEXT;
ALTER TABLE billing_subscribers ADD COLUMN reset_expires TEXT;
