<?php

namespace App;

use App\Models\ExitServer;
use App\Models\NodeSetting;
use App\Models\Rule;
use App\Models\RuleGroup;
use App\Models\Server;
use App\Models\ServerSet;
use App\Models\Setting;
use App\Singbox\DnsBuilder;
use App\Singbox\ExitOutboundBuilder;
use App\Singbox\InboundsBuilder;
use App\Singbox\RouteBuilder;
use App\Singbox\RuleSetRegistry;

/**
 * Собирает /etc/sing-box/config.json и rule-set файлы из состояния БД.
 * Ничего не пишет на диск сама по себе за пределами предоставленных путей —
 * вызывающий код (Applier) отвечает за то, чтобы затем применить изменения через sudo-скрипт.
 *
 * Оркестратор: сама только читает настройки и собирает части через модули
 * App\Singbox\* (outbound'ы, маршруты, inbound'ы, DNS, реестр rule-set'ов).
 */
class SingboxConfigBuilder
{
    /** Общий список рекламных/трекинговых доменов SagerNet sing-geosite. */
    public const ADBLOCK_GEOSITE = 'category-ads-all';

    /**
     * Multi-router: which router node the current build reads settings for.
     * null = the self-router / global store (today's behaviour). Set only for
     * the duration of build()/validate() on a non-self router.
     */
    private static ?int $ctxRouter = null;

    /** Read a setting scoped to the router being built, else the global store. */
    private static function setget(string $key, ?string $default = null): ?string
    {
        if (self::$ctxRouter !== null) {
            return NodeSetting::get(self::$ctxRouter, $key, $default);
        }
        return Setting::get($key, $default);
    }

    /**
     * Куда Reality отдаёт рукопожатие посторонних (сканеров). По умолчанию —
     * сам SNI-домен на 443. Настройка reality_handshake ("хост:порт") нужна,
     * когда прикрытие — собственный сайт на этом же сервере через локальный
     * TLS 1.3-фронт (deploy/router/reality-front: 127.0.0.1:8443), потому что
     * nginx со старым OpenSSL сам TLS 1.3 не умеет.
     */
    public static function realityHandshake(string $serverName): array
    {
        $custom = trim((string) self::setget('reality_handshake', ''));
        if ($custom !== '' && preg_match('/^(\[[0-9a-fA-F:]+\]|[A-Za-z0-9.-]+):(\d{1,5})$/', $custom, $m)) {
            return ['server' => trim($m[1], '[]'), 'server_port' => (int) $m[2]];
        }
        return ['server' => $serverName, 'server_port' => 443];
    }

    public static function adblockEnabled(): bool
    {
        return self::setget('adblock_enabled', '0') === '1';
    }

    /** Блокировать QUIC (UDP 443) — по умолчанию да: убирает зависания YouTube через прокси. */
    public static function blockQuicEnabled(): bool
    {
        return self::setget('block_quic', '1') === '1';
    }

    /**
     * «Умный DNS»: sing-box перехватывает DNS-запросы клиентов (hijack-dns) и
     * домены, идущие через exit, резолвит DoH-запросом ЧЕРЕЗ сам exit. По
     * умолчанию включено. Реализация — App\Singbox\DnsBuilder.
     */
    public static function smartDnsEnabled(): bool
    {
        return self::setget('smart_dns', '1') === '1';
    }

    /** Локальный порт SOCKS/HTTP для загрузки списков через exit (см. listFetchEnabled). */
    public const LIST_FETCH_PORT = 11080;

    /**
     * «Загрузка списков через exit»: добавляет localhost-инбаунд mixed на
     * 127.0.0.1:LIST_FETCH_PORT, чей трафик уходит через первый exit — чтобы
     * панель могла скачивать заблокированные локально блок-листы (Antizapret и т.п.)
     * с внешней точки. По умолчанию выключено — конфиг не меняется, пока не включат.
     */
    public static function listFetchEnabled(): bool
    {
        return self::setget('list_fetch_via_exit', '0') === '1';
    }

