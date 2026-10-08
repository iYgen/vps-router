-- Иерархия route-групп ("раздел" -> "подраздел") + поддержка синхронизации
-- со внешними списками IP-адресов (route add <ip> mask <netmask> 0.0.0.0,
-- формат bat-файлов роутеров Keenetic, см. App\IpListImporter).
--
-- Партиционированный UNIQUE-индекс (CREATE UNIQUE INDEX ... WHERE ...)
-- сознательно не используется — не гарантирован на старых сборках SQLite
-- (см. историю проекта: прод работает на SQLite 3.7.17, партиционные
-- индексы появились в 3.8.0). Уникальность import_source_path соблюдается
-- на уровне приложения (App\IpListImporter делает SELECT перед INSERT).

ALTER TABLE rule_groups ADD COLUMN parent_id INTEGER REFERENCES rule_groups(id);
ALTER TABLE rule_groups ADD COLUMN origin TEXT NOT NULL DEFAULT 'manual';
-- manual | imported
ALTER TABLE rule_groups ADD COLUMN import_source_path TEXT;
-- напр. "Global/Discord/Discord.bat" — ключ для идемпотентного re-sync, NULL для ручных групп

CREATE INDEX IF NOT EXISTS idx_rule_groups_parent ON rule_groups(parent_id);
CREATE INDEX IF NOT EXISTS idx_rule_groups_import_source ON rule_groups(import_source_path);
