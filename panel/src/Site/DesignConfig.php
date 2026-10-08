<?php

namespace App\Site;

/**
 * Design data конкретного дизайна: структурный шаблон + палитра/типографика/
 * hero/pricing/секции/контакты/footer/cabinet. Сохранённый config накладывается
 * поверх дефолтов шаблона (TemplateRegistry). Бизнес-данных не содержит.
 */
class DesignConfig
{
    private array $data;

    public function __construct(string $template, array $saved)
    {
        $this->data = self::merge(TemplateRegistry::defaults($template), $saved);
    }

    /** Глубокое слияние (ассоц. массивы объединяются, остальное перезаписывается). */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && self::isAssoc($v) && self::isAssoc($base[$k])) {
                $base[$k] = self::merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    private static function isAssoc(array $a): bool
    {
        return $a !== [] && array_keys($a) !== range(0, count($a) - 1);
    }

    /** Доступ по точечному пути: get('palette.primary', '#000'). */
    public function get(string $path, $default = null)
    {
        $cur = $this->data;
        foreach (explode('.', $path) as $seg) {
            if (!is_array($cur) || !array_key_exists($seg, $cur)) {
                return $default;
            }
            $cur = $cur[$seg];
        }
        return $cur;
    }

    public function palette(): array { return (array) ($this->data['palette'] ?? []); }
    public function typography(): array { return (array) ($this->data['typography'] ?? []); }
    public function hero(): array { return (array) ($this->data['hero'] ?? []); }
    public function pricing(): array { return (array) ($this->data['pricing'] ?? []); }
    public function contacts(): array { return (array) ($this->data['contacts'] ?? []); }
    public function faq(): array { return (array) ($this->data['faq'] ?? []); }
    public function features(): array { return (array) ($this->data['features'] ?? []); }
    public function footer(): array { return (array) ($this->data['footer'] ?? []); }
    public function cabinet(): array { return (array) ($this->data['cabinet'] ?? []); }

    /** Порядок секций (из шаблона, с возможным override). */
    public function sections(): array
    {
        $s = $this->data['sections'] ?? [];
        return is_array($s) && $s ? array_values($s) : ['hero', 'pricing', 'footer'];
    }

    public function brand(): array { return (array) ($this->data['brand'] ?? []); }
    public function advancedCss(): string { return (string) ($this->data['advanced_css'] ?? ''); }
    public function all(): array { return $this->data; }
}
