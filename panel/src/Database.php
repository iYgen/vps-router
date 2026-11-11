<?php

namespace App;

class Database
{
    private static ?\PDO $pdo = null;

    public static function get(): \PDO
    {
        if (self::$pdo === null) {
            $config = App::config();
            $dbPath = $config['db_path'];

            $isNew = !file_exists($dbPath);

            $pdo = new \PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA foreign_keys = ON');
            // Панель, крон-сборщик трафика и health-check пишут в одну SQLite БД
            // из разных процессов. По умолчанию busy_timeout=0 — параллельная
            // запись сразу падает с "database is locked". WAL позволяет читать во
            // время записи, а busy_timeout заставляет подождать освобождения
            // блокировки вместо мгновенной ошибки (частая причина сбоя удаления
            // при работающем сборщике трафика).
            $pdo->exec('PRAGMA busy_timeout = 15000');
            @$pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');

            self::$pdo = $pdo;

            // Во время самообновления (SelfUpdater) новый код уже на диске, и любой
            // запрос (браузер, приложение, крон) начал бы сам накатывать миграции
            // параллельно с обновлятором → «database is locked» и откат обновления.
            // Пока идёт обновление, миграции делает только обновлятор.
            if ($isNew || !self::selfUpdateInProgress()) {
                self::migrate($isNew);
            }
        }

        return self::$pdo;
    }

    /** Глубина «нашей» транзакции (см. transaction()) — вложенные вызовы не открывают новую. */
    private static int $txnDepth = 0;

    /**
     * Выполнить $fn внутри транзакции, устойчиво к "database is locked".
     *
     * Почему не обычный beginTransaction(): PDO открывает транзакцию как DEFERRED —
     * блокировка записи берётся только на ПЕРВОЙ записи. Если между стартом
     * (первым SELECT) и первой записью другой процесс уже стал писателем, SQLite
     * возвращает SQLITE_BUSY НЕМЕДЛЕННО, и busy_timeout тут не помогает (это защита
     * от дедлока при апгрейде читатель→писатель). Мы стартуем BEGIN IMMEDIATE —
     * write-лок берётся сразу, и busy_timeout уже честно ждёт его освобождения.
     * Сверху — повтор всей транзакции с экспоненциальной паузой на случай, если
     * блокировку не удалось взять за отведённое время (частый сценарий: клик по
     * графу во время работы крон-сборщика трафика).
     *
     * @template T
     * @param callable(\PDO):T $fn
     * @return T
     */
    public static function transaction(callable $fn, int $retries = 6)
    {
        $pdo = self::get();
        // Вложенный вызов — не открываем вторую транзакцию (SQLite их не поддерживает).
        if (self::$txnDepth > 0) {
            return $fn($pdo);
        }
        $attempt = 0;
        while (true) {
            try {
                $pdo->exec('BEGIN IMMEDIATE');
            } catch (\Throwable $e) {
                if (self::isLocked($e) && $attempt++ < $retries) {
                    usleep(self::backoffUs($attempt));
                    continue;
                }
                throw $e;
            }
            self::$txnDepth = 1;
            try {
                $result = $fn($pdo);
                $pdo->exec('COMMIT');
                self::$txnDepth = 0;
                return $result;
            } catch (\Throwable $e) {
                self::$txnDepth = 0;
                try {
                    $pdo->exec('ROLLBACK');
                } catch (\Throwable $ignore) {
                    // нет активной транзакции — уже откатилась
                }
                if (self::isLocked($e) && $attempt++ < $retries) {
                    usleep(self::backoffUs($attempt));
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * Повторить $fn при "database is locked" (для отдельных записей вне транзакции).
     * @template T
     * @param callable(\PDO):T $fn
     * @return T
     */
    public static function retry(callable $fn, int $retries = 6)
    {
        $attempt = 0;
        while (true) {
            try {
                return $fn(self::get());
            } catch (\Throwable $e) {
                if (self::isLocked($e) && $attempt++ < $retries) {
                    usleep(self::backoffUs($attempt));
                    continue;
                }
                throw $e;
            }
        }
    }

    private static function isLocked(\Throwable $e): bool
    {
        $m = strtolower($e->getMessage());
        return str_contains($m, 'database is locked')
            || str_contains($m, 'database is busy')
            || str_contains($m, 'database table is locked');
    }

    /** Экспоненциальная пауза с джиттером: ~50, 100, 200, 400, 800мс (потолок 800мс). */
    private static function backoffUs(int $attempt): int
    {
        $base = (int) min(50000 * (2 ** ($attempt - 1)), 800000);
        return $base + random_int(0, 25000);
    }

    /**
     * Последовательно и идемпотентно применяет все *.sql из migrations/,
     * упорядоченные по имени файла (числовой префикс). Уже применённые
     * версии отслеживаются в schema_migrations и повторно не выполняются.
     * На абсолютно новой БД миграции просто накатываются по порядку —
     * отдельного "initial schema" не требуется.
     */
    public static function migrate(bool $isNew = false): void
    {
        $pdo = self::$pdo ?? self::get();
        self::retry(fn() => $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )'
        ));

        $dir = __DIR__ . '/../migrations';
        $files = glob($dir . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);

        // Быстрый путь: всё уже накатано — ни блокировок, ни записи.
        if (!self::pendingVersions($pdo, $files)) {
            return;
        }

        // Несколько процессов (веб, крон, обновлятор) могут одновременно увидеть
        // новые миграции. Файловая блокировка выстраивает их в очередь, а внутри
        // очереди список применённых перечитывается — каждая миграция ровно один раз.
        $lock = @fopen(App::config()['db_path'] . '.migrate.lock', 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        try {
            foreach ($files as $file) {
                $version = basename($file, '.sql');
                $sql = (string) file_get_contents($file);
                try {
                    // BEGIN IMMEDIATE: write-лок сразу, busy_timeout честно ждёт;
                    // повтор с паузой, если база занята дольше (сборщик трафика и т.п.).
                    self::transaction(function (\PDO $pdo) use ($version, $sql) {
                        $done = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = ?');
                        $done->execute([$version]);
                        if ($done->fetchColumn()) {
                            return;
                        }
                        $pdo->exec($sql);
                        $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
                    }, 10);
                } catch (\Throwable $e) {
                    throw new \RuntimeException("Миграция $version провалилась: " . $e->getMessage(), 0, $e);
                }
            }
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @param string[] $files */
    private static function pendingVersions(\PDO $pdo, array $files): array
    {
        $applied = [];
        foreach (self::retry(fn() => $pdo->query('SELECT version FROM schema_migrations')->fetchAll()) as $row) {
            $applied[$row['version']] = true;
        }
        return array_values(array_filter(array_map(fn($f) => basename($f, '.sql'), $files), fn($v) => !isset($applied[$v])));
    }

    /**
     * Идёт самообновление: SelfUpdater распаковывает архив в .update/extract и
     * удаляет его в конце. Свежесть 10 минут — чтобы оборванное обновление не
     * блокировало миграции навсегда.
     */
    public static function selfUpdateInProgress(): bool
    {
        $dir = dirname(__DIR__) . '/.update/extract';
        clearstatcache(true, $dir);
        return is_dir($dir) && (time() - (int) @filemtime($dir)) < 600;
    }
}
