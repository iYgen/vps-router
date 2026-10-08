<?php

namespace App;

use App\Models\ConfigVersion;
use App\Singbox\ConfigValidator;

/**
 * Записывает сгенерированные конфиги на диск и просит привилегированный
 * скрипт (через узкую sudo-запись) применить их. PHP-FPM (www-data) никогда
 * не получает root напрямую — единственная разрешённая команда прописана
 * в /etc/sudoers.d/panel-apply и не принимает аргументов от вызывающего.
 */
class Applier
{
    /** Собирает конфиг, не записывая ничего на диск — для экрана Preview/diff. */
    public function preview(?int $routerServerId = null): array
    {
        $built = (new SingboxConfigBuilder())->build($routerServerId);
        $issues = (new SingboxConfigBuilder())->validate($routerServerId);
        // Пред-валидация собранного конфига (висячие ссылки) — показываем в Preview.
        foreach (ConfigValidator::validate($built['config'], $built['rule_sets']) as $msg) {
            $issues[] = ['level' => 'error', 'message' => $msg];
        }
        // Совместимость с установленной версией sing-box (после обновления/отката движка).
        foreach (ConfigValidator::versionWarnings($built['config'], \App\SingboxVersion::installed()) as $msg) {
            $issues[] = ['level' => 'warning', 'message' => $msg];
        }
        // AmneziaWG: параметры обфускации exit'ов + поддержка на самом узле.
        $hasAwg = false;
        foreach (Models\ExitServer::all() as $es) {
            if (($es['protocol'] ?? 'amneziawg') !== 'amneziawg' || ($es['status'] ?? '') === 'disabled') {
                continue;
            }
            $hasAwg = true;
            $params = !empty($es['amnezia_params']) ? (json_decode((string) $es['amnezia_params'], true) ?: []) : [];
            foreach (\App\Amnezia\AwgParams::validate($params) as $m) {
                $issues[] = ['level' => 'error', 'message' => "AmneziaWG «{$es['name']}»: $m"];
            }
        }
        // Проверка окружения — только для self-узла (у удалённого роутера своё).
        $self = Models\Server::self();
        $isSelf = $routerServerId === null || ($self !== null && (int) $self['id'] === $routerServerId);
        if ($hasAwg && $isSelf && !\App\Amnezia\AwgKernel::usable()) {
            $issues[] = ['level' => 'warning', 'message' => 'На этом узле не обнаружены инструменты/модуль AmneziaWG (awg-quick + kernel-модуль или amneziawg-go) — туннели к AmneziaWG-exit могут не подняться. Установите amneziawg-tools.'];
        }
        // IPv6-утечка: у узла есть публичный IPv6, а exit'ы обычно IPv4-only.
        if ($isSelf) {
            $v6 = \App\LocalSystem::globalIpv6();
            if ($v6 !== null) {
                $issues[] = ['level' => 'warning', 'message' => "У сервера есть публичный IPv6 ($v6). Если устройства ходят по IPv6, часть трафика может идти мимо туннеля (exit'ы обычно только IPv4). Отключите IPv6 на устройствах/роутере или используйте exit с поддержкой IPv6. Умный DNS уже отдаёт prefer_ipv4, а QUIC блокируется — но это не гарантия."];
            }
        }

        $previous = ConfigVersion::latest();
        $previousConfig = $previous ? json_decode($previous['singbox_config'], true) : null;

        return [
            'config' => $built['config'],
            'issues' => $issues,
            'diff' => $this->diff($previousConfig, $built['config']),
        ];
    }

    /**
     * Применяет конфиг узла-роутера. По умолчанию — self (узел, где стоит
     * панель): пишет файлы локально и запускает привилегированный apply-скрипт
     * через sudo (как раньше). Для не-self роутера собирает его конфиг и
     * пушит по SSH, запуская там router-apply.sh.
     */
    public function apply(string $username = 'system', string $description = '', ?int $routerServerId = null): array
    {
        $self = Models\Server::self();
        $isSelf = $routerServerId === null
            || ($self !== null && (int) $self['id'] === $routerServerId);

        if ($isSelf) {
            return $this->applyLocal($username, $description);
        }
        return $this->applyRemote($routerServerId, $username, $description);
    }

