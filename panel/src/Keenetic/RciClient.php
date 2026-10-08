<?php

namespace App\Keenetic;

/**
 * Клиент Keenetic RCI API (документированный протокол аутентификации
 * KeeneticOS): GET /auth без сессии отвечает 401 с заголовками
 * X-NDM-Challenge/X-NDM-Realm; клиент считает
 * sha256(challenge . md5("login:realm:password")) и шлёт его паролем на
 * POST /auth; сервер возвращает сессионную cookie для последующих
 * запросов к /rci/*.
 *
 * ВАЖНО: этот протокол закодирован по опубликованной документации
 * Keenetic, а не проверен вживую на момент написания — при первом реальном
 * запуске против настоящего роутера, если формат ответа отличается,
 * поправить именно authenticate() по факту (см. docs/infrastructure-ui.md,
 * Verification в плане Keenetic-интеграции).
 */
class RciClient
{
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT = 15;

    private string $baseUrl;
    private ?string $cookie = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $scheme,
        private readonly string $username,
        private readonly string $password
    ) {
        $this->baseUrl = "{$this->scheme}://{$this->host}:{$this->port}";
    }

    public function authenticate(): void
    {
        $probe = $this->request('GET', '/auth', null, false);

        if ($probe['status'] === 200) {
            // Некоторые прошивки отдают 200 сразу, если сессия уже валидна
            // (например повторный вызов на том же соединении) — не считаем
            // это ошибкой.
            $this->cookie = $probe['cookie'] ?? $this->cookie;
            return;
        }

        if ($probe['status'] !== 401) {
            throw new \RuntimeException("Keenetic RCI: неожиданный ответ GET /auth — HTTP {$probe['status']} (ожидался 401 с challenge)");
        }

        $challenge = $probe['headers']['x-ndm-challenge'] ?? null;
        $realm = $probe['headers']['x-ndm-realm'] ?? null;
        if (!$challenge || !$realm) {
            throw new \RuntimeException('Keenetic RCI: в ответе 401 нет заголовков X-NDM-Challenge/X-NDM-Realm — протокол аутентификации на этой прошивке отличается от ожидаемого, нужна ручная проверка');
        }

        // Challenge привязан к сессии, выданной ИМЕННО этим 401-ответом
        // (Set-Cookie уже на GET /auth, до какого-либо логина) — без этой
        // cookie на POST сервер не может сопоставить наш ответ с challenge
        // и отвечает 400, даже если сам хэш посчитан верно.
        $this->cookie = $probe['cookie'];

        $part1 = md5("{$this->username}:{$realm}:{$this->password}");
        $token = hash('sha256', $challenge . $part1);

        $auth = $this->request('POST', '/auth', ['login' => $this->username, 'password' => $token], true);
        if ($auth['status'] !== 200) {
            throw new \RuntimeException("Keenetic RCI: аутентификация не удалась — HTTP {$auth['status']} (проверьте логин/пароль)");
        }
        // POST /auth не обязан выдавать НОВУЮ cookie — та же сессия,
        // выданная на GET /auth, просто помечается сервером как
        // аутентифицированная; используем новую, только если она пришла.
        if (!empty($auth['cookie'])) {
            $this->cookie = $auth['cookie'];
        }
    }

    public function get(string $path): array
    {
        return $this->call('GET', $path, null);
    }

    /**
     * ВАЖНО: RCI отвечает HTTP 200 даже когда сама команда отклонена —
     * ошибка приходит embedded в теле JSON (например
     * `{"ip":{"route":{"status":[{"status":"error","message":"..."}]}}}`),
     * а не HTTP-кодом. call() этого не видит (HTTP 2xx = "успех"), поэтому
     * post() отдельно ищет такие embedded-ошибки и бросает исключение —
     * без этой проверки отклонённая команда (см. инцидент 2026-09-23,
     * где домен-маршрут молча "успешно" не добавлялся) выглядела бы как
     * тихий успех.
     */
    public function post(string $path, array $body): array
    {
        $result = $this->call('POST', $path, $body);
        $errors = self::findEmbeddedErrors($result);
        if ($errors) {
            throw new \RuntimeException('Keenetic RCI: команда отклонена — ' . implode('; ', $errors));
        }
        return $result;
    }

    /** @return string[] */
    private static function findEmbeddedErrors(mixed $node): array
    {
        $errors = [];
        if (!is_array($node)) {
            return $errors;
        }
        if (($node['status'] ?? null) === 'error') {
            $errors[] = ($node['message'] ?? 'unknown error') . ' (code ' . ($node['code'] ?? '?') . ')';
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $errors = array_merge($errors, self::findEmbeddedErrors($value));
            }
        }
        return $errors;
    }

    private function call(string $method, string $path, ?array $body): array
    {
        if ($this->cookie === null) {
            $this->authenticate();
        }
        $result = $this->request($method, $path, $body, true);
        if ($result['status'] === 401) {
            // Сессия протухла — одна повторная попытка после re-auth.
            $this->cookie = null;
            $this->authenticate();
            $result = $this->request($method, $path, $body, true);
        }
        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw new \RuntimeException("Keenetic RCI: {$method} {$path} — HTTP {$result['status']}: " . ($result['raw'] ?? ''));
        }
        return $result['body'] ?? [];
    }

    /** @return array{status:int,headers:array<string,string>,body:?array,raw:?string,cookie:?string} */
    private function request(string $method, string $path, ?array $body, bool $withCookie): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers = ['Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($withCookie && $this->cookie !== null) {
            $headers[] = 'Cookie: ' . $this->cookie;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_POSTFIELDS => $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Keenetic RCI: не удалось подключиться к {$this->baseUrl} — $err");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $rawBody = substr($raw, $headerSize);

        $parsedHeaders = [];
        $cookie = null;
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_starts_with(strtolower($line), 'set-cookie:')) {
                $cookie = trim(substr($line, strlen('set-cookie:')));
                $cookie = explode(';', $cookie, 2)[0]; // только name=value, без атрибутов
                continue;
            }
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $parsedHeaders[strtolower(trim($name))] = trim($value);
            }
        }

        $decoded = null;
        if ($rawBody !== '' && str_contains($parsedHeaders['content-type'] ?? '', 'json')) {
            $decoded = json_decode($rawBody, true);
        }

        return ['status' => $status, 'headers' => $parsedHeaders, 'body' => $decoded, 'raw' => $rawBody, 'cookie' => $cookie];
    }
}
