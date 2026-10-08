<?php

namespace Tests;

use App\Auth;
use App\Database;
use App\Models\Connection;
use App\Models\ExitServer;
use App\Models\Server;
use App\Provisioner;
use PHPUnit\Framework\TestCase;

class OrphanExitAndAccountTest extends TestCase
{
    private function ensureSelf(): void
    {
        if (!Server::self()) {
            $id = Server::create(['name' => 'entry-' . uniqid(), 'role' => 'router']);
            Database::get()->prepare('UPDATE servers SET is_self = 1 WHERE id = ?')->execute([$id]);
        }
    }

    public function testOrphanExitServerBecomesGraphNode(): void
    {
        $this->ensureSelf();
        $es = ExitServer::create([
            'name' => 'Orphan-' . uniqid(), 'endpoint_host' => '203.0.113.9', 'endpoint_port' => 443,
            'wg_peer_pubkey' => 'p', 'wg_local_privkey' => 'l', 'wg_local_address' => '10.0.0.2/32',
            'interface_name' => 'awg-orph-' . random_int(1000, 9999), 'status' => 'active', 'protocol' => 'vless',
        ]);

        $before = count(array_filter(Connection::all(), fn($c) => (int) ($c['exit_server_id'] ?? 0) === $es));
        $this->assertSame(0, $before);

        $added = Provisioner::syncOrphanExitServers();
        $this->assertGreaterThanOrEqual(1, $added);

        $linked = array_filter(Connection::all(), fn($c) => (int) ($c['exit_server_id'] ?? 0) === $es);
        $this->assertCount(1, $linked);
        // Идемпотентность: второй проход не плодит дубли.
        Provisioner::syncOrphanExitServers();
        $this->assertCount(1, array_filter(Connection::all(), fn($c) => (int) ($c['exit_server_id'] ?? 0) === $es));
    }

    /**
     * Регрессия: удаление графового узла exit-сервера должно удалять и связанный
     * exit_server, иначе syncOrphanExitServers() воскрешает узел при следующей
     * загрузке графа («сервер не удаляется, возвращается после Apply/refresh»).
     */
    public function testDeletingExitNodeAlsoRemovesExitServerSoItIsNotResurrected(): void
    {
        $this->ensureSelf();
        $es = ExitServer::create([
            'name' => 'FreeDel-' . uniqid(), 'endpoint_host' => '203.0.113.44', 'endpoint_port' => 8388,
            'status' => 'active', 'protocol' => 'shadowsocks', 'source' => 'free',
        ]);

        // Узел + связь появляются из «осиротевшего» exit'а.
        Provisioner::syncOrphanExitServers();
        $conn = null;
        foreach (Connection::all() as $c) {
            if ((int) ($c['exit_server_id'] ?? 0) === $es) { $conn = $c; break; }
        }
        $this->assertNotNull($conn, 'связь для exit должна была появиться');
        $nodeId = (int) $conn['target_server_id'];

        // Удаляем именно узел графа (как кнопка «Удалить» в инспекторе).
        Server::delete($nodeId);

        $this->assertNull(Server::find($nodeId), 'узел удалён');
        $this->assertNull(ExitServer::find($es), 'exit_server тоже удалён (иначе воскреснет)');

        // Ключевое: повторная синхронизация НЕ создаёт узел заново.
        Provisioner::syncOrphanExitServers();
        $revived = array_filter(Connection::all(), fn($c) => (int) ($c['exit_server_id'] ?? 0) === $es);
        $this->assertCount(0, $revived, 'узел не должен воскресать');
    }

    public function testChangeUsernameRequiresCorrectPassword(): void
    {
        $pdo = Database::get();
        $u = 'acc' . random_int(1000, 9999);
        $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)')
            ->execute([$u, password_hash('secret-pass-12', PASSWORD_DEFAULT)]);
        $_SESSION['user_id'] = (int) $pdo->lastInsertId();
        $_SESSION['username'] = $u;

        $this->expectException(\RuntimeException::class);
        Auth::changeUsername('newname', 'wrong-password');
    }

    public function testChangeUsernameSucceeds(): void
    {
        $pdo = Database::get();
        $u = 'acc' . random_int(1000, 9999);
        $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)')
            ->execute([$u, password_hash('secret-pass-12', PASSWORD_DEFAULT)]);
        $_SESSION['user_id'] = (int) $pdo->lastInsertId();
        $_SESSION['username'] = $u;

        $new = 'acc' . random_int(10000, 99999);
        Auth::changeUsername($new, 'secret-pass-12');
        $this->assertSame($new, $_SESSION['username']);
    }

    public function testChangeUsernameRejectsBadFormat(): void
    {
        $pdo = Database::get();
        $u = 'acc' . random_int(1000, 9999);
        $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)')
            ->execute([$u, password_hash('secret-pass-12', PASSWORD_DEFAULT)]);
        $_SESSION['user_id'] = (int) $pdo->lastInsertId();
        $_SESSION['username'] = $u;

        $this->expectException(\InvalidArgumentException::class);
        Auth::changeUsername('ab', 'secret-pass-12'); // короче 3 символов
    }
}
