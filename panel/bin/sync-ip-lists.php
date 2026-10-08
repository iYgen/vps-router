<?php
// Синхронизирует списки IP-подсетей из https://github.com/RockBlack-VPN/ip-address
// (см. App\IpListImporter) — каждый *.bat-файл в Global/ становится
// подразделом route-группы "Импортированные списки". Идемпотентно, можно
// гонять по расписанию.
// Запуск вручную: php bin/sync-ip-lists.php
// По cron раз в сутки: 0 4 * * * php /var/www/panel/bin/sync-ip-lists.php >> /var/log/panel-sync.log 2>&1

require __DIR__ . '/../src/bootstrap.php';

use App\IpListImporter;

$result = IpListImporter::sync(function (string $path) {
    fwrite(STDOUT, "  $path\n");
});

fwrite(STDOUT, "Готово: импортировано {$result['imported']}, пропущено (пусто) {$result['skipped']}, всего файлов {$result['total']}\n");
if ($result['errors']) {
    fwrite(STDERR, "Ошибки:\n");
    foreach ($result['errors'] as $err) {
        fwrite(STDERR, "  $err\n");
    }
    exit(1);
}
