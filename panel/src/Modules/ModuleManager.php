<?php

namespace App\Modules;

use App\App;
use App\Database;

/**
 * Модульная система панели. Жизненный цикл:
 *   архив (.vmod) в module-archives/  ──install──▶  распаковка в modules/<id>/ + запись в БД (выключен)
 *                                     ──activate──▶  active=1 (функционал появляется)
 *                                     ──deactivate─▶ active=0 (функционал скрывается)
 *                                     ──remove────▶  удаляются файлы modules/<id>/ и строка modules;
 *                                                     АРХИВ сохраняется; настройки — по выбору:
 *                                                       keepSettings=true  → module_settings остаётся,
 *                                                       keepSettings=false → удаляется.
 *   При повторной установке сохранённые настройки подтягиваются (не перезаписываются).
 *
 * БЕЗОПАСНОСТЬ v1: архив — JSON-бандл {manifest, files}. Файлы модуля не
 * исполняются (манифест — данные; роутер-модули декларативны и маппятся на
 * встроенные экспортеры по `provides.exports[].format`). Выполнение PHP-хуков
 * модулей сознательно НЕ реализовано (это была бы RCE) — см. TODO 7.2.
 */
class ModuleManager
{
    private const ID_RE = '/^[a-z0-9][a-z0-9._-]{1,48}$/';
    private const MAX_FILES = 200;
    private const MAX_FILE_BYTES = 1_000_000;

    private static function baseDir(): string
    {
        return rtrim(dirname((string) App::config()['db_path']), '/');
    }

    public static function archivesDir(): string
    {
        $d = self::baseDir() . '/module-archives';
        if (!is_dir($d)) {
            @mkdir($d, 0750, true);
        }
        return $d;
    }

    public static function modulesDir(): string
    {
        $d = self::baseDir() . '/modules';
        if (!is_dir($d)) {
            @mkdir($d, 0750, true);
        }
        return $d;
    }

    // ----------------------------------------------------------------- чтение

    /** Установленные модули (из БД) + флаги hasSettings/hasArchive. */
    public static function all(): array
    {
        self::ensureBuiltins();
        $rows = Database::get()->query('SELECT * FROM modules ORDER BY type, name')->fetchAll();
        return array_map([self::class, 'decorate'], $rows);
    }

    public static function get(string $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM modules WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::decorate($row) : null;
    }

    private static function decorate(array $row): array
    {
        $row['manifest'] = json_decode((string) $row['manifest'], true) ?: [];
        $row['active'] = (int) $row['active'] === 1;
        $row['has_settings'] = self::hasSettings($row['id']);
        $row['has_archive'] = is_file(self::archivePath($row['id']));
        // Локализованные имя/описание для встроенных модулей (следуют языку интерфейса).
        // Переопределяют то, что записано в БД/архиве на момент установки.
        $row['name'] = self::localName((string) $row['id'], (string) ($row['name'] ?? $row['id']));
        $desc = self::localDesc((string) $row['id'], (string) ($row['manifest']['description'] ?? ''));
        if ($desc !== '') {
            $row['manifest']['description'] = $desc;
        }
        return $row;
    }

    /** Локализованное имя модуля по ключу module.<id>.name; иначе — fallback (из манифеста). */
    private static function localName(string $id, string $fallback): string
    {
        $key = 'module.' . $id . '.name';
        $v = \App\I18n::t($key);
        return $v === $key ? $fallback : $v;
    }

    /** Локализованное описание модуля по ключу module.<id>.desc; иначе — fallback. */
    private static function localDesc(string $id, string $fallback): string
    {
        $key = 'module.' . $id . '.desc';
        $v = \App\I18n::t($key);
        return $v === $key ? $fallback : $v;
    }