    /**
     * Собирает конфиг для узла-роутера. По умолчанию — self (узел, где стоит
     * панель): поведение и результат идентичны прежним. Для не-self роутера
     * читает его устройства/маршруты/inbound-настройки (node_settings).
     */
    public function build(?int $routerServerId = null): array
    {
        $self = Server::self();
        $scopeId = $routerServerId ?? (isset($self['id']) ? (int) $self['id'] : null);
        $isSelf = $self !== null && $scopeId !== null && (int) $self['id'] === $scopeId;
        // self/неизвестный узел → глобальный server_settings (как раньше);
        // отдельный роутер → его node_settings.
        $diCtx = ($scopeId !== null && !$isSelf) ? $scopeId : null;
        // Строки с router_server_id IS NULL — это «наследие» self-роутера,
        // включаем их только когда собираем self/дефолтный узел.
        $includeNull = $isSelf || $routerServerId === null;

        return DeviceInbounds::withRouter($diCtx, function () use ($scopeId, $diCtx, $includeNull) {
            $prev = self::$ctxRouter;
            self::$ctxRouter = $diCtx;
            try {
                return $this->buildInner($scopeId, $includeNull);
            } finally {
                self::$ctxRouter = $prev;
            }
        });
    }

    private function buildInner(?int $scopeId, bool $includeNull): array
    {
        $listenIp = self::setget('reality_listen_ip');
        $listenPort = (int) self::setget('reality_listen_port', '443');
        $serverName = self::setget('reality_server_name');
        $privateKey = self::setget('reality_private_key');
        $shortId = self::setget('reality_short_id');

        $vlessEnabled = DeviceInbounds::isEnabled('vless');
        if ($vlessEnabled && (!$listenIp || !$serverName || !$privateKey || !$shortId)) {
            $missing = array_filter([
                !$listenIp ? 'IP, на котором sing-box слушает' : null,
                !$serverName ? 'Camouflage-домен (SNI)' : null,
                !$privateKey ? 'Reality private key' : null,
                !$shortId ? 'Reality short_id' : null,
            ]);
            throw new \RuntimeException(
                'Reality-инбаунд настроен не полностью. На странице «Настройки» не заполнено: ' . implode(', ', $missing) . '.'
            );
        }

        $groups = RuleGroup::all($scopeId, $includeNull);
        $exitServers = [];
        foreach (ExitServer::all() as $es) {
            $exitServers[$es['id']] = $es;
        }

        // Outbound'ы/endpoint'ы exit-серверов (по протоколу — см. ExitOutboundBuilder).
        $outbounds = [
            ['type' => 'direct', 'tag' => 'direct-rf'],
            ['type' => 'block', 'tag' => 'block'],
        ];
        $endpoints = [];
        foreach ($exitServers as $es) {
            [$outbound, $endpoint] = ExitOutboundBuilder::build($es);
            if ($outbound) {
                $outbounds[] = $outbound;
            }
            if ($endpoint) {
                $endpoints[] = $endpoint;
            }
        }

        // Маршруты из групп + данные для DNS. Реестр rule-set'ов общий для всех модулей.
        $registry = new RuleSetRegistry((string) App::config()['singbox_ruleset_dir']);
        $route = RouteBuilder::build($groups, $exitServers, $registry);
        $routeRules = $route['rules'];

        // Inbound'ы устройств (VLESS/Reality + остальные протоколы).
        $in = InboundsBuilder::build($scopeId, $includeNull, [
            'enabled' => $vlessEnabled,
            'listen_ip' => $listenIp,
            'listen_port' => $listenPort,
            'server_name' => $serverName,
            'private_key' => $privateKey,
            'short_id' => $shortId,
            'handshake' => self::realityHandshake((string) $serverName),
        ]);
        $inbounds = $in['inbounds'];
        $endpoints = array_merge($endpoints, $in['endpoints']);

        if (!$inbounds && !$endpoints) {
            throw new \RuntimeException(DeviceInbounds::enabled()
                ? 'VLESS выключен, а для остальных протоколов пока нет ни одного устройства — создайте устройство на странице «Устройства».'
                : 'Не включён ни один протокол подключения устройств (Настройки → «Протоколы подключения устройств»).');
        }

        // «Загрузка списков через exit»: localhost-SOCKS/HTTP -> первый exit.
        if (self::listFetchEnabled()) {
            $exitTags = [];
            foreach (array_merge($outbounds, $endpoints) as $o) {
                if (isset($o['tag']) && str_starts_with((string) $o['tag'], 'exit-')) {
                    $exitTags[] = $o['tag'];
                }
            }
            if ($exitTags) {
                $inbounds[] = [
                    'type' => 'mixed',
                    'tag' => 'list-fetch-in',
                    'listen' => '127.0.0.1',
                    'listen_port' => self::LIST_FETCH_PORT,
                ];
                $routeRules[] = ['inbound' => ['list-fetch-in'], 'outbound' => $exitTags[0]];
            }
        }

        // Блокировщик рекламы (Настройки → «Блокировка рекламы»): рекламные и
        // трекинговые домены отклоняются до всех остальных правил.
        if (self::adblockEnabled()) {
            $adTag = $registry->addGeosite(self::ADBLOCK_GEOSITE);
            array_unshift($routeRules, ['rule_set' => [$adTag], 'action' => 'reject']);
        }

        // Блокировка QUIC (HTTP/3, UDP 443). YouTube и Google сначала пытаются
        // качать по QUIC; через прокси-цепочку UDP подвисает, клиент ждёт
        // таймаут и откатывается на TCP — отсюда «видео замерло, потом пошло».
        // Отклоняем QUIC, чтобы сразу использовался стабильный TCP.
        if (self::blockQuicEnabled()) {
            array_unshift($routeRules, ['network' => 'udp', 'port' => [443], 'action' => 'reject']);
        }

        // «Умный DNS» (см. DnsBuilder): перехват DNS клиентов + резолв exit-доменов
        // через сам exit. Строится ПОСЛЕ adblock — чтобы его rule-set'ы легли в
        // реестр в том же порядке, что и раньше.
        $dns = null;
        $defaultDomainResolver = null;
        if (self::smartDnsEnabled()) {
            $built = DnsBuilder::build($route['dnsExitDomains'], $route['dnsExitGeosite'], $registry);
            $dns = $built['dns'];
            $defaultDomainResolver = $built['defaultDomainResolver'];
            array_unshift($routeRules, $built['hijackRule']);
        }

        // sniff — первым правилом: без него домен известен только у VLESS/Trojan
        // и т.п.; у WireGuard/AmneziaWG приходят голые IP, и доменные правила не
        // сработали бы. Sniff достаёт домен из TLS SNI/HTTP Host.
        array_unshift($routeRules, ['action' => 'sniff']);

        $config = [
            'log' => ['level' => 'info', 'timestamp' => true],
            'inbounds' => $inbounds,
            'outbounds' => $outbounds,
            'route' => [
                'rule_set' => $registry->defs(),
                'rules' => $routeRules,
                'final' => 'direct-rf',
            ],
        ];
        if ($dns !== null) {
            $config['dns'] = $dns;
            $config['route']['default_domain_resolver'] = $defaultDomainResolver;
        }
        if ($endpoints) {
            $config['endpoints'] = $endpoints;
        }
        // Clash API только на 127.0.0.1 — источник данных для учёта трафика устройств.
        $config['experimental'] = TrafficCollector::singboxExperimental();

        return [
            'config' => $config,
            'rule_sets' => $registry->localRuleSets(),
        ];
    }

