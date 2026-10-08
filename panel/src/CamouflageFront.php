<?php

namespace App;

/**
 * Генерирует статический сайт-прикрытие для маскировки Reality. Reality на
 * exit-сервере отдаёт рукопожатие посторонних (сканеров Active Probing) этому
 * сайту — они видят обычный сайт с валидным сертификатом на домене, который
 * указывает на этот же сервер. Файлы отдаёт Caddy на 127.0.0.1:8443
 * (deploy/provision/exit-camouflage-front.sh); порт 443 остаётся за VLESS.
 */
class CamouflageFront
{
    /** @return array<string,string> id => человекочитаемое название пресета */
    public static function presets(): array
    {
        return [
            'updates' => 'Сервер обновлений ПО (English; страницы версий и релизов)',
            'status' => 'Статус-страница сервиса (мониторинг, аптайм)',
            'maintenance' => 'Технические работы (нейтральная заглушка)',
            'custom' => 'Своя HTML-страница (вставьте свой текст/HTML)',
        ];
    }

    /**
     * Файлы сайта под выбранный пресет.
     *
     * @param string $customHtml при пресете 'custom' — полный HTML-документ или
     *                           фрагмент (будет обёрнут в минимальный документ).
     * @return array<string,string> имя файла => содержимое
     */
    public static function files(string $preset, string $brand = 'Service', string $customHtml = ''): array
    {
        $brand = trim($brand) !== '' ? $brand : 'Service';
        return match ($preset) {
            'custom' => ['index.html' => self::customPage($customHtml, $brand)],
            'status' => ['index.html' => self::statusPage($brand)],
            'maintenance' => ['index.html' => self::maintenancePage($brand)],
            default => [
                'index.html' => self::updatesIndex($brand),
                'releases.html' => self::updatesReleases($brand),
                'status.html' => self::updatesStatus($brand),
            ],
        };
    }

    private static function head(string $title, string $lang = 'ru'): string
    {
        return '<!doctype html><html lang="' . htmlspecialchars($lang) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . htmlspecialchars($title) . '</title><style>'
            . ':root{--bg:#f5f6f8;--card:#fff;--text:#1c2430;--muted:#6b7684;--line:#e4e8ee;--accent:#2f6fed;--ok:#1aa251;--warn:#c98a00}'
            . '*{box-sizing:border-box}body{margin:0;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--text);line-height:1.55;font-size:15px}'
            . 'header{background:var(--card);border-bottom:1px solid var(--line)}.wrap{max-width:960px;margin:0 auto;padding:0 20px}'
            . '.top{display:flex;align-items:center;justify-content:space-between;height:60px}.brand{display:flex;align-items:center;gap:10px;font-weight:600}'
            . '.logo{width:30px;height:30px;border-radius:7px;background:linear-gradient(135deg,#2f6fed,#5b9cff);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700}'
            . 'nav a{color:var(--muted);text-decoration:none;margin-left:20px;font-size:14px}nav a:hover{color:var(--text)}'
            . 'h1{font-size:24px;margin:28px 0 6px}.sub{color:var(--muted);margin:0 0 24px}'
            . '.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px}'
            . '.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:18px 20px}.card h3{margin:0 0 10px;font-size:15px}'
            . '.status{display:flex;align-items:center;gap:8px;font-size:14px;margin:6px 0}.dot{width:9px;height:9px;border-radius:50%;background:var(--ok)}'
            . 'table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:9px 10px;border-bottom:1px solid var(--line)}'
            . 'th{color:var(--muted);font-weight:500;font-size:12.5px;text-transform:uppercase;letter-spacing:.03em}'
            . 'code{background:#eef1f6;padding:1px 6px;border-radius:4px;font-size:13px}.tag{display:inline-block;font-size:11.5px;padding:1px 8px;border-radius:20px;background:#e8f0fe;color:var(--accent)}'
            . '.tag.lts{background:#e6f6ec;color:var(--ok)}.tag.beta{background:#fff2e0;color:#c98a00}'
            . '.rel{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:18px 20px;margin-bottom:14px}.rel h2{font-size:17px;margin:0 0 2px}'
            . '.date{color:var(--muted);font-size:13px;margin:0 0 12px}ul{margin:6px 0 0;padding-left:20px}li{margin:3px 0;color:#3a4552}'
            . '.row{display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--line)}.row:last-child{border-bottom:none}'
            . '.up{color:var(--ok);font-size:13px}.metric{color:var(--muted);font-size:13px}'
            . 'footer{color:var(--muted);font-size:13px;padding:28px 0;border-top:1px solid var(--line);margin-top:24px}'
            . '.center{max-width:520px;margin:14vh auto;text-align:center;padding:0 20px}'
            . '@media(max-width:640px){.grid{grid-template-columns:1fr}}</style></head><body>';
    }

