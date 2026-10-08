<?php

namespace App;

/**
 * Проверяет, выглядит ли домен заблокированным локально — с той же входной VPS,
 * на которой крутится панель (реальный российский vantage point, в
 * отличие от App\RiskScanner, который проверяет НАШ СОБСТВЕННЫЙ сервер
 * СНАРУЖИ). Помогает решить, действительно ли маршрут нуждается в
 * проксировании, или домен и так доступен напрямую.
 *
 * Портирована ключевая идея статьи "Когда антибот притворяется"
 * (rkn-block-checker, MIT, https://github.com/MayersScott/rkn-block-checker) —
 * не сам Python-инструмент (чужой рантайм не годится для PHP-монолита без
 * build-шага), а именно эвристика: наивный поиск маркеров "доступ
 * ограничен"/"решению роскомнадзора" и т.п. в теле ответа даёт ложные
 * срабатывания на антибот-защиту целевого сайта (Cloudflare/WAF отвечают
 * 429/403 с похожим текстом про "временно ограничен доступ"). Проверенных
 * заглушек провайдеров локально отличает то, что HTTP 429/403 — это ответ
 * ЦЕЛЕВОГО сервера, а не перехват на магистрали: настоящая заглушка почти
 * всегда либо HTTP 451, либо статус 200 с подменённым телом.
 */
class BlockChecker
{
    private const CONNECT_TIMEOUT = 5;
    private const MAX_DOMAINS_PER_REQUEST = 15;

    private const STUB_MARKERS = [
        'доступ ограничен', 'решению роскомнадзора', 'по решению суда',
        'единый реестр', 'ограничен на территории', 'заблокирован в соответствии',
        'информация ограничена', 'роскомнадзор',
    ];

    /** Коды, которые сам целевой сайт (антибот/rate-limit/WAF) отдаёт независимо от блокировок магистрали. */
    private const ANTIBOT_STATUSES = [403, 429];

    /** @return array<int,array{domain:string,verdict:string,confidence:string,note:string}> */
    public static function checkDomains(array $domains): array
    {
        $domains = array_slice(array_values(array_unique(array_filter($domains))), 0, self::MAX_DOMAINS_PER_REQUEST);
        return array_map(fn (string $d) => self::checkDomain($d), $domains);
    }

    /** @return array{domain:string,verdict:string,confidence:string,note:string} verdict: ok|blocked|down */
    public static function checkDomain(string $domain): array
    {
        $domain = preg_replace('#^https?://#', '', trim($domain));
        $domain = explode('/', $domain)[0];

        $context = stream_context_create([
            'http' => [
                'timeout' => self::CONNECT_TIMEOUT,
                'ignore_errors' => true,
                'header' => "User-Agent: Mozilla/5.0 (compatible; vps-router-block-checker/1.0)\r\n",
                'follow_location' => 1,
                'max_redirects' => 3,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        $body = @file_get_contents("https://$domain/", false, $context);
        if ($body === false) {
            return ['domain' => $domain, 'verdict' => 'down', 'confidence' => 'medium', 'note' => 'соединение не установлено (timeout/reset) — похоже на блокировку на уровне соединения (аналог Active Blocking System из статьи про Active Probing)'];
        }

        $status = self::statusFromResponseHeaders($http_response_header ?? []);
        $looksLikeStub = self::looksLikeStub($body);

        if ($looksLikeStub && in_array($status, self::ANTIBOT_STATUSES, true)) {
            return ['domain' => $domain, 'verdict' => 'ok', 'confidence' => 'low', 'note' => "HTTP $status похож на антибот-защиту самого сайта (rate limit/WAF), а не на блокировку — текст совпал случайно, см. известный false positive rkn-block-checker"];
        }
        if ($looksLikeStub) {
            return ['domain' => $domain, 'verdict' => 'blocked', 'confidence' => 'high', 'note' => "тело ответа (HTTP $status) похоже на страницу-заглушку провайдера"];
        }
        if ($status === 451) {
            return ['domain' => $domain, 'verdict' => 'blocked', 'confidence' => 'high', 'note' => 'HTTP 451 Unavailable For Legal Reasons'];
        }

        return ['domain' => $domain, 'verdict' => 'ok', 'confidence' => 'medium', 'note' => "сайт отвечает без признаков блокировки (HTTP $status)"];
    }

    /** public — узкая чистая логика, тестируется без реальной сети (см. tests/BlockCheckerTest.php). */
    public static function looksLikeStub(string $body): bool
    {
        $lower = mb_strtolower($body);
        foreach (self::STUB_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }
        return false;
    }

    /** public — см. looksLikeStub(). */
    public static function statusFromResponseHeaders(array $headers): int
    {
        $status = 0;
        foreach ($headers as $header) {
            // Не return на первом совпадении — при редиректах в массиве
            // несколько строк статуса подряд, нужна ПОСЛЕДНЯЯ (финальная).
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }
        return $status;
    }
}
