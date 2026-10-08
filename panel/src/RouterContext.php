<?php

namespace App;

use App\Models\Server;

/**
 * «Текущий роутер» — какой вход-роутер сейчас редактируется в панели
 * (устройства, маршруты, настройки, Apply). Мультироутерность (вариант 3):
 * пока роутер один (self), контекст всегда = self и переключатель не мешает.
 *
 * Выбор хранится в сессии; ?router=ID на любой странице его меняет (как ?lang=).
 * Скоуп данных: self-роутер видит и свои строки, и «наследие» (router_server_id
 * IS NULL, см. миграцию 015), не-self — строго свои.
 */
class RouterContext
{
    /** Все узлы-роутеры (role=router), self первым. @return array<int,array> */
    public static function routers(): array
    {
        $out = [];
        foreach (Server::all() as $s) {
            if (($s['role'] ?? '') === 'router') {
                $out[] = $s;
            }
        }
        usort($out, fn($a, $b) => ((int) ($b['is_self'] ?? 0)) <=> ((int) ($a['is_self'] ?? 0)));
        return $out;
    }

    public static function count(): int
    {
        return count(self::routers());
    }

    /** id текущего роутера: ?router= > сессия > self. Валидируется по факту. */
    public static function currentId(): int
    {
        $routers = self::routers();
        $ids = array_map(fn($r) => (int) $r['id'], $routers);
        $selfId = self::selfId($routers);

        if (isset($_GET['router']) && ctype_digit((string) $_GET['router']) && in_array((int) $_GET['router'], $ids, true)) {
            $_SESSION['router_id'] = (int) $_GET['router'];
        }

        $current = isset($_SESSION['router_id']) ? (int) $_SESSION['router_id'] : null;
        if ($current !== null && in_array($current, $ids, true)) {
            return $current;
        }
        return $selfId ?? ($ids[0] ?? 0);
    }

    public static function current(): ?array
    {
        $id = self::currentId();
        foreach (self::routers() as $r) {
            if ((int) $r['id'] === $id) {
                return $r;
            }
        }
        return null;
    }

    public static function isSelf(int $id): bool
    {
        $self = Server::self();
        return $self !== null && (int) $self['id'] === $id;
    }

    /**
     * self-роутер включает строки с router_server_id IS NULL («наследие» до
     * появления мультироутерности). Не-self — строго свои. Используется при
     * скоупе Client::/RuleGroup:: запросов.
     */
    public static function includeNull(int $id): bool
    {
        return self::isSelf($id);
    }

    private static function selfId(array $routers): ?int
    {
        foreach ($routers as $r) {
            if (!empty($r['is_self'])) {
                return (int) $r['id'];
            }
        }
        $self = Server::self();
        return $self ? (int) $self['id'] : null;
    }
}