    private static function nav(string $brand, string $suffix, string $lang = 'ru'): string
    {
        $b = htmlspecialchars($brand);
        $l = htmlspecialchars(mb_substr($brand, 0, 1));
        $links = $lang === 'en'
            ? '<a href="/">Overview</a><a href="/releases.html">Releases</a><a href="/status.html">Status</a>'
            : '<a href="/">Обзор</a><a href="/releases.html">Релизы</a><a href="/status.html">Статус</a>';
        return '<header><div class="wrap top"><div class="brand"><span class="logo">' . $l . '</span> ' . $b . ' ' . $suffix . '</div>'
            . '<nav>' . $links . '</nav></div></header>';
    }

    // --- Пресет updates: англоязычный «сервер обновлений» (заграничный ресурс) ---

    private static function updatesIndex(string $brand): string
    {
        return self::head($brand . ' Update Service', 'en') . self::nav($brand, 'Update Service', 'en')
            . '<main class="wrap"><h1>Update Server</h1>'
            . '<p class="sub">Service node for client application update delivery. Region EU-North-1.</p>'
            . '<div class="grid"><div class="card"><h3>Service health</h3>'
            . '<div class="status"><span class="dot"></span> Update catalog — operational</div>'
            . '<div class="status"><span class="dot"></span> Version check (manifest) — operational</div>'
            . '<div class="status"><span class="dot"></span> Package mirror — operational</div>'
            . '<div class="status"><span class="dot"></span> Signature validation — operational</div></div>'
            . '<div class="card"><h3>Current channels</h3><table><tr><th>Channel</th><th>Version</th><th>Build</th></tr>'
            . '<tr><td>Stable</td><td>4.8.2</td><td>4820</td></tr><tr><td>LTS</td><td>4.6.11</td><td>4611</td></tr>'
            . '<tr><td>Beta</td><td>4.9.0-rc3</td><td>4903</td></tr></table></div></div>'
            . '<div class="card"><h3>How it works</h3><p style="margin:0;color:var(--muted)">The application periodically requests the current version manifest over a secure channel (TLS 1.3) and verifies checksums. Direct distribution downloads through the web interface are not provided — updates are delivered by the client&rsquo;s built-in mechanism.</p></div>'
            . '<p style="margin-top:24px"><a class="tag" href="/releases.html">Full release history &rarr;</a></p></main>'
            . '<footer><div class="wrap">&copy; 2019&ndash;2026 ' . htmlspecialchars($brand) . '. Service node.</div></footer></body></html>';
    }

    private static function updatesReleases(string $brand): string
    {
        $rel = function (string $ver, string $tag, string $tagClass, string $date, string $build, array $items): string {
            $li = implode('', array_map(fn($x) => '<li>' . htmlspecialchars($x) . '</li>', $items));
            return '<div class="rel"><h2>' . htmlspecialchars($ver) . ' <span class="tag ' . $tagClass . '">' . htmlspecialchars($tag) . '</span></h2>'
                . '<p class="date">' . htmlspecialchars($date) . ' &middot; build ' . htmlspecialchars($build) . '</p><ul>' . $li . '</ul></div>';
        };
        return self::head('Release history — ' . $brand, 'en') . self::nav($brand, 'Update Service', 'en')
            . '<main class="wrap"><h1>Release history</h1>'
            . $rel('4.8.2', 'Stable', '', 'September 18, 2026', '4820', ['Faster initial catalog sync on slow connections.', 'Fixed manifest re-download when the time zone changes.', 'Updated root certificates in the client trust store.'])
            . $rel('4.8.1', 'Stable', '', 'August 27, 2026', '4815', ['Fixed a memory leak in the background version-check service.', 'Added delta-update support for modules over 200 MB.', 'Fixed proxy settings handling on corporate networks.'])
            . $rel('4.6.11', 'LTS', 'lts', 'August 12, 2026', '4611', ['Scheduled security update for the long-term support branch.', 'Compatibility with automated deployment policies.'])
            . $rel('4.9.0-rc3', 'Beta', 'beta', 'September 5, 2026', '4903', ['New package integrity check based on Ed25519 signatures.', 'Experimental parallel module downloader.', 'Note: the Beta channel is not recommended for production use.'])
            . '</main><footer><div class="wrap">&copy; 2019&ndash;2026 ' . htmlspecialchars($brand) . '. Update delivery service node.</div></footer></body></html>';
    }

