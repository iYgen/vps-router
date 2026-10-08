<?php

namespace App;

/** Мелкие хелперы для api/*.php — без роутера, каждый файл сам себе endpoint. */
class Http
{
    /** Режим тестов: json()/error() бросают HttpResponse вместо echo+exit. */
    public static bool $capture = false;
    /** Тело запроса для jsonInput() в режиме тестов (php://input недоступен в CLI). */
    public static ?string $testBody = null;

    public static function jsonInput(): array
    {
        $raw = (self::$capture && self::$testBody !== null) ? self::$testBody : file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            self::error('Некорректный JSON в теле запроса', 400);
        }
        return $data;
    }

    public static function json($data, int $status = 200): void
    {
        if (self::$capture) {
            throw new HttpResponse($status, is_array($data) ? $data : ['data' => $data]);
        }
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(string $message, int $status = 400): void
    {
        self::json(['error' => $message], $status);
    }

    /** Стандартный пролог для api/*.php: сессия + CSRF (кроме GET) + метод. */
    public static function guard(array $allowedMethods): string
    {
        Auth::requireLoginJson();
        self::logIfSuspicious();
        $method = $_SERVER['REQUEST_METHOD'];
        if (!in_array($method, $allowedMethods, true)) {
            self::error('Method not allowed', 405);
        }
        if ($method !== 'GET') {
            Auth::requireValidCsrfJson();
        }
        return $method;
    }

    /**
     * Небольшой фиксированный список характерных сигнатур инъекций/XSS —
     * ТОЛЬКО логирование (category=block в audit_log), запрос не
     * отклоняется. SQL и так параметризован через PDO везде, вывод
     * экранирован — реальной защиты этот список не добавляет, только
     * видимость: "кто-то пробовал". Авто-блокировка по совпадению паттерна
     * сознательно не делается — риск ложно заблокировать легитимный запрос
     * (например домен/название маршрута со спецсимволами) выше пользы,
     * когда экранирование и параметризация уже закрывают реальную угрозу.
     */
    private const SUSPICIOUS_PATTERNS = [
        "' or '1'='1", 'union select', '<script', '../../../', '; drop table',
        '<?php', 'javascript:', 'onerror=', 'onload=', '${jndi:',
    ];

    private static function logIfSuspicious(): void
    {
        $haystack = strtolower($_SERVER['REQUEST_URI'] ?? '');
        foreach ($_GET as $v) {
            $haystack .= ' ' . strtolower((string) $v);
        }
        $body = @file_get_contents('php://input');
        if ($body) {
            $haystack .= ' ' . strtolower($body);
        }

        foreach (self::SUSPICIOUS_PATTERNS as $pattern) {
            if (str_contains($haystack, $pattern)) {
                \App\Models\AuditLog::record(
                    'suspicious_request',
                    ($_SERVER['REQUEST_URI'] ?? '') . " — совпал паттерн «{$pattern}»",
                    'block'
                );
                return; // одного совпадения достаточно, не спамим лог за один запрос
            }
        }
    }
}
