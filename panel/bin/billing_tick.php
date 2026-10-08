<?php
// Биллинг: отключение просроченных подписок (устройства → revoked, конфиг
// переприменяется) и email-напоминания о скором окончании. Идемпотентно.
//
// Запуск по cron (раз в 15 минут достаточно — срок считается по дате):
//   */15 * * * * php /var/www/panel/bin/billing_tick.php >/dev/null 2>&1

require __DIR__ . '/../src/bootstrap.php';

use App\Billing;
use App\Models\Subscription;
use App\Modules\ModuleManager;
use App\PortalMail;

if (!ModuleManager::featureActive('billing')) {
    exit(0); // модуль «Биллинг» выключен
}

// 1) Отключить просроченные по сроку.
$expired = Billing::enforce();
if ($expired > 0) {
    fwrite(STDERR, "billing: expired $expired subscription(s)\n");
}

// 1b) Отключить превысивших лимит трафика в текущем периоде.
$overTraffic = Billing::enforceTraffic();
if ($overTraffic > 0) {
    fwrite(STDERR, "billing: traffic limit hit for $overTraffic subscriber(s)\n");
}

// 2) Напоминания о продлении (за 3 дня, не чаще раза в сутки на подписку).
$daysBefore = 3;
foreach (Billing::dueReminders($daysBefore) as $r) {
    $to = (string) ($r['subscriber_email'] ?? '');
    if ($to === '') {
        continue;
    }
    $sent = PortalMail::send($to, 'reminder', [
        '{name}' => (string) ($r['subscriber_name'] ?? ''),
        '{plan}' => (string) ($r['plan_name'] ?? ''),
        '{date}' => date('d.m.Y', strtotime((string) $r['expires_at'])),
        '{link}' => PortalMail::portalBase() . '/portal.php',
    ]);
    if ($sent) {
        Subscription::markReminded((int) $r['id']);
    }
}