    private function applyLocal(string $username, string $description): array
    {
        $config = App::config();

        $built = (new SingboxConfigBuilder())->build();
        // Пред-валидация ДО записи и перезапуска: не роняем рабочий sing-box в битый конфиг.
        $errors = ConfigValidator::validate($built['config'], $built['rule_sets']);
        if ($errors) {
            throw new \RuntimeException(
                "Конфигурация не прошла проверку целостности — применение отменено:\n- " . implode("\n- ", $errors)
            );
        }
        $this->writeJson($config['singbox_config_path'], $built['config']);

        $rulesetDir = rtrim($config['singbox_ruleset_dir'], '/');
        $this->ensureDir($rulesetDir);
        $this->pruneDir($rulesetDir, array_keys($built['rule_sets']));
        foreach ($built['rule_sets'] as $tag => $content) {
            $this->writeJson("$rulesetDir/$tag.json", $content);
        }

        $amneziaDir = rtrim($config['amnezia_conf_dir'], '/');
        $this->ensureDir($amneziaDir);
        $wgConfigs = (new AmneziaConfigBuilder())->buildAll();
        // Вход устройств по AmneziaWG — серверный интерфейс awg-in рядом с
        // туннелями к exit-серверам; поднимает/перезапускает его тот же apply-router.sh.
        $awgInbound = DeviceInbounds::amneziaServerConfig(Models\Client::active());
        if ($awgInbound !== null) {
            $wgConfigs[DeviceInbounds::AWG_INTERFACE] = $awgInbound;
        }
        foreach ($wgConfigs as $iface => $content) {
            $this->writeFile("$amneziaDir/$iface.conf", $content);
            chmod("$amneziaDir/$iface.conf", 0600);
        }
        $this->writeFile($config['amnezia_interfaces_list'], implode("\n", array_keys($wgConfigs)) . "\n");
        $this->writeFile("$amneziaDir/awg-inbound.env", DeviceInbounds::amneziaInboundEnv($awgInbound !== null));

        // Порты включённых протоколов — apply-router.sh откроет их в ufw/firewalld, если те активны.
        $this->writeFile(dirname($config['singbox_config_path']) . '/open-ports.list', implode("\n", DeviceInbounds::openPorts()) . "\n");

        $result = $this->runApplyScript($config['apply_script']);
        ConfigVersion::record($username, $description, $built['config'], $result);
        if (($result['exit_code'] ?? 1) === 0) {
            Models\Setting::set('applied_fingerprint', self::fingerprint($built));
        }

        return $result;
    }

    /**
     * Собирает JSON-параметры для router-apply.sh на удалённом роутере: сам
     * конфиг sing-box, локальные rule-set'ы, awg-quick конфиги (вход устройств
     * по AmneziaWG — node-scoped; туннели к exit пока используют общие ключи
     * exit_servers — см. Phase 3b), список интерфейсов, env входа и порты.
     *
     * @return array{params: array, built: array}
     */
    public function buildRemoteParams(int $routerServerId): array
    {
        $built = (new SingboxConfigBuilder())->build($routerServerId);
        // Пред-валидация до пуша на удалённый роутер — не отправляем битый конфиг.
        $errors = ConfigValidator::validate($built['config'], $built['rule_sets']);
        if ($errors) {
            throw new \RuntimeException(
                "Конфигурация роутера не прошла проверку целостности — применение отменено:\n- " . implode("\n- ", $errors)
            );
        }

        return DeviceInbounds::withRouter($routerServerId, function () use ($routerServerId, $built) {
            $wgConfigs = (new AmneziaConfigBuilder())->buildAll();
            $awgInbound = DeviceInbounds::amneziaServerConfig(Models\Client::active($routerServerId));
            if ($awgInbound !== null) {
                $wgConfigs[DeviceInbounds::AWG_INTERFACE] = $awgInbound;
            }

            $params = [
                'singbox_config' => $built['config'],
                'rule_sets' => (object) $built['rule_sets'],
                'amnezia_confs' => (object) $wgConfigs,
                'interfaces_list' => implode("\n", array_keys($wgConfigs)) . (empty($wgConfigs) ? '' : "\n"),
                'awg_inbound_env' => DeviceInbounds::amneziaInboundEnv($awgInbound !== null),
                'open_ports' => implode("\n", DeviceInbounds::openPorts()) . "\n",
            ];

            return ['params' => $params, 'built' => $built];
        });
    }

