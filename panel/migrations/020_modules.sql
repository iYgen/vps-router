-- Модульная система: установленные модули + их настройки.
-- module_settings НАМЕРЕННО без внешнего ключа на modules: настройки должны
-- переживать удаление модуля (опция «сохранить настройки»), чтобы подтянуться
-- при повторной установке. Удаление строки настроек — только явным выбором.

CREATE TABLE IF NOT EXISTS modules (
    id TEXT PRIMARY KEY,                         -- напр. "router-mikrotik"
    name TEXT NOT NULL,
    version TEXT NOT NULL DEFAULT '1.0.0',
    type TEXT NOT NULL DEFAULT 'generic',        -- router | notify | payment | ...
    active INTEGER NOT NULL DEFAULT 0,            -- 0 установлен/выключен, 1 активен
    manifest TEXT NOT NULL DEFAULT '{}',         -- JSON манифеста (module.json)
    installed_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS module_settings (
    module_id TEXT PRIMARY KEY,                  -- не FK: переживает удаление модуля
    data TEXT NOT NULL DEFAULT '{}',             -- JSON настроек модуля
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
