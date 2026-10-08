<?php

namespace App;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscriber;
use App\Models\Subscription;

/**
 * Биллинг: тарифы → подписки подписчиков → их устройства (clients.subscriber_id).
 * Оплата (ручная или через шлюз) продлевает подписку; при просрочке устройства
 * подписчика отключаются (clients.revoked=1) и конфиг переприменяется.
 *
 * Важно: трогаем revoked ТОЛЬКО у устройств с subscriber_id (биллинговые);
 * устройства без подписчика (админские) биллинг не затрагивает.
 */
class Billing
{
    /** Привязать устройство (client) к подписчику с проверкой лимита тарифа. */
    public static function assignDevice(int $subscriberId, int $clientId): void
    {
        $sub = Subscription::forSubscriber($subscriberId);
        $plan = ($sub && $sub['plan_id']) ? Plan::find((int) $sub['plan_id']) : null;
        $limit = self::effectiveDeviceLimit($sub, $plan);
        // Текущее число привязанных устройств (исключая это же).
        $cur = Database::get()->prepare('SELECT COUNT(*) FROM clients WHERE subscriber_id = ? AND id <> ?');
        $cur->execute([$subscriberId, $clientId]);
        if ((int) $cur->fetchColumn() >= $limit) {
            throw new \RuntimeException(I18n::t('billing.err.device_limit', $limit));
        }
        $stmt = Database::get()->prepare('UPDATE clients SET subscriber_id = ? WHERE id = ?');
        $stmt->execute([$subscriberId, $clientId]);
        // Доступ зависит от состояния подписки.
        self::syncSubscriberDevices($subscriberId);
    }

    public static function unassignDevice(int $clientId): void
    {
        $stmt = Database::get()->prepare('UPDATE clients SET subscriber_id = NULL WHERE id = ?');
        $stmt->execute([$clientId]);
    }

    /**
     * Продлить (или создать) подписку подписчика на $days дней. Продление идёт
     * от текущей даты окончания, если она в будущем, иначе от «сейчас».
     */
    public static function extendSubscription(int $subscriberId, int $days, ?int $planId = null): int
    {
        $now = time();
        $sub = Subscription::forSubscriber($subscriberId);
        $base = $now;
        if ($sub && $sub['status'] !== 'cancelled') {
            $curExp = strtotime((string) $sub['expires_at']);
            if ($curExp && $curExp > $now) {
                $base = $curExp;
            }
        }
        $newExpires = date('Y-m-d H:i:s', $base + $days * 86400);
        $nowStr = date('Y-m-d H:i:s');
        if ($sub) {
            Subscription::setExpiry((int) $sub['id'], $newExpires, 'active');
            // Новый оплаченный период: сбрасываем окно учёта трафика и снимаем блок.
            $stmt = Database::get()->prepare(
                'UPDATE billing_subscriptions SET traffic_period_start = ?, traffic_blocked = 0' .
                ($planId !== null ? ', plan_id = ?' : '') . ' WHERE id = ?'
            );
            $stmt->execute($planId !== null ? [$nowStr, $planId, (int) $sub['id']] : [$nowStr, (int) $sub['id']]);
            $subId = (int) $sub['id'];
        } else {
            $subId = Subscription::create($subscriberId, $planId, $newExpires);
            $stmt = Database::get()->prepare('UPDATE billing_subscriptions SET traffic_period_start = ? WHERE id = ?');
            $stmt->execute([$nowStr, $subId]);
        }
        self::syncSubscriberDevices($subscriberId);
        return $subId;
    }

    /** Ручной платёж: создаёт запись и продлевает подписку на period_days тарифа. */
    public static function recordManualPayment(int $subscriberId, ?int $planId, float $amount, int $periodDays, string $comment = ''): int
    {
        $plan = $planId ? Plan::find($planId) : null;
        $currency = $plan['currency'] ?? 'RUB';
        if ($periodDays <= 0 && $plan) {
            $periodDays = (int) $plan['period_days'];
        }
        $subId = self::extendSubscription($subscriberId, $periodDays, $planId);
        $payId = Payment::create([
            'subscriber_id'   => $subscriberId,
            'subscription_id' => $subId,
            'plan_id'         => $planId,
            'amount'          => $amount,
            'currency'        => $currency,
            'method'          => 'manual',
            'status'          => 'paid',
            'period_days'     => $periodDays,
            'comment'         => $comment ?: null,
            'paid_at'         => date('Y-m-d H:i:s'),
        ]);
        AuditLog::record('billing.payment_manual', "subscriber=$subscriberId amount=$amount days=$periodDays");
        self::reapply([$subscriberId]);
        return $payId;
    }

