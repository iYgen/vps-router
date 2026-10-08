<?php

namespace App;

/**
 * Экспорт/импорт настроек панели vps_router («сохранить/загрузить настройки»).
 * Используется и на странице Настройки, и в веб-установщике (восстановление из
 * копии). Выгружает конфиг/состояние панели как есть (сырые строки таблиц,
 * включая зашифрованные секреты) + app_secret — чтобы на восстановлении
 * зашифрованные значения (SSH-ключи серверов, Reality/WG-секреты) остались
 * расшифровываемыми. Файл бэкапа поэтому ЧУВСТВИТЕЛЬНЫЙ (эквивалент секретов) —
 * скачивать только по HTTPS и хранить бережно.
 *
 * НЕ входят: users (логин задаётся заново), audit_log/login_blocks/
 * password_resets (безопасность), config_versions/schema_migrations, а также
 * транзиентная статистика (traffic_*, device_presence, *_load, risk_scan_*).
 */
class PanelBackup
{
    public const VERSION = 1;

    /** Порядок важен для вставки при включённых внешних ключах (родители раньше детей). */
    private const TABLES = [
        'server_settings',
        'servers',
        'exit_servers',
        'connections',
        'server_sets',
        'server_set_members',
        'rule_groups',
        'rules',
        'clients',
        'node_settings',
        'exit_server_peers',
        'policy_profiles',
        'policy_profile_routes',
        'policy_devices',
        'policy_versions',
    ];

    public static function export(): array
    {
        $pdo = Database::get();
        $tables = [];
        foreach (self::TABLES as $t) {
            if (!self::tableExists($pdo, $t)) {
                continue;
            }
            $tables[$t] = $pdo->query("SELECT * FROM $t")->fetchAll(\PDO::FETCH_ASSOC);
        }
        return [
            'format' => 'vps_router.settings',
            'version' => self::VERSION,
            'created_at' => date('c'),
            'app_secret' => App::config()['app_secret'] ?? null,
            'tables' => $tables,
        ];
    }

    public static function exportJson(): string
    {
        return json_encode(self::export(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * Восстанавливает данные из массива export(). Полностью заменяет содержимое
     * перечисленных таблиц (не мержит). Внешние ключи на время импорта
     * отключаются (вставка идёт в порядке TABLES). users/логин не трогаются.
     *
     * @return array{restored: array<string,int>}
     */
    public static function import(array $data): array
    {
        if (($data['format'] ?? '') !== 'vps_router.settings' || !isset($data['tables']) || !is_array($data['tables'])) {
            throw new \InvalidArgumentException('Файл не похож на резервную копию настроек vps_router.');
        }
        $pdo = Database::get();
        // PRAGMA foreign_keys нельзя менять внутри транзакции — делаем вне её.
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $restored = [];
        try {
            // Чистим в обратном порядке, вставляем в прямом.
            foreach (array_reverse(self::TABLES) as $t) {
                if (self::tableExists($pdo, $t)) {
                    $pdo->exec("DELETE FROM $t");
                }
            }
            foreach (self::TABLES as $t) {
                $rows = $data['tables'][$t] ?? [];
                if (!$rows || !self::tableExists($pdo, $t)) {
                    $restored[$t] = 0;
                    continue;
                }
                $cols = array_keys($rows[0]);
                $safeCols = array_filter($cols, fn($c) => preg_match('/^[A-Za-z0-9_]+$/', $c));
                $colList = implode(',', $safeCols);
                $ph = implode(',', array_fill(0, count($safeCols), '?'));
                $stmt = $pdo->prepare("INSERT INTO $t ($colList) VALUES ($ph)");
                $n = 0;
                foreach ($rows as $row) {
                    $vals = [];
                    foreach ($safeCols as $c) {
                        $vals[] = $row[$c] ?? null;
                    }
                    $stmt->execute($vals);
                    $n++;
                }
                $restored[$t] = $n;
            }
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
        return ['restored' => $restored];
    }

    private static function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }
}