    /** Пушит конфиг роутера по SSH и запускает router-apply.sh на удалённом узле. */
    private function applyRemote(int $routerServerId, string $username, string $description): array
    {
        $server = Models\Server::find($routerServerId);
        if (!$server) {
            throw new \InvalidArgumentException('Роутер не найден');
        }
        $privateKey = Models\Server::sshPrivateKey($routerServerId);
        if (!$privateKey) {
            throw new \InvalidArgumentException('Для роутера «' . $server['name'] . '» не сохранён SSH-приватный ключ — добавьте его в карточке сервера');
        }

        ['params' => $params, 'built' => $built] = $this->buildRemoteParams($routerServerId);

        Provisioner::knockIfConfigured($server);

        $scriptsDir = App::config()['provision_scripts_dir'] ?? (__DIR__ . '/../deploy/provision');
        $result = Ssh::runProvisionScript(
            $server['host'],
            (int) $server['ssh_port'],
            $server['ssh_user'],
            $privateKey,
            rtrim($scriptsDir, '/') . '/router-apply.sh',
            $params
        );

        // Нормализуем к формату applyLocal (exit_code/stdout/stderr).
        $normalized = [
            'exit_code' => $result['exit_code'] ?? ($result['ok'] ? 0 : 1),
            'stdout' => $result['stdout'] ?? '',
            'stderr' => trim(($result['stderr'] ?? '') . "\n" . ($result['raw_error'] ?? '')),
        ];
        ConfigVersion::record($username, $description, $built['config'], $normalized);
        if (($normalized['exit_code'] ?? 1) === 0) {
            Models\NodeSetting::set($routerServerId, 'applied_fingerprint', self::fingerprint($built));
        }

        return $normalized;
    }

