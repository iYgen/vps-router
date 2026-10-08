-- Конструктор сайта и личного кабинета. Разделение business/design data:
-- бизнес-данные (тарифы/подписки/платежи) остаются в своих таблицах, а ДИЗАЙН
-- (структурный шаблон + палитра/типографика/секции/контент) хранится здесь как
-- версионируемый JSON. Один dataset бизнес-данных — много presentation-дизайнов.

CREATE TABLE IF NOT EXISTS site_designs (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT NOT NULL,
    template     TEXT NOT NULL DEFAULT 'modern-saas', -- ключ структурного шаблона (TemplateRegistry)
    config_json  TEXT NOT NULL DEFAULT '{}',          -- design data (палитра/типографика/hero/pricing/sections/cabinet/…)
    status       TEXT NOT NULL DEFAULT 'draft',        -- draft | published | archived
    version      INTEGER NOT NULL DEFAULT 1,
    based_on_id  INTEGER,                              -- для duplicate/версий
    author       TEXT,
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    published_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_site_designs_status ON site_designs(status);

-- Дизайн по умолчанию (Modern SaaS), сразу опубликован — чтобы /site.php работал
-- из коробки. Пустой config → renderer берёт дефолты шаблона из реестра.
INSERT INTO site_designs (name, template, config_json, status, version, author, published_at)
SELECT 'Default', 'modern-saas', '{}', 'published', 1, 'system', datetime('now')
WHERE NOT EXISTS (SELECT 1 FROM site_designs);
