<?php

namespace App;

use App\Models\Rule;
use App\Models\RuleGroup;

/**
 * Синхронизирует списки IP-подсетей из публичного репозитория
 * https://github.com/RockBlack-VPN/ip-address (папка Global/<Сервис>/*.bat,
 * формат Keenetic-роутера: "route add <ip> mask <netmask> 0.0.0.0" —
 * одна строка = одна подсеть).
 *
 * Каждый .bat-файл -> отдельная подгруппа (rule_group с parent_id на общий
 * корневой раздел), идемпотентно по import_source_path (путь файла в
 * репозитории) — повторный sync ОБНОВЛЯЕТ правила существующей группы, а
 * не плодит дубликаты. Ручные (не импортированные) route-группы
 * пользователя синхронизация не трогает вообще.
 */
class IpListImporter
{
    private const REPO = 'RockBlack-VPN/ip-address';
    private const BASE_PATH = 'Global';
    private const ROOT_MARKER = '__rockblack_root__';
    private const ROOT_GROUP_NAME = 'Импортированные списки (RockBlack-VPN)';

    /**
     * @param callable(string):void|null $onProgress вызывается с путём файла перед каждой попыткой импорта
     * @return array{imported:int,skipped:int,total:int,errors:array<int,string>}
     */
    public static function sync(?callable $onProgress = null): array
    {
        $rootId = self::ensureRootGroup();
        $files = self::listBatFiles();

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($files as $path) {
            if ($onProgress) {
                $onProgress($path);
            }
            try {
                $ok = self::importFile($path, $rootId);
                $ok ? $imported++ : $skipped++;
            } catch (\Throwable $e) {
                $errors[] = "$path: " . $e->getMessage();
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'total' => count($files), 'errors' => $errors];
    }

    /** Разбирает содержимое одного .bat-файла в список CIDR (публичный метод — покрыт тестами без сети). */
    public static function parseBat(string $content): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            if (!preg_match('/^\s*route\s+add\s+(\d{1,3}(?:\.\d{1,3}){3})\s+mask\s+(\d{1,3}(?:\.\d{1,3}){3})/i', $line, $m)) {
                continue;
            }
            $prefix = self::maskToPrefix($m[2]);
            if ($prefix === null) {
                continue;
            }
            $out[] = $m[1] . '/' . $prefix;
        }
        return array_values(array_unique($out));
    }

    private static function maskToPrefix(string $mask): ?int
    {
        $long = ip2long($mask);
        if ($long === false) {
            return null;
        }
        $bits = str_pad(decbin($long), 32, '0', STR_PAD_LEFT);
        return substr_count($bits, '1');
    }

    private static function ensureRootGroup(): int
    {
        $existing = RuleGroup::findByImportSource(self::ROOT_MARKER);
        if ($existing) {
            return (int) $existing['id'];
        }
        return RuleGroup::create(
            self::ROOT_GROUP_NAME,
            null,
            null,
            'Корневой раздел для синхронизированных списков — сам по себе роутинг не задаёт, служит папкой для подразделов.',
            null,
            'imported',
            self::ROOT_MARKER
        );
    }

    /** @return string[] пути *.bat-файлов внутри Global/ в репозитории */
    private static function listBatFiles(): array
    {
        $url = 'https://api.github.com/repos/' . self::REPO . '/git/trees/main?recursive=1';
        $json = self::httpGet($url);
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['tree'])) {
            throw new \RuntimeException('Не удалось получить список файлов репозитория (GitHub API)');
        }

        $files = [];
        foreach ($data['tree'] as $entry) {
            $path = $entry['path'] ?? '';
            if (($entry['type'] ?? '') === 'blob'
                && str_starts_with($path, self::BASE_PATH . '/')
                && str_ends_with(strtolower($path), '.bat')
            ) {
                $files[] = $path;
            }
        }
        return $files;
    }

    /** @return bool true если группа создана/обновлена, false если файл пуст (пропущен) */
    private static function importFile(string $path, int $rootId): bool
    {
        $raw = self::httpGet('https://raw.githubusercontent.com/' . self::REPO . '/main/' . self::encodePath($path));
        $cidrs = self::parseBat($raw);
        if (!$cidrs) {
            return false;
        }

        $existing = RuleGroup::findByImportSource($path);
        $groupName = self::resolveGroupName($path, $existing['id'] ?? null);

        if ($existing) {
            $groupId = (int) $existing['id'];
            RuleGroup::rename($groupId, $groupName);
            Rule::deleteAllForGroup($groupId);
        } else {
            $groupId = RuleGroup::create($groupName, null, null, "Импортировано из $path", $rootId, 'imported', $path);
        }

        Rule::createMany($groupId, 'ip_cidr', $cidrs);
        return true;
    }

    /**
     * Имя группы = имя папки (сервис), напр. "Discord". Папка может
     * содержать НЕСКОЛЬКО .bat-файлов (варианты/версии списка) — тогда
     * первому достаётся чистое имя папки, остальным — с уточнением по
     * имени файла, чтобы не столкнуться с rule_groups.name UNIQUE
     * (найдено живым прогоном на проде: Discord/Discord.bat +
     * Discord/Discord_Old.bat + Discord/discordgg.bat).
     */
    private static function resolveGroupName(string $path, ?int $selfId): string
    {
        $parts = explode('/', $path);
        $file = array_pop($parts);
        $folder = $parts[count($parts) - 1] ?? $file;
        $base = pathinfo($file, PATHINFO_FILENAME);

        $candidate = $folder;
        if (!RuleGroup::nameExists($candidate, $selfId)) {
            return $candidate;
        }
        $candidate = "$folder — $base";
        if (!RuleGroup::nameExists($candidate, $selfId)) {
            return $candidate;
        }
        // Совсем маловероятный случай — оба варианта заняты чем-то посторонним.
        return "$folder — $base (" . substr(md5($path), 0, 6) . ')';
    }

    /** Кодирует каждый сегмент пути отдельно (пробелы и т.п. в именах папок/файлов), сохраняя "/". */
    private static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private static function httpGet(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: vps_router-panel\r\n",
                'timeout' => 15,
            ],
        ]);
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            throw new \RuntimeException("HTTP-запрос не удался: $url");
        }
        return $result;
    }
}