    public static function exitOutboundTag(int $exitServerId): string
    {
        return ExitOutboundBuilder::tag($exitServerId);
    }

    /**
     * Структурные проверки перед Apply (раздел 38 ТЗ): пустые server sets,
     * routes без назначения, ссылки на удалённые сущности. Не проверяет
     * сетевую доступность — это делает health-check/test-connection отдельно.
     *
     * @return array<int,array{level:string,message:string}>
     */
    public function validate(?int $routerServerId = null): array
    {
        $self = Server::self();
        $scopeId = $routerServerId ?? (isset($self['id']) ? (int) $self['id'] : null);
        $isSelf = $self !== null && $scopeId !== null && (int) $self['id'] === $scopeId;
        $diCtx = ($scopeId !== null && !$isSelf) ? $scopeId : null;
        $includeNull = $isSelf || $routerServerId === null;

        return DeviceInbounds::withRouter($diCtx, function () use ($scopeId, $diCtx, $includeNull) {
            $prev = self::$ctxRouter;
            self::$ctxRouter = $diCtx;
            try {
                return $this->validateInner($scopeId, $includeNull);
            } finally {
                self::$ctxRouter = $prev;
            }
        });
    }

    private function validateInner(?int $scopeId, bool $includeNull): array
    {
        $issues = [];

        if (DeviceInbounds::isEnabled('vless') && (!self::setget('reality_listen_ip') || !self::setget('reality_private_key'))) {
            $issues[] = ['level' => 'error', 'message' => 'Reality-инбаунд не настроен (см. Настройки).'];
        }
        if (!DeviceInbounds::enabled()) {
            $issues[] = ['level' => 'error', 'message' => 'Не включён ни один протокол подключения устройств (см. Настройки).'];
        }

        $exitIds = array_column(ExitServer::all(), 'id');
        $setIds = array_column(ServerSet::all(), 'id');

        foreach (RuleGroup::all($scopeId, $includeNull) as $group) {
            if (!$group['enabled']) {
                continue;
            }
            $rules = Rule::forGroup((int) $group['id']);
            if (empty($rules)) {
                $issues[] = ['level' => 'warning', 'message' => "Route «{$group['name']}» включён, но не содержит правил."];
            }

            $resolvedExitId = null;
            if (!empty($group['server_set_id']) && !in_array((int) $group['server_set_id'], $setIds, true)) {
                $issues[] = ['level' => 'error', 'message' => "Route «{$group['name']}» ссылается на удалённый server set."];
            } elseif (!empty($group['server_set_id'])) {
                $resolvedExitId = ServerSet::resolveExitServerId((int) $group['server_set_id']);
                if ($resolvedExitId === null) {
                    $issues[] = ['level' => 'warning', 'message' => "Server set «{$group['server_set_name']}» не содержит доступных серверов — route «{$group['name']}» пойдёт напрямую (вход)."];
                }
            } elseif (!empty($group['exit_server_id'])) {
                if (!in_array((int) $group['exit_server_id'], $exitIds, true)) {
                    $issues[] = ['level' => 'error', 'message' => "Route «{$group['name']}» ссылается на удалённый exit-сервер."];
                } else {
                    $resolvedExitId = (int) $group['exit_server_id'];
                }
            }

            if ($resolvedExitId !== null) {
                $es = ExitServer::find($resolvedExitId);
                $incomplete = $es ? ExitOutboundBuilder::incompleteness($es) : null;
                if ($incomplete) {
                    $issues[] = ['level' => 'error', 'message' => "Route «{$group['name']}» ведёт на «{$es['name']}» — $incomplete Нажмите «Установить и настроить» в карточке связи."];
                }
            }
        }

        foreach (ServerSet::all() as $set) {
            if ($set['enabled'] && empty($set['members'])) {
                $issues[] = ['level' => 'warning', 'message' => "Server set «{$set['name']}» включён, но пуст."];
            }
        }

        return $issues;
    }
}
