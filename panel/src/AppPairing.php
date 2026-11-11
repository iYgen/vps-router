<?php

namespace App;

/**
 * Привязка Android-приложения к панели без пароля.
 *
 * 1. В браузере (вход уже выполнен, с 2FA если включена) страница «Приложение» выдаёт
 *    одноразовый код и показывает его в QR: vpsrouter://panel?url=…&user=…&code=…
 * 2. Приложение обменивает код на постоянный токен (/api/app-auth.php?action=pair).
 * 3. Дальше приложение получает сессию по токену (?action=session) — как после входа.
 *
 * Код живёт CODE_TTL секунд и срабатывает один раз. Токен можно отозвать на странице
 * «Приложение». В БД — только SHA-256 кода и токена.
 */
class AppPairing
{
    public const CODE_TTL = 600;

    private static function hash(string $v): string
    {
        return hash('sha256', $v);
    }

    /** Новый одноразовый код для текущего пользователя. */
    public static function createCode(int $userId, ?int $clientId): string
    {
        $code = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        $pdo = Database::get();
        // Старые коды не копим
        $pdo->exec("DELETE FROM app_pairings WHERE expires_at < datetime('now', '-1 day')");
        $pdo->prepare("INSERT INTO app_pairings (user_id, code_hash, client_id, expires_at) VALUES (?, ?, ?, datetime('now', ?))")
            ->execute([$userId, self::hash($code), $clientId, '+' . self::CODE_TTL . ' seconds']);
        return $code;
    }

    /**
     * Обменять код на токен. null — код неверный, просрочен или уже использован.
     * @return array{token:string,user_id:int,username:string,client_id:?int}|null
     */
    public static function redeem(string $code, string $name): ?array
    {
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT * FROM app_pairings WHERE code_hash = ? AND used_at IS NULL AND expires_at > datetime('now')");
            $st->execute([self::hash($code)]);
            $p = $st->fetch();
            if (!$p) {
                $pdo->rollBack();
                return null;
            }
            $pdo->prepare("UPDATE app_pairings SET used_at = datetime('now') WHERE id = ?")->execute([$p['id']]);
            $token = bin2hex(random_bytes(32));
            $name = mb_substr(trim($name) !== '' ? trim($name) : 'Android', 0, 80);
            $pdo->prepare('INSERT INTO app_tokens (user_id, token_hash, name, client_id) VALUES (?, ?, ?, ?)')
                ->execute([(int) $p['user_id'], self::hash($token), $name, $p['client_id'] !== null ? (int) $p['client_id'] : null]);
            $u = $pdo->prepare('SELECT username FROM users WHERE id = ?');
            $u->execute([(int) $p['user_id']]);
            $username = (string) $u->fetchColumn();
            $pdo->commit();
            return ['token' => $token, 'user_id' => (int) $p['user_id'], 'username' => $username,
                'client_id' => $p['client_id'] !== null ? (int) $p['client_id'] : null];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @return array{id:int,user_id:int,name:string,client_id:?int}|null */
    public static function findToken(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $st = Database::get()->prepare('SELECT id, user_id, name, client_id FROM app_tokens WHERE token_hash = ? AND revoked_at IS NULL');
        $st->execute([self::hash($token)]);
        $t = $st->fetch();
        if (!$t) {
            return null;
        }
        Database::get()->prepare("UPDATE app_tokens SET last_used_at = datetime('now'), last_ip = ? WHERE id = ?")
            ->execute([Auth::clientIp(), $t['id']]);
        return ['id' => (int) $t['id'], 'user_id' => (int) $t['user_id'], 'name' => (string) $t['name'],
            'client_id' => $t['client_id'] !== null ? (int) $t['client_id'] : null];
    }

    /** Запомнить, каким устройством панели является телефон (выбор в приложении). */
    public static function setClient(int $tokenId, ?int $clientId): void
    {
        Database::get()->prepare('UPDATE app_tokens SET client_id = ? WHERE id = ?')->execute([$clientId, $tokenId]);
    }

    /** @return list<array<string,mixed>> */
    public static function tokens(int $userId): array
    {
        $st = Database::get()->prepare('SELECT t.*, c.name AS client_name FROM app_tokens t LEFT JOIN clients c ON c.id = t.client_id
            WHERE t.user_id = ? AND t.revoked_at IS NULL ORDER BY t.created_at DESC');
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    public static function revoke(int $userId, int $id): void
    {
        Database::get()->prepare("UPDATE app_tokens SET revoked_at = datetime('now') WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
    }
}