    private static function updatesStatus(string $brand): string
    {
        // Значения в span'ах — статический фолбэк (если JS выключен); скрипт ниже
        // детерминированно пересчитывает их от текущего времени (см. metricsScript).
        return self::head('Service status — ' . $brand, 'en') . self::nav($brand, 'Update Service', 'en')
            . '<main class="wrap"><h1>Service status</h1><p class="sub">Region EU-North-1 &middot; continuous monitoring</p>'
            . '<div class="card">'
            . '<div class="row"><div class="status"><span class="dot"></span> Version-check API</div><span class="up" id="u1">Operational &middot; 99.98%</span></div>'
            . '<div class="row"><div class="status"><span class="dot"></span> Manifest catalog</div><span class="up" id="u2">Operational &middot; 99.99%</span></div>'
            . '<div class="row"><div class="status"><span class="dot"></span> Package mirror</div><span class="up" id="u3">Operational &middot; 99.95%</span></div>'
            . '<div class="row"><div class="status"><span class="dot"></span> Signature service</div><span class="up" id="u4">Operational &middot; 100%</span></div></div>'
            . '<div class="card"><div class="row"><span class="metric">Average API response</span><span class="metric" id="ms">34 ms</span></div>'
            . '<div class="row"><span class="metric">Requests served in 24h</span><span class="metric" id="reqs">1,284,507</span></div>'
            . '<div class="row"><span class="metric">Scheduled maintenance</span><span class="metric">none scheduled</span></div></div>'
            . '</main><footer><div class="wrap">&copy; 2019&ndash;2026 ' . htmlspecialchars($brand) . '. Service node.</div></footer>'
            . self::metricsScript() . '</body></html>';
    }

    /**
     * Inline-JS: «живые» метрики статус-страницы. Чтобы статический сайт (Caddy,
     * без бэкенда) выглядел работающим, значения считаются детерминированно от
     * текущего времени UTC — при каждом заходе слегка меняются:
     *   • счётчик запросов за сутки растёт в течение дня (не с нуля);
     *   • аптайм служб колышется в пределах 99.90–99.99% (стабилен в течение суток);
     *   • средний отклик API 28–42 мс (меняется поминутно).
     * Детерминизм (хэш от дня/минуты) делает числа согласованными между соседними
     * загрузками, а не прыгающими случайно.
     */
    private static function metricsScript(): string
    {
        return '<script>(function(){'
            . 'function h(n){n=(n^0x9e3779b9)>>>0;n=Math.imul(n^(n>>>16),0x45d9f3b)>>>0;return (n^(n>>>16))>>>0;}'
            . 'var now=new Date();'
            . 'var sec=now.getUTCHours()*3600+now.getUTCMinutes()*60+now.getUTCSeconds();'
            . 'var frac=sec/86400;'
            . 'var day=Math.floor(Date.now()/86400000);'
            . 'var minute=now.getUTCHours()*60+now.getUTCMinutes();'
            . 'var daily=1180000+(h(day)%260000);'
            . 'var reqs=Math.floor(daily*(0.5+0.5*frac))+(h(day*1440+minute)%400);'
            . 'function up(s){return (99.90+(h(day+s)%10)/100).toFixed(2);}'
            . 'var ms=28+(h(minute)%15);'
            . 'function set(id,v){var e=document.getElementById(id);if(e)e.textContent=v;}'
            . 'set("u1","Operational · "+up(1)+"%");'
            . 'set("u2","Operational · "+up(2)+"%");'
            . 'set("u3","Operational · "+up(3)+"%");'
            . 'set("u4","Operational · 100%");'
            . 'set("ms",ms+" ms");'
            . 'set("reqs",reqs.toLocaleString("en-US"));'
            . '})();</script>';
    }

    private static function statusPage(string $brand): string
    {
        return self::head($brand . ' — System Status') . self::nav($brand, 'Status')
            . '<main class="wrap"><h1>Состояние систем</h1><p class="sub">Все системы работают в штатном режиме.</p>'
            . '<div class="card">'
            . '<div class="row"><div class="status"><span class="dot"></span> Веб-приложение</div><span class="up">Работает</span></div>'
            . '<div class="row"><div class="status"><span class="dot"></span> API</div><span class="up">Работает</span></div>'
            . '<div class="row"><div class="status"><span class="dot"></span> База данных</div><span class="up">Работает</span></div>'
            . '<div class="row"><div class="status"><span class="dot"></span> Хранилище</div><span class="up">Работает</span></div></div>'
            . '</main><footer><div class="wrap">&copy; 2019&ndash;2026 ' . htmlspecialchars($brand) . '.</div></footer></body></html>';
    }

    private static function maintenancePage(string $brand): string
    {
        return self::head($brand) . '<div class="center"><div class="logo" style="margin:0 auto 18px">'
            . htmlspecialchars(mb_substr($brand, 0, 1)) . '</div>'
            . '<h1>Идут технические работы</h1>'
            . '<p class="sub">Сервис временно недоступен для планового обслуживания. Пожалуйста, зайдите позже.</p>'
            . '</div></body></html>';
    }

    /**
     * Пресет 'custom' — HTML администратора. Полный документ (начинается с
     * <!doctype/<html>) отдаётся как есть; фрагмент оборачивается в минимальный
     * валидный документ. Пусто — нейтральная заглушка «техработы».
     * Контент статический (Caddy, без PHP), поэтому исполняемого кода тут нет.
     */
    private static function customPage(string $html, string $brand): string
    {
        $html = trim($html);
        if ($html === '') {
            return self::maintenancePage($brand);
        }
        if (preg_match('/^\s*<(!doctype|html)\b/i', $html)) {
            return $html;
        }
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . htmlspecialchars($brand) . '</title></head><body>'
            . $html . '</body></html>';
    }
}
