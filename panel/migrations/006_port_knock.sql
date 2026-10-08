-- Сохраняет конфигурацию port knocking, применённую через "Спрятать SSH"
-- (App\Provisioner::hardenPortKnock), чтобы панель могла сама "постучать"
-- перед следующими SSH-подключениями к этому серверу (test-connection,
-- metrics, provision, health-check по cron) — раньше это не сохранялось
-- нигде, и включение защиты ломало все остальные SSH-based фичи панели
-- для этого сервера, требуя стука вручную (см. docs/infrastructure-ui_old.md
-- TODO "Панель не умеет сама стучать...").

ALTER TABLE servers ADD COLUMN knock_enabled INTEGER NOT NULL DEFAULT 0;
ALTER TABLE servers ADD COLUMN knock_protected_port INTEGER;
ALTER TABLE servers ADD COLUMN knock_ports TEXT; -- JSON-массив портов по порядку
