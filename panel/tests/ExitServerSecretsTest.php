<?php

namespace Tests;

use App\Database;
use App\Models\ExitServer;
use App\Secrets;
use PHPUnit\Framework\TestCase;

class ExitServerSecretsTest extends TestCase
{
    public function testCreateAndFindRoundTripSecretsAsPlaintext(): void
    {
        $id = ExitServer::create([
            'name' => 'Secret-' . uniqid(),
            'endpoint_host' => 'exit.example.com',
            'endpoint_port' => 51820,
            'status' => 'active',
            'wg_peer_pubkey' => 'pub-plain',
            'wg_peer_psk' => 'psk-plain-secret',
            'wg_local_privkey' => 'privkey-plain-secret',
            'wg_local_address' => '10.0.0.2/32',
            'protocol' => 'vless',
            'protocol_params' => ['uuid' => 'uuid-plain-secret', 'transport' => 'reality'],
        ]);

        $found = ExitServer::find($id);
        $this->assertSame('psk-plain-secret', $found['wg_peer_psk']);
        $this->assertSame('privkey-plain-secret', $found['wg_local_privkey']);
        $params = json_decode($found['protocol_params'], true);
        $this->assertSame('uuid-plain-secret', $params['uuid']);

        $all = ExitServer::all();
        $fromAll = array_values(array_filter($all, fn($r) => (int) $r['id'] === $id))[0];
        $this->assertSame('psk-plain-secret', $fromAll['wg_peer_psk']);
    }

    public function testSecretsAreActuallyEncryptedAtRest(): void
    {
        $id = ExitServer::create([
            'name' => 'AtRest-' . uniqid(),
            'endpoint_host' => 'atrest.example.com',
            'endpoint_port' => 51820,
            'status' => 'active',
            'wg_peer_pubkey' => 'pub-plain',
            'wg_peer_psk' => 'raw-psk-value',
            'wg_local_privkey' => 'raw-privkey-value',
            'wg_local_address' => '10.0.0.3/32',
            'protocol' => 'amneziawg',
        ]);

        $stmt = Database::get()->prepare('SELECT wg_peer_psk, wg_local_privkey FROM exit_servers WHERE id = ?');
        $stmt->execute([$id]);
        $raw = $stmt->fetch();

        $this->assertStringNotContainsString('raw-psk-value', $raw['wg_peer_psk']);
        $this->assertStringNotContainsString('raw-privkey-value', $raw['wg_local_privkey']);
        $this->assertSame('raw-psk-value', Secrets::decrypt($raw['wg_peer_psk']));
    }

    public function testEmptyPlaceholderPrivkeyIsPreservedAsEmptyString(): void
    {
        // Не-WG протоколы оставляют wg_local_privkey='' как заглушку
        // (см. ExitServer::defaults()) — decryptOrPlain() не должен
        // превращать её в null.
        $id = ExitServer::create([
            'name' => 'Placeholder-' . uniqid(),
            'endpoint_host' => 'ss.example.com',
            'endpoint_port' => 8388,
            'status' => 'active',
            'protocol' => 'shadowsocks',
            'protocol_params' => ['method' => 'aes-256-gcm', 'password' => 'ss-pass'],
        ]);

        $found = ExitServer::find($id);
        $this->assertSame('', $found['wg_local_privkey']);
    }

    public function testDecryptOrPlainToleratesLegacyPlaintextRow(): void
    {
        // Симулируем строку, записанную до появления шифрования (сырой INSERT
        // мимо модели) — find() должен по-прежнему отдать читаемое значение,
        // а не бросить исключение (см. bin/encrypt-exit-secrets.php).
        $pdo = Database::get();
        $pdo->exec("INSERT INTO exit_servers
            (name, endpoint_host, endpoint_port, status, wg_peer_pubkey, wg_peer_psk, wg_local_privkey, wg_local_address, interface_name, protocol)
            VALUES ('Legacy', 'legacy.example.com', 51820, 'active', 'pub', NULL, 'legacy-plaintext-privkey', '10.0.0.9/32', 'n-a-legacy', 'amneziawg')");
        $id = (int) $pdo->lastInsertId();

        $found = ExitServer::find($id);
        $this->assertSame('legacy-plaintext-privkey', $found['wg_local_privkey']);
    }
}