    /** Архивы, которые можно установить (ещё не установлены). */
    public static function availableArchives(): array
    {
        self::ensureBuiltins();
        $installed = [];
        foreach (Database::get()->query('SELECT id FROM modules') as $r) {
            $installed[$r['id']] = true;
        }
        $out = [];
        foreach (glob(self::archivesDir() . '/*.vmod') ?: [] as $file) {
            $bundle = self::readArchive($file);
            if ($bundle === null) {
                continue;
            }
            $m = $bundle['manifest'];
            if (isset($installed[$m['id']])) {
                continue; // уже установлен
            }
            $out[] = [
                'id' => $m['id'],
                'name' => self::localName((string) $m['id'], (string) ($m['name'] ?? $m['id'])),
                'version' => $m['version'] ?? '1.0.0',
                'type' => $m['type'] ?? 'generic',
                'description' => self::localDesc((string) $m['id'], (string) ($m['description'] ?? '')),
                'archive' => basename($file),
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------ жизненный цикл

    public static function install(string $archiveName): array
    {
        $path = self::archivePathByName($archiveName);
        $bundle = self::readArchive($path);
        if ($bundle === null) {
            throw new \RuntimeException('Не удалось прочитать архив модуля (ожидается .vmod JSON).');
        }
        $m = $bundle['manifest'];
        $id = $m['id'];

        // Распаковываем файлы в modules/<id>/ (с защитой от обхода путей).
        $dir = self::modulesDir() . '/' . $id;
        self::rrmdir($dir);
        @mkdir($dir, 0750, true);
        $files = $bundle['files'] ?? ['module.json' => json_encode($m, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)];
        if (count($files) > self::MAX_FILES) {
            throw new \RuntimeException('Слишком много файлов в архиве модуля.');
        }
        foreach ($files as $rel => $content) {
            $safe = self::safeRelPath((string) $rel);
            if ($safe === null) {
                throw new \RuntimeException('Недопустимый путь файла в архиве: ' . $rel);
            }
            if (strlen((string) $content) > self::MAX_FILE_BYTES) {
                throw new \RuntimeException('Файл модуля слишком большой: ' . $rel);
            }
            $target = $dir . '/' . $safe;
            @mkdir(dirname($target), 0750, true);
            file_put_contents($target, (string) $content);
        }

        // Регистрируем (выключенным). Настройки, если сохранены ранее, НЕ трогаем.
        // Portable-upsert (без ON CONFLICT…DO UPDATE — его нет в старом SQLite на EL7).
        $pdo = Database::get();
        $manifestJson = json_encode($m, JSON_UNESCAPED_UNICODE);
        $ex = $pdo->prepare('SELECT 1 FROM modules WHERE id = ?');
        $ex->execute([$id]);
        if ($ex->fetchColumn()) {
            $pdo->prepare('UPDATE modules SET name = ?, version = ?, type = ?, manifest = ? WHERE id = ?')
                ->execute([$m['name'] ?? $id, $m['version'] ?? '1.0.0', $m['type'] ?? 'generic', $manifestJson, $id]);
        } else {
            $pdo->prepare('INSERT INTO modules (id, name, version, type, active, manifest) VALUES (?, ?, ?, ?, 0, ?)')
                ->execute([$id, $m['name'] ?? $id, $m['version'] ?? '1.0.0', $m['type'] ?? 'generic', $manifestJson]);
        }
        return self::get($id);
    }

    public static function activate(string $id): void
    {
        self::setActive($id, true);
    }

    public static function deactivate(string $id): void
    {
        self::setActive($id, false);
    }

    private static function setActive(string $id, bool $on): void
    {
        $mod = self::get($id);
        if (!$mod) {
            throw new \RuntimeException('Модуль не установлен: ' . $id);
        }
        $stmt = Database::get()->prepare('UPDATE modules SET active = ? WHERE id = ?');
        $stmt->execute([$on ? 1 : 0, $id]);
    }

    /**
     * Удаляет модуль: файлы modules/<id>/ и строку modules. Архив-установщик
     * сохраняется (можно поставить снова). Настройки удаляются только при
     * keepSettings=false; при true остаются в module_settings (нигде не
     * показываются) и подтянутся при повторной установке.
     */
    public static function remove(string $id, bool $keepSettings = true): void
    {
        if (!preg_match(self::ID_RE, $id)) {
            throw new \RuntimeException('Некорректный id модуля');
        }
        self::rrmdir(self::modulesDir() . '/' . $id);
        Database::transaction(function (\PDO $pdo) use ($id, $keepSettings) {
            $pdo->prepare('DELETE FROM modules WHERE id = ?')->execute([$id]);
            if (!$keepSettings) {
                $pdo->prepare('DELETE FROM module_settings WHERE module_id = ?')->execute([$id]);
            }
        });
    }

    // --------------------------------------------------------------- настройки

    public static function hasSettings(string $id): bool
    {
        $stmt = Database::get()->prepare('SELECT 1 FROM module_settings WHERE module_id = ?');
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    public static function settings(string $id): array
    {
        $stmt = Database::get()->prepare('SELECT data FROM module_settings WHERE module_id = ?');
        $stmt->execute([$id]);
        $raw = $stmt->fetchColumn();
        return $raw ? (json_decode((string) $raw, true) ?: []) : [];
    }

    /** Мягкое сохранение: новые ключи домерживаются, существующие НЕ перезаписываются пустым. */
    public static function saveSettings(string $id, array $data, bool $merge = true): void
    {
        if ($merge) {
            $data = $data + self::settings($id);
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        // Portable-upsert (старый SQLite на EL7 не знает ON CONFLICT…DO UPDATE).
        $pdo = Database::get();
        $ex = $pdo->prepare('SELECT 1 FROM module_settings WHERE module_id = ?');
        $ex->execute([$id]);
        if ($ex->fetchColumn()) {
            $pdo->prepare("UPDATE module_settings SET data = ?, updated_at = datetime('now') WHERE module_id = ?")
                ->execute([$json, $id]);
        } else {
            $pdo->prepare("INSERT INTO module_settings (module_id, data, updated_at) VALUES (?, ?, datetime('now'))")
                ->execute([$id, $json]);
        }
    }

    // ------------------------------------------------------------- возможности

    /** Активные модули заданного типа. */
    public static function activeByType(string $type): array
    {
        return array_values(array_filter(self::all(), fn($m) => $m['active'] && ($m['type'] ?? '') === $type));
    }

    /**
     * Доступна ли «необязательная» фича. Фича гейтится, только если какой-то
     * установленный модуль объявляет её в `provides.features`:
     *   • объявлена активным модулем  → true (фича включена);
     *   • объявлена, но все такие модули выключены → false (фича скрыта);
     *   • никто не объявляет (нет модуля) → true (не гейтим незнакомое).
     * Так ядро работает и без модуля, а наличие модуля даёт выключатель.
     */
    public static function featureActive(string $feature): bool
    {
        $known = false;
        foreach (self::all() as $m) {
            foreach ($m['manifest']['provides']['features'] ?? [] as $f) {
                if ($f === $feature) {
                    $known = true;
                    if ($m['active']) {
                        return true; // есть активный модуль, предоставляющий фичу
                    }
                }
            }
        }
        // Фичу объявляет встроенный модуль (каталог) → она «модульная»: без
        // активного модуля скрыта, даже если модуль удалён (его можно поставить
        // заново). Так «удалил модуль → функционал пропал» работает как ожидается.
        if (!$known) {
            foreach (ModuleCatalog::builtins() as $m) {
                foreach ($m['provides']['features'] ?? [] as $f) {
                    if ($f === $feature) {
                        $known = true;
                        break 2;
                    }
                }
            }
        }
        // Незнакомая фича (никакой модуль её не объявляет) — не гейтим: ядро
        // продолжает работать без модульной системы.
        return !$known;
    }

    /**
     * Кнопки экспорта маршрутов от активных router-модулей.
     * @return array<int,array{format:string,label:string,module:string}>
     */
    public static function routerExports(): array
    {
        $out = [];
        foreach (self::activeByType('router') as $m) {
            foreach ($m['manifest']['provides']['exports'] ?? [] as $e) {
                if (!empty($e['format']) && !empty($e['label'])) {
                    $out[] = ['format' => (string) $e['format'], 'label' => (string) $e['label'], 'module' => $m['id']];
                }
            }
        }
        return $out;
    }

    /** Разрешён ли формат экспорта (его предоставляет активный router-модуль). */
    public static function isExportFormatActive(string $format): bool
    {
        foreach (self::routerExports() as $e) {
            if ($e['format'] === $format) {
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------- встроенные

    /**
     * Создаёт архивы встроенных модулей (роутеры) в module-archives/ и при самом
     * первом запуске ставит+активирует их, чтобы текущее поведение (кнопки
     * экспорта) не пропало. Повторно НЕ активирует (флаг modules_builtins_seeded),
     * поэтому удаление/выключение пользователем сохраняется.
     */
    public static function ensureBuiltins(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        foreach (ModuleCatalog::builtins() as $m) {
            $archive = self::archivePath($m['id']);
            if (!is_file($archive)) {
                $bundle = ['manifest' => $m, 'files' => ['module.json' => json_encode($m, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]];
                @file_put_contents($archive, json_encode($bundle, JSON_UNESCAPED_UNICODE));
            }
        }

        // Посев отслеживаем ПОМОДУЛЬНО (список id), а не одним флагом: чтобы новые
        // встроенные модули тоже посеялись один раз, а удалённые/выключенные
        // пользователем не воскресали.
        try {
            $raw = (string) \App\Models\Setting::get('modules_builtins_seeded', '');
        } catch (\Throwable $e) {
            return; // БД ещё не готова
        }
        // Обратная совместимость: старое значение '1' = роутеры уже посеяны.
        $seeded = $raw === '1'
            ? array_flip(['router-keenetic', 'router-mikrotik', 'router-openwrt'])
            : array_flip(array_filter(array_map('trim', explode(',', $raw))));

        $changed = false;
        foreach (ModuleCatalog::builtins() as $m) {
            if (isset($seeded[$m['id']])) {
                continue;
            }
            if (!self::get($m['id'])) {
                self::install($m['id'] . '.vmod');
                // Платные/опциональные модули можно помечать default_active=false —
                // они ставятся, но включаются вручную (после оплаты/лицензии).
                if (($m['default_active'] ?? true) !== false) {
                    self::activate($m['id']);
                }
            }
            $seeded[$m['id']] = true;
            $changed = true;
        }
        if ($changed) {
            \App\Models\Setting::set('modules_builtins_seeded', implode(',', array_keys($seeded)));
        }
    }

    // -------------------------------------------------------------------- utils

    private static function archivePath(string $id): string
    {
        return self::archivesDir() . '/' . $id . '.vmod';
    }

    private static function archivePathByName(string $name): string
    {
        $name = basename(trim($name)); // срезаем любые пути
        if (!preg_match('/^[A-Za-z0-9._-]{1,80}\.vmod$/', $name)) {
            throw new \RuntimeException('Недопустимое имя архива модуля');
        }
        $path = self::archivesDir() . '/' . $name;
        if (!is_file($path)) {
            throw new \RuntimeException('Архив модуля не найден: ' . $name);
        }
        return $path;
    }

    /** Читает и валидирует .vmod JSON-бандл. null при ошибке. */
    private static function readArchive(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['manifest']) || !is_array($data['manifest'])) {
            return null;
        }
        $m = $data['manifest'];
        if (empty($m['id']) || !is_string($m['id']) || !preg_match(self::ID_RE, $m['id'])) {
            return null;
        }
        if (isset($data['files']) && !is_array($data['files'])) {
            return null;
        }
        return $data;
    }

    /** Безопасный относительный путь внутри каталога модуля (без обхода). */
    private static function safeRelPath(string $rel): ?string
    {
        $rel = str_replace('\\', '/', trim($rel));
        if ($rel === '' || $rel[0] === '/' || str_contains($rel, '..') || str_contains($rel, "\0")) {
            return null;
        }
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '' || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $seg)) {
                return null;
            }
        }
        return $rel;
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') {
                continue;
            }
            $p = $dir . '/' . $it;
            is_dir($p) ? self::rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /** Приёмка загруженного пользователем архива в module-archives/. Возвращает имя файла. */
    public static function acceptUpload(string $tmpPath, string $origName): string
    {
        $raw = @file_get_contents($tmpPath, false, null, 0, self::MAX_FILE_BYTES + 1);
        if ($raw === false || strlen($raw) > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('Архив не читается или слишком большой');
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['manifest']['id']) || !preg_match(self::ID_RE, (string) $data['manifest']['id'])) {
            throw new \RuntimeException('Некорректный архив модуля (ожидается .vmod JSON с manifest.id)');
        }
        $name = $data['manifest']['id'] . '.vmod';
        file_put_contents(self::archivesDir() . '/' . $name, $raw);
        return $name;
    }
}
