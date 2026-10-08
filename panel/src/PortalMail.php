<?php

namespace App;

use App\Models\Setting;

/**
 * Письма клиентского портала с настраиваемым текстом (Биллинг → Письма).
 * Плейсхолдеры: {brand} {name} {link} {date} {plan} {days}. Тексты берутся из
 * настроек portal_mail_<key>_subject/_body, иначе — дефолты ниже.
 */
class PortalMail
{
    private const DEFAULTS = [
        'verify' => [
            'subject' => 'Подтвердите email — {brand}',
            'body'    => "Здравствуйте, {name}!\n\nПодтвердите адрес почты, перейдя по ссылке:\n{link}\n\nЕсли вы не регистрировались — просто проигнорируйте письмо.",
        ],
        'reminder' => [
            'subject' => 'Подписка истекает {date} — {brand}',
            'body'    => "Здравствуйте, {name}!\n\nВаша подписка «{plan}» истекает {date}.\nПродлите её, чтобы доступ не отключился:\n{link}",
        ],
        'welcome' => [
            'subject' => 'Добро пожаловать — {brand}',
            'body'    => "Здравствуйте, {name}!\n\nВаш аккаунт создан. Личный кабинет: {link}",
        ],
        'reset' => [
            'subject' => 'Восстановление пароля — {brand}',
            'body'    => "Здравствуйте, {name}!\n\nДля смены пароля перейдите по ссылке (действует 1 час):\n{link}\n\nЕсли вы не запрашивали сброс — проигнорируйте письмо.",
        ],
    ];

    public static function subject(string $key): string
    {
        return (string) Setting::get("portal_mail_{$key}_subject", self::DEFAULTS[$key]['subject'] ?? '');
    }

    public static function body(string $key): string
    {
        return (string) Setting::get("portal_mail_{$key}_body", self::DEFAULTS[$key]['body'] ?? '');
    }

    public static function default(string $key, string $part): string
    {
        return self::DEFAULTS[$key][$part] ?? '';
    }

    /** @param array<string,string> $vars */
    private static function fill(string $tpl, array $vars): string
    {
        $vars['{brand}'] = $vars['{brand}'] ?? (string) Setting::get('portal_brand', 'VPN');
        return strtr($tpl, $vars);
    }

    /** Отправить письмо по шаблону $key с подстановками $vars. */
    public static function send(string $to, string $key, array $vars): bool
    {
        if ($to === '') {
            return false;
        }
        return Mailer::send($to, self::fill(self::subject($key), $vars), self::fill(self::body($key), $vars));
    }

    /** Базовый URL портала (для ссылок в письмах). */
    public static function portalBase(): string
    {
        $host = (string) Setting::get('panel_public_host', '');
        if ($host === '') {
            $host = (string) Setting::get('reality_public_host', '') ?: 'localhost';
        }
        return 'https://' . $host;
    }
}
