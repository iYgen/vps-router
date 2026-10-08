<?php

namespace App;

use App\Models\Setting;

/**
 * Уведомления администратора о доступности серверов (письмо на почту).
 * Вызывается из крон-проверки здоровья (bin/health_check_servers.php) при СМЕНЕ
 * статуса узла, поэтому писем не больше одного на событие (не спамит каждую минуту):
 *   online → offline/warning  → «сервер недоступен»;
 *   offline/warning → online  → «сервер снова в строю».
 *
 * Получатель: Setting 'alert_email' (если задан), иначе e-mail первого админа.
 * Включается Setting 'alerts_offline' (по умолчанию включено, если есть получатель).
 */
class ServerAlerts
{
    private const DOWN = ['offline', 'warning'];

    public static function enabled(): bool
    {
        return Setting::get('alerts_offline', '1') === '1';
    }

    /** Кому слать: переопределение в настройках или e-mail первого админа. */
    public static function recipient(): ?string
    {
        $cfg = trim((string) Setting::get('alert_email', ''));
        if ($cfg !== '' && filter_var($cfg, FILTER_VALIDATE_EMAIL)) {
            return $cfg;
        }
        try {
            $email = Database::get()->query(
                "SELECT email FROM users WHERE email IS NOT NULL AND email != '' ORDER BY id LIMIT 1"
            )->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }
        return ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) ? (string) $email : null;
    }

    /**
     * Реагирует на смену статуса узла. Шлёт письмо только на «фронте» события:
     * online→(offline|warning) и (offline|warning)→online. Прочие переходы
     * (например offline→warning) не дублируют тревогу.
     */
    public static function onStatusChange(array $server, string $old, string $new, string $detail = ''): void
    {
        if ($old === $new || !self::enabled()) {
            return;
        }
        $wasOnline = $old === 'online';
        $isDown = in_array($new, self::DOWN, true);
        $recovered = in_array($old, self::DOWN, true) && $new === 'online';

        // Тревога — только с явного online вниз (не шумим на unknown→offline при первом запуске).
        if (!($wasOnline && $isDown) && !$recovered) {
            return;
        }
        $to = self::recipient();
        if (!$to) {
            return;
        }

        $name = (string) ($server['name'] ?? ('#' . ($server['id'] ?? '?')));
        $host = (string) ($server['host'] ?? '');
        $when = gmdate('Y-m-d H:i') . ' UTC';

        if ($recovered) {
            $subject = "[VPS Router] Сервер снова доступен: {$name}";
            $body = "Узел «{$name}»" . ($host ? " ({$host})" : '') . " снова в сети (статус: online).\n"
                . "Время: {$when}\n";
        } else {
            $subject = "[VPS Router] Сервер НЕДОСТУПЕН: {$name} ({$new})";
            $body = "Узел «{$name}»" . ($host ? " ({$host})" : '') . " сменил статус online → {$new}.\n"
                . "Время: {$when}\n"
                . ($detail !== '' ? "Детали: {$detail}\n" : '')
                . "\nПроверьте сервер и панель управления хостинга.\n";
        }

        try {
            Mailer::send($to, $subject, $body);
            \App\Models\AuditLog::record('server.alert', "{$name}: {$old} -> {$new} -> {$to}");
        } catch (\Throwable $e) {
            error_log('ServerAlerts mail failed: ' . $e->getMessage());
        }
    }
}
