-- Разные РАСКЛАДКИ портала (а не только цвета): стиль вывода тарифов, ширина
-- страницы, произвольный вводный блок. Плюс у тарифов — описание, список фич и
-- флаг «популярный» для выделения (как в операторских линейках тарифов).

ALTER TABLE portal_templates ADD COLUMN layout_width TEXT NOT NULL DEFAULT 'contained'; -- contained | full
ALTER TABLE portal_templates ADD COLUMN plan_style TEXT NOT NULL DEFAULT 'cards';       -- cards | table | rows
ALTER TABLE portal_templates ADD COLUMN intro_html TEXT;                                 -- блок над тарифами

ALTER TABLE billing_plans ADD COLUMN description TEXT;   -- короткое описание тарифа
ALTER TABLE billing_plans ADD COLUMN features TEXT;      -- список фич, по одной в строке
ALTER TABLE billing_plans ADD COLUMN featured INTEGER NOT NULL DEFAULT 0; -- выделить как «популярный»

-- Встроенные шаблоны делаем визуально разными, чтобы переключение было заметно.
UPDATE portal_templates SET plan_style = 'cards', layout_width = 'contained' WHERE name = 'Лайт';
UPDATE portal_templates SET plan_style = 'table', layout_width = 'contained' WHERE name = 'Миднайт';
UPDATE portal_templates SET plan_style = 'cards', layout_width = 'full'      WHERE name = 'Аврора';