    /**
     * Активировать бесплатный тариф (price=0) без оплаты. Разрешено, только если
     * подписка сейчас не активна (чтобы нельзя было бесконечно «докручивать» срок).
     */
    public static function activateFree(int $subscriberId, int $planId): void
    {
        $plan = Plan::find($planId);
        if (!$plan || !$plan['enabled'] || (float) $plan['price'] != 0.0) {
            throw new \RuntimeException(I18n::t('billing.err.not_free'));
        }
        $sub = Subscription::forSubscriber($subscriberId);
        if (self::subscriptionState($sub) === 'active') {
            throw new \RuntimeException(I18n::t('billing.err.already_active'));
        }
        $days = (int) $plan['period_days'];
        $subId = self::extendSubscription($subscriberId, $days, $planId);
        Payment::create([
            'subscriber_id'   => $subscriberId,
            'subscription_id' => $subId,
            'plan_id'         => $planId,
            'amount'          => 0,
            'currency'        => (string) $plan['currency'],
            'method'          => 'free',
            'status'          => 'paid',
            'period_days'     => $days,
            'paid_at'         => date('Y-m-d H:i:s'),
        ]);
        AuditLog::record('billing.free_activated', "subscriber=$subscriberId plan=$planId days=$days");
        self::reapply([$subscriberId]);
    }

    /**
     * Применить успешный платёж от шлюза (идемпотентно по external_id): продлить
     * подписку и переприменить конфиг. Вызывается из webhook.
     */
    public static function applyGatewayPayment(string $method, string $externalId): bool
    {
        $pay = Payment::findByExternal($method, $externalId);
        if (!$pay) {
            return false;
        }
        if ($pay['status'] === 'paid') {
            return true; // уже обработан — идемпотентность
        }
        Payment::markPaid((int) $pay['id']);
        // Пополнение баланса — зачисляем, подписку не трогаем.
        if (($pay['purpose'] ?? 'subscription') === 'topup') {
            self::adjustBalance((int) $pay['subscriber_id'], (float) $pay['amount'], 'Пополнение (онлайн)');
            AuditLog::record('billing.topup_gateway', "$method:$externalId +{$pay['amount']}");
            return true;
        }
        $days = (int) $pay['period_days'];
        if ($days <= 0 && $pay['plan_id']) {
            $plan = Plan::find((int) $pay['plan_id']);
            $days = $plan ? (int) $plan['period_days'] : 30;
        }
        self::extendSubscription((int) $pay['subscriber_id'], $days, $pay['plan_id'] ? (int) $pay['plan_id'] : null);
        AuditLog::record('billing.payment_gateway', "$method:$externalId subscriber={$pay['subscriber_id']} days=$days");
        self::reapply([(int) $pay['subscriber_id']]);
        return true;
    }

    /** Отключить/включить устройства подписчика по статусу подписки И лимиту трафика. */
    public static function syncSubscriberDevices(int $subscriberId): void
    {
        $sub = Subscription::forSubscriber($subscriberId);
        $active = $sub
            && $sub['status'] === 'active'
            && strtotime((string) $sub['expires_at']) > time()
            && empty($sub['traffic_blocked']);
        $stmt = Database::get()->prepare('UPDATE clients SET revoked = ? WHERE subscriber_id = ?');
        $stmt->execute([$active ? 0 : 1, $subscriberId]);
    }

    private const GB = 1000000000; // 1 ГБ = 10^9 байт (десятичный, как у хостеров/в тарифах)

    /** Человекочитаемый лимит/объём трафика: <1 ГБ → «МБ», иначе «ГБ» (без лишних нулей). null → без лимита. */
    public static function trafficLabel(?float $gb): ?string
    {
        if ($gb === null) {
            return null;
        }
        if ($gb > 0 && $gb < 1) {
            return rtrim(rtrim(number_format($gb * 1000, 1, '.', ''), '0'), '.') . ' ' . I18n::t('unit.mb');
        }
        return rtrim(rtrim(number_format($gb, 2, '.', ''), '0'), '.') . ' ' . I18n::t('unit.gb');
    }

    /** Цена без лишних нулей: 199 / 199.5 / 199.99. */
    public static function priceLabel(float $price): string
    {
        return rtrim(rtrim(number_format($price, 2, '.', ''), '0'), '.');
    }

