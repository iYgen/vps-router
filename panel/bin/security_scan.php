<?php

// Считает доступные обновления пакетов на сервере панели и кладёт результат в
// кэш (server_settings.security_pending_updates). Запускать по cron, например
// раз в 6 часов:
//   0 */6 * * *  panel  php82 /var/www/panel/bin/security_scan.php
// Пакетный менеджер (apt/dnf/yum) медленный и в веб-запросе не вызывается —
// панель показывает последний закэшированный результат.

require __DIR__ . '/../src/bootstrap.php';

$result = App\SecurityAudit::scanUpdates();
fwrite(STDOUT, sprintf(
    "[security_scan] manager=%s total=%d security=%d at=%s\n",
    $result['manager'] ?? '-',
    $result['total'],
    $result['security'],
    $result['checked_at']
));