    /**
     * Отпечаток того, что панель СЕЙЧАС записала бы в sing-box (конфиг +
     * rule-set'ы). Совпадает с сохранённым после последнего успешного Apply —
     * значит, всё применено; не совпадает — в БД есть изменения (маршруты,
     * наборы, устройства, настройки), которые до sing-box ещё не дошли.
     */
    public static function fingerprint(array $built): string
    {
        return hash('sha256', json_encode($built['config'], JSON_UNESCAPED_SLASHES) . json_encode($built['rule_sets'], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param int|null $routerServerId роутер, для которого проверяем «не применено».
     *                 null/self → глобальный applied_fingerprint (как раньше),
     *                 не-self → per-node (NodeSetting).
     * @return array{pending:bool,error:?string}
     */
    public static function pendingState(?int $routerServerId = null): array
    {
        $self = Models\Server::self();
        $isSelf = $routerServerId === null || ($self !== null && (int) $self['id'] === $routerServerId);
        try {
            $built = (new SingboxConfigBuilder())->build($routerServerId);
        } catch (\Throwable $e) {
            return ['pending' => true, 'error' => $e->getMessage()];
        }
        $stored = $isSelf
            ? Models\Setting::get('applied_fingerprint')
            : Models\NodeSetting::get($routerServerId, 'applied_fingerprint');
        return ['pending' => $stored !== self::fingerprint($built), 'error' => null];
    }

    /**
     * Откатывает на ранее применённый конфиг: записывает исторический
     * config.json обратно на диск и запускает тот же (без изменений)
     * привилегированный apply-скрипт — новых прав не требуется, т.к.
     * скрипт всегда просто перечитывает файлы по фиксированным путям.
     * AmneziaWG-конфиги при этом не откатываются (они генерируются из
     * exit_servers, а не из снапшота) — rollback относится к
     * sing-box routing/outbounds, самому изменчивому слою.
     */
    public function rollback(int $versionId, string $username = 'system'): array
    {
        $version = ConfigVersion::find($versionId);
        if (!$version) {
            throw new \InvalidArgumentException('Версия конфигурации не найдена');
        }

        $config = App::config();
        $historicalConfig = json_decode($version['singbox_config'], true);
        if (!is_array($historicalConfig)) {
            throw new \RuntimeException('Сохранённый снапшот конфигурации повреждён');
        }

        $this->writeJson($config['singbox_config_path'], $historicalConfig);
        $result = $this->runApplyScript($config['apply_script']);

        ConfigVersion::markRolledBack($versionId);
        // После отката рабочий конфиг не совпадает с тем, что в БД — показываем «не применено».
        Models\Setting::set('applied_fingerprint', '');
        ConfigVersion::record($username, "rollback -> version #$versionId", $historicalConfig, $result);

        return $result;
    }

    /** Минимальный key-level diff для preview-модалки (не JSON-patch, просто читаемая сводка). */
    private function diff(?array $previous, array $next): array
    {
        if ($previous === null) {
            return [['op' => '+', 'path' => 'config', 'note' => 'первое применение конфигурации']];
        }

        $changes = [];
        $prevOutbounds = array_column($previous['outbounds'] ?? [], 'tag');
        $nextOutbounds = array_column($next['outbounds'] ?? [], 'tag');
        foreach (array_diff($nextOutbounds, $prevOutbounds) as $tag) {
            $changes[] = ['op' => '+', 'path' => 'outbound', 'note' => $tag];
        }
        foreach (array_diff($prevOutbounds, $nextOutbounds) as $tag) {
            $changes[] = ['op' => '-', 'path' => 'outbound', 'note' => $tag];
        }

        $prevRuleCount = count($previous['route']['rules'] ?? []);
        $nextRuleCount = count($next['route']['rules'] ?? []);
        if ($prevRuleCount !== $nextRuleCount) {
            $changes[] = ['op' => '~', 'path' => 'route.rules', 'note' => "$prevRuleCount -> $nextRuleCount правил"];
        }

        $prevUsers = count($previous['inbounds'][0]['users'] ?? []);
        $nextUsers = count($next['inbounds'][0]['users'] ?? []);
        if ($prevUsers !== $nextUsers) {
            $changes[] = ['op' => '~', 'path' => 'inbounds[0].users', 'note' => "$prevUsers -> $nextUsers устройств"];
        }

        return $changes ?: [['op' => '=', 'path' => 'config', 'note' => 'без изменений в outbounds/rules/users']];
    }

    private function runApplyScript(string $script): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(['sudo', '-n', $script], $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Не удалось запустить apply-скрипт');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function writeJson(string $path, array $data): void
    {
        $this->ensureDir(dirname($path));
        $this->writeFile($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * file_put_contents() возвращает false молча (без warning в error_log
     * при подавленных ошибках, без исключения) при отказе в доступе —
     * например если файл уже существует с чужим владельцем. Раньше это
     * приводило к тому, что Apply рапортовал успех, реально не записав
     * ничего на диск (найдено вживую: /etc/sing-box/config.json оказался
     * root:root от установки sing-box, панель писала "в никуда" неделю).
     */
    private function writeFile(string $path, string $content): void
    {
        $written = @file_put_contents($path, $content);
        if ($written === false) {
            $err = error_get_last();
            throw new \RuntimeException("Не удалось записать $path — проверьте владельца/права файла" . ($err ? ': ' . $err['message'] : ''));
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
    }

    /** Удаляет файлы rule-set'ов групп, которых больше нет (например группу удалили). */
    private function pruneDir(string $dir, array $keepTags): void
    {
        $keep = array_map(fn($t) => "$t.json", $keepTags);
        foreach (glob("$dir/*.json") ?: [] as $file) {
            if (!in_array(basename($file), $keep, true)) {
                unlink($file);
            }
        }
    }
}