    /** Потрачено байт подписчиком за текущий период (сумма up+down его устройств). */
    public static function usageBytes(int $subscriberId, string $sinceDay): int
    {
        $keys = [];
        foreach (Subscriber::devices($subscriberId) as $d) {
            $keys[] = 'c' . (int) $d['id'];
        }
        if (!$keys) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($keys), '?'));
        $stmt = Database::get()->prepare(
            "SELECT COALESCE(SUM(up + down), 0) FROM traffic_daily WHERE day >= ? AND client_key IN ($in)"
        );
        $stmt->execute(array_merge([substr($sinceDay, 0, 10)], $keys));
        return (int) $stmt->fetchColumn();
    }

    /**
     * Сведения об использовании трафика для подписчика: лимит (ГБ), потрачено,
     * заблокирован ли. Если у тарифа нет лимита — limit_gb = null.
     */
    public static function usageInfo(int $subscriberId): array
    {
        $sub = Subscription::forSubscriber($subscriberId);
        $plan = ($sub && $sub['plan_id']) ? Plan::find((int) $sub['plan_id']) : null;
        $limitGb = self::effectiveTrafficGb($sub, $plan);
        $since = $sub ? (string) ($sub['traffic_period_start'] ?? $sub['started_at']) : date('Y-m-d');
        $used = $sub ? self::usageBytes($subscriberId, $since) : 0;
        return [
            'limit_gb'   => $limitGb,
            'used_bytes' => $used,
            'used_gb'    => round($used / self::GB, 2),
            'blocked'    => (bool) ($sub['traffic_blocked'] ?? false),
            'since'      => $since,
        ];
    }

    /**
     * Отключить подписчиков, превысивших лимит трафика в текущем периоде.
     * Ставит traffic_blocked=1 и отключает устройства. Для cron. Сброс — при
     * продлении (новый период обнуляет окно и снимает блок). @return int
     */
    public static function enforceTraffic(): int
    {
        $rows = Database::get()->query(
            "SELECT s.*, p.traffic_gb, p.device_limit FROM billing_subscriptions s
             JOIN billing_plans p ON p.id = s.plan_id
             WHERE s.status = 'active' AND s.traffic_blocked = 0 AND p.traffic_gb IS NOT NULL"
        )->fetchAll();
        $n = 0;
        $affected = [];
        foreach ($rows as $s) {
            $since = (string) ($s['traffic_period_start'] ?? $s['started_at']);
            $used = self::usageBytes((int) $s['subscriber_id'], $since);
            $limitGb = (float) $s['traffic_gb'] + (float) ($s['extra_traffic_gb'] ?? 0);
            if ($used >= $limitGb * self::GB) {
                $stmt = Database::get()->prepare('UPDATE billing_subscriptions SET traffic_blocked = 1 WHERE id = ?');
                $stmt->execute([(int) $s['id']]);
                $rev = Database::get()->prepare('UPDATE clients SET revoked = 1 WHERE subscriber_id = ?');
                $rev->execute([(int) $s['subscriber_id']]);
                AuditLog::record('billing.traffic_exceeded', "subscriber={$s['subscriber_id']} used=" . round($used / self::GB, 1) . "GB");
                $affected[] = (int) $s['subscriber_id'];
                $n++;
            }
        }
        if ($n > 0) {
            self::reapply($affected);
        }
        return $n;
    }

    /** Добавить устройство подписчику (для портала): создать client + привязать. */
    public static function addDevice(int $subscriberId, string $name, ?string $deviceType = null): int
    {
        $created = \App\Models\Client::create($name !== '' ? $name : 'device', null, $deviceType);
        try {
            self::assignDevice($subscriberId, (int) $created['id']); // внутри — проверка лимита
        } catch (\Throwable $e) {
            \App\Models\Client::delete((int) $created['id']); // не оставляем «осиротевшее» устройство
            throw $e;
        }
        self::reapply([$subscriberId]);
        return (int) $created['id'];
    }

    /**
     * Отключить просроченные подписки (status active, expires_at в прошлом):
     * пометить expired, отключить устройства, переприменить конфиг. Для cron.
     * @return int сколько подписок отключено
     */
    public static function enforce(): int
    {
        $rows = Database::get()->query(
            "SELECT * FROM billing_subscriptions WHERE status = 'active' AND expires_at <= datetime('now')"
        )->fetchAll();
        $n = 0;
        $affected = [];
        foreach ($rows as $s) {
            Subscription::setStatus((int) $s['id'], 'expired');
            $stmt = Database::get()->prepare('UPDATE clients SET revoked = 1 WHERE subscriber_id = ?');
            $stmt->execute([(int) $s['subscriber_id']]);
            Subscription::markEnforced((int) $s['id']);
            AuditLog::record('billing.expired', "subscriber={$s['subscriber_id']} sub={$s['id']}");
            $affected[] = (int) $s['subscriber_id'];
            $n++;
        }
        if ($n > 0) {
            self::reapply($affected);
        }
        return $n;
    }

    /**
     * Подписки, истекающие в ближайшие $daysBefore дней, которым ещё не слали
     * напоминание за последние ~($daysBefore) дней. Для cron + email.
     */
    public static function dueReminders(int $daysBefore = 3): array
    {
        $stmt = Database::get()->prepare(
            "SELECT s.*, sub.name AS subscriber_name, sub.email AS subscriber_email, p.name AS plan_name
             FROM billing_subscriptions s
             JOIN billing_subscribers sub ON sub.id = s.subscriber_id
             LEFT JOIN billing_plans p ON p.id = s.plan_id
             WHERE s.status = 'active'
               AND sub.email IS NOT NULL AND sub.email <> ''
               AND s.expires_at > datetime('now')
               AND s.expires_at <= datetime('now', ?)
               AND (s.reminded_at IS NULL OR s.reminded_at <= datetime('now', '-1 day'))"
        );
        $stmt->execute(["+{$daysBefore} days"]);
        return $stmt->fetchAll();
    }

    /**
     * Переприменить конфиг на роутерах, где реально есть устройства затронутых
     * подписчиков (а не только self) — иначе отключение/включение устройства на
     * удалённом роутере не вступит в силу до его следующего apply. Каждый роутер
     * применяется best-effort (ошибка одного не роняет остальные и вызов).
     * @param int[] $subscriberIds пусто → только self-роутер
     */
    private static function reapply(array $subscriberIds = []): void
    {
        $applier = new Applier();
        foreach (self::routersFor($subscriberIds) as $routerId) {
            try {
                $applier->apply('billing', 'billing', $routerId);
            } catch (\Throwable $e) {
                AuditLog::record('billing.reapply_fail', "router=$routerId " . $e->getMessage());
            }
        }
    }

    /**
     * Какие роутеры переприменять: распределение устройств затронутых подписчиков
     * по роутерам (clients.router_server_id; NULL = self). Пусто → [self].
     * @param int[] $subscriberIds
     * @return int[] id роутеров
     */
    private static function routersFor(array $subscriberIds): array
    {
        $self = \App\Models\Server::self();
        $selfId = $self ? (int) $self['id'] : 0;
        if (!$subscriberIds) {
            return $selfId ? [$selfId] : [];
        }
        $in = implode(',', array_fill(0, count($subscriberIds), '?'));
        $stmt = Database::get()->prepare("SELECT DISTINCT router_server_id FROM clients WHERE subscriber_id IN ($in)");
        $stmt->execute(array_map('intval', $subscriberIds));
        $routers = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $rid) {
            $routers[(int) ($rid ?: $selfId)] = true; // NULL router = self
        }
        if (!$routers && $selfId) {
            $routers[$selfId] = true;
        }
        return array_keys($routers);
    }

    /**
     * Удалить устройство и переприменить его роутер. Роутер определяем ДО удаления
     * (после — строки уже нет, routersFor его не увидит). best-effort apply.
     */
    public static function removeDevice(int $clientId): void
    {
        $c = \App\Models\Client::find($clientId);
        if (!$c) {
            return;
        }
        $self = \App\Models\Server::self();
        $routerId = (int) ($c['router_server_id'] ?: ($self['id'] ?? 0));
        \App\Models\Client::delete($clientId);
        if ($routerId) {
            try {
                (new Applier())->apply('billing', 'device removed', $routerId);
            } catch (\Throwable $e) {
                AuditLog::record('billing.reapply_fail', "router=$routerId " . $e->getMessage());
            }
        }
    }

    /** Отменить подписку: вернуть неиспользованную часть на баланс, отключить устройства. */
    public static function cancelSubscription(int $subscriberId, bool $refund = true): void
    {
        $sub = Subscription::forSubscriber($subscriberId);
        if (!$sub) {
            return;
        }
        if ($refund && self::subscriptionState($sub) === 'active' && $sub['plan_id']) {
            $plan = Plan::find((int) $sub['plan_id']);
            $r = self::remainingDays($sub);
            if ($plan && $r > 0 && (int) $plan['period_days'] > 0) {
                $refundAmt = round((float) $plan['price'] / (int) $plan['period_days'] * $r, 2);
                if ($refundAmt > 0) {
                    self::adjustBalance($subscriberId, $refundAmt, 'Возврат при отмене подписки');
                }
            }
        }
        Subscription::setStatus((int) $sub['id'], 'cancelled');
        self::syncSubscriberDevices($subscriberId);
        self::reapply([$subscriberId]);
        AuditLog::record('billing.cancel', "subscriber=$subscriberId");
    }

    // ───────────────────────── баланс и допы ─────────────────────────

    /** Эффективный лимит трафика (ГБ) с учётом допов; null = безлимит. */
    public static function effectiveTrafficGb(?array $sub, ?array $plan): ?float
    {
        if (!$plan || $plan['traffic_gb'] === null) {
            return null;
        }
        return (float) $plan['traffic_gb'] + (float) ($sub['extra_traffic_gb'] ?? 0);
    }

    /** Эффективный лимит устройств с учётом допов. */
    public static function effectiveDeviceLimit(?array $sub, ?array $plan): int
    {
        return ($plan ? (int) $plan['device_limit'] : 1) + (int) ($sub['extra_devices'] ?? 0);
    }

    public static function balanceOf(int $subscriberId): float
    {
        $s = Subscriber::find($subscriberId);
        return $s ? (float) ($s['balance'] ?? 0) : 0.0;
    }

    /** Изменить баланс (+/-) и записать в леджер. */
    public static function adjustBalance(int $subscriberId, float $delta, string $reason): float
    {
        $pdo = Database::get();
        $pdo->prepare('UPDATE billing_subscribers SET balance = COALESCE(balance,0) + ? WHERE id = ?')->execute([$delta, $subscriberId]);
        $pdo->prepare('INSERT INTO billing_balance_ledger (subscriber_id, amount, reason) VALUES (?, ?, ?)')->execute([$subscriberId, $delta, $reason]);
        return self::balanceOf($subscriberId);
    }

    /** Остаток дней подписки (вверх). */
    private static function remainingDays(array $sub): int
    {
        $exp = strtotime((string) $sub['expires_at']);
        return $exp ? max(0, (int) ceil(($exp - time()) / 86400)) : 0;
    }

    /** Пересчитать блок трафика по текущему эффективному лимиту (НЕ сбрасывая used). */
    public static function recomputeTrafficBlock(int $subscriberId): void
    {
        $sub = Subscription::forSubscriber($subscriberId);
        if (!$sub) {
            return;
        }
        $plan = $sub['plan_id'] ? Plan::find((int) $sub['plan_id']) : null;
        $limit = self::effectiveTrafficGb($sub, $plan);
        $blocked = 0;
        if ($limit !== null) {
            $since = (string) ($sub['traffic_period_start'] ?? $sub['started_at']);
            if (self::usageBytes($subscriberId, $since) >= $limit * self::GB) {
                $blocked = 1;
            }
        }
        Database::get()->prepare('UPDATE billing_subscriptions SET traffic_blocked = ? WHERE id = ?')->execute([$blocked, (int) $sub['id']]);
    }

    /**
     * Сменить тариф с пересчётом: дата окончания СОХРАНЯЕТСЯ, доплата за остаток
     * периода (разница дневных ставок × оставшиеся дни) списывается с баланса; при
     * понижении разница возвращается на баланс. Трафик/лимиты НЕ сбрасываются —
     * только пересчитывается блокировка по новому лимиту.
     */
    public static function changePlan(int $subscriberId, int $newPlanId): void
    {
        $sub = Subscription::forSubscriber($subscriberId);
        if (!$sub || self::subscriptionState($sub) !== 'active' || !$sub['plan_id']) {
            throw new \RuntimeException(I18n::t('billing.err.no_active'));
        }
        if ((int) $sub['plan_id'] === $newPlanId) {
            return;
        }
        $old = Plan::find((int) $sub['plan_id']);
        $new = Plan::find($newPlanId);
        if (!$old || !$new || !$new['enabled']) {
            throw new \RuntimeException(I18n::t('billing.err.plan'));
        }
        $r = self::remainingDays($sub);
        $oldDaily = (int) $old['period_days'] > 0 ? (float) $old['price'] / (int) $old['period_days'] : 0;
        $newDaily = (int) $new['period_days'] > 0 ? (float) $new['price'] / (int) $new['period_days'] : 0;
        $diff = round(($newDaily - $oldDaily) * $r, 2);
        if ($diff > 0) {
            if (self::balanceOf($subscriberId) < $diff) {
                throw new \RuntimeException(I18n::t('billing.err.need_balance', self::priceLabel($diff - self::balanceOf($subscriberId))));
            }
            self::adjustBalance($subscriberId, -$diff, 'Смена тарифа: доплата');
        } elseif ($diff < 0) {
            self::adjustBalance($subscriberId, -$diff, 'Смена тарифа: возврат разницы');
        }
        Database::get()->prepare('UPDATE billing_subscriptions SET plan_id = ? WHERE id = ?')->execute([$newPlanId, (int) $sub['id']]);
        Payment::create([
            'subscriber_id' => $subscriberId, 'subscription_id' => (int) $sub['id'], 'plan_id' => $newPlanId,
            'amount' => max(0, $diff), 'currency' => (string) $new['currency'], 'method' => 'balance',
            'status' => 'paid', 'period_days' => 0, 'purpose' => 'change', 'paid_at' => date('Y-m-d H:i:s'),
        ]);
        self::recomputeTrafficBlock($subscriberId); // понижение с исчерпанным лимитом → блок; повышение → разблок
        self::syncSubscriberDevices($subscriberId);
        AuditLog::record('billing.change_plan', "subscriber=$subscriberId -> plan=$newPlanId diff=$diff");
        self::reapply([$subscriberId]);
    }

    /** Купить доп: тип traffic (ГБ) или device (шт.) на текущий период, с баланса. */
    public static function buyAddon(int $subscriberId, string $type, float $qty): void
    {
        $sub = Subscription::forSubscriber($subscriberId);
        if (!$sub || self::subscriptionState($sub) !== 'active') {
            throw new \RuntimeException(I18n::t('billing.err.no_active'));
        }
        $qty = max(0, $qty);
        if ($qty <= 0) {
            throw new \InvalidArgumentException(I18n::t('billing.err.addon_qty'));
        }
        if ($type === 'traffic') {
            $unit = (float) \App\Models\Setting::get('billing_addon_traffic_price', '0');
            $col = 'extra_traffic_gb';
        } elseif ($type === 'device') {
            $unit = (float) \App\Models\Setting::get('billing_addon_device_price', '0');
            $qty = (int) $qty;
            $col = 'extra_devices';
        } else {
            throw new \InvalidArgumentException('bad addon');
        }
        if ($unit <= 0) {
            throw new \RuntimeException(I18n::t('billing.err.addon_off'));
        }
        $price = round($unit * $qty, 2);
        if (self::balanceOf($subscriberId) < $price) {
            throw new \RuntimeException(I18n::t('billing.err.need_balance', self::priceLabel($price - self::balanceOf($subscriberId))));
        }
        self::adjustBalance($subscriberId, -$price, "Доп: $type x$qty");
        Database::get()->prepare("UPDATE billing_subscriptions SET $col = COALESCE($col,0) + ? WHERE id = ?")->execute([$qty, (int) $sub['id']]);
        Payment::create([
            'subscriber_id' => $subscriberId, 'subscription_id' => (int) $sub['id'], 'plan_id' => $sub['plan_id'] ? (int) $sub['plan_id'] : null,
            'amount' => $price, 'currency' => (string) \App\Models\Setting::get('billing_currency', 'RUB'), 'method' => 'balance',
            'status' => 'paid', 'period_days' => 0, 'purpose' => 'addon', 'paid_at' => date('Y-m-d H:i:s'),
        ]);
        self::recomputeTrafficBlock($subscriberId); // доп трафика может снять блок
        self::syncSubscriberDevices($subscriberId);
        AuditLog::record('billing.addon', "subscriber=$subscriberId $type x$qty price=$price");
        self::reapply([$subscriberId]);
    }

    /** Человекочитаемый статус подписки для UI. */
    public static function subscriptionState(?array $sub): string
    {
        if (!$sub) {
            return 'none';
        }
        if ($sub['status'] === 'cancelled') {
            return 'cancelled';
        }
        if ($sub['status'] === 'expired' || strtotime((string) $sub['expires_at']) <= time()) {
            return 'expired';
        }
        return 'active';
    }
}
