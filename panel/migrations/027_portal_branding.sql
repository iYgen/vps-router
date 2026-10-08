-- Оформление портала (шаблоны/цвета/лого/хедер-футер), бесплатные тарифы,
-- подтверждение почты и настраиваемые письма.

-- Подтверждение email подписчика.
ALTER TABLE billing_subscribers ADD COLUMN email_verified INTEGER NOT NULL DEFAULT 0;
ALTER TABLE billing_subscribers ADD COLUMN verify_token TEXT;

-- Шаблоны оформления портала. Встроенные (is_builtin=1) нельзя удалить, но можно
-- редактировать; свои — создавать/править/удалять. Активный выбирается настройкой
-- portal_active_template_id. Палитра — набор CSS-переменных; dark=1 → color-scheme dark.
CREATE TABLE IF NOT EXISTS portal_templates (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,
    is_builtin  INTEGER NOT NULL DEFAULT 0,
    dark        INTEGER NOT NULL DEFAULT 1,
    accent      TEXT NOT NULL DEFAULT '#ff4f87',
    bg          TEXT NOT NULL DEFAULT '#17171a',
    surface     TEXT NOT NULL DEFAULT '#1d1d22',
    surface2    TEXT NOT NULL DEFAULT '#222228',
    text        TEXT NOT NULL DEFAULT '#f5f5f7',
    muted       TEXT NOT NULL DEFAULT '#a1a1aa',
    border      TEXT NOT NULL DEFAULT 'rgba(255,255,255,.08)',
    radius      TEXT NOT NULL DEFAULT '14px',
    custom_css  TEXT,
    header_html TEXT,
    footer_html TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Встроенные шаблоны: «Лайт» (светлый, по умолчанию), «Миднайт» (тёмный),
-- «Аврора» (тёмный с градиентом).
INSERT INTO portal_templates (name, is_builtin, dark, accent, bg, surface, surface2, text, muted, border, radius, custom_css) VALUES
 ('Лайт', 1, 0, '#2563eb', '#f5f6f8', '#ffffff', '#eef1f6', '#141418', '#6b7280', 'rgba(0,0,0,.10)', '14px', NULL),
 ('Миднайт', 1, 1, '#ff4f87', '#17171a', '#1d1d22', '#222228', '#f5f5f7', '#a1a1aa', 'rgba(255,255,255,.08)', '14px', NULL),
 ('Аврора', 1, 1, '#7c5cff', '#0f1020', '#191a2e', '#20223a', '#eef0ff', '#9aa0c0', 'rgba(255,255,255,.10)', '16px',
  'body{background:radial-gradient(1200px 600px at 20% -10%, color-mix(in srgb,var(--accent) 22%,transparent), transparent), var(--bg)}');
