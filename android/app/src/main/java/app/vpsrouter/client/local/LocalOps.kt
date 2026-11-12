package app.vpsrouter.client.local

import android.content.Context
import app.vpsrouter.client.awg.AwgFactory
import app.vpsrouter.client.data.AppLog
import app.vpsrouter.client.data.AwgObfs
import app.vpsrouter.client.data.ManagedServer
import app.vpsrouter.client.data.PortSpec
import app.vpsrouter.client.data.RelayState
import app.vpsrouter.client.data.Repository
import app.vpsrouter.client.data.ServerRole
import app.vpsrouter.client.data.TunnelProfile
import app.vpsrouter.client.ssh.SshConnection
import app.vpsrouter.client.ssh.SshException
import app.vpsrouter.client.ssh.SshTarget
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/**
 * Автономный режим: всё, что панель VPS Router делает по SSH, приложение умеет само —
 * поднять AmneziaWG-сервер, выдать клиентов, настроить ретранслятор (nftables DNAT,
 * тот же relay-forward.sh), поставить саму панель на чистый VPS.
 */
class LocalOps(private val context: Context, private val repo: Repository) {

    /** Скрипт установки: из панели (если синхронизирован) или встроенный из deploy/provision. */
    private fun asset(name: String) = app.vpsrouter.client.update.ScriptStore.read(context, name)

    fun target(s: ManagedServer) = SshTarget(s.host, s.sshPort, s.sshUser, s.password, s.privateKey, s.hostKeyFp)

    suspend fun <T> withSsh(s: ManagedServer, block: (SshConnection) -> T): T = withContext(Dispatchers.IO) {
        SshConnection.connect(target(s)).use { c ->
            if (s.hostKeyFp == null) repo.updateServer(s.id) { it.copy(hostKeyFp = c.hostKeyFingerprint) }
            block(c)
        }
    }

    /** Проверка доступа: подключиться, прочитать сведения о системе, запомнить ключ хоста. */
    suspend fun check(s: ManagedServer): String {
        return try {
            val info = withSsh(s) { it.info() }
            repo.updateServer(s.id) { it.copy(osInfo = info, status = "online", lastCheckAt = System.currentTimeMillis()) }
            info
        } catch (e: Exception) {
            repo.updateServer(s.id) { it.copy(status = "offline", lastCheckAt = System.currentTimeMillis()) }
            throw e
        }
    }

    /** Создать SSH-ключ и перейти на вход по ключу (пароль root больше не хранится). */
    suspend fun switchToKey(s: ManagedServer) {
        val key = withSsh(s) { it.installNewKey() }
        // Проверяем, что вход по ключу работает, прежде чем забыть пароль.
        val probe = s.copy(privateKey = key, password = null)
        withSsh(probe) { it.exec("true") }
        repo.updateServer(s.id) {
            // Не-root без NOPASSWD sudo всё ещё нуждается в пароле для sudo.
            it.copy(privateKey = key, password = if (it.sshUser == "root") null else it.password)
        }
    }

    private suspend fun runProv(s: ManagedServer, script: String, params: String, log: (String) -> Unit): Boolean {
        val lib = asset("_lib.sh")
        val body = asset(script)
        AppLog.i("${s.name}: запуск $script")
        val res = withSsh(s) { it.runScript(lib, body, params, log) }
        if (res.ok) AppLog.i("${s.name}: $script — готово") else AppLog.e("${s.name}: $script — ошибка (код ${res.exitCode})")
        repo.updateServer(s.id) { it.copy(lastLog = res.output.takeLast(6000), lastCheckAt = System.currentTimeMillis(), status = "online") }
        return res.ok
    }

    /** Ускорение (BBR + fq) на своём сервере по SSH: action = on | off | status. */
    suspend fun netBoost(serverId: String, action: String): app.vpsrouter.client.data.BoostResult {
        val s = repo.server(serverId) ?: error("Сервер не найден")
        return runCatching {
            val res = withSsh(s) { it.runScript(asset("_lib.sh"), asset("net-boost.sh"), """{"action":"$action"}""") {} }
            app.vpsrouter.client.data.BoostResult.parse(s.name, res.ok, res.output)
        }.getOrElse { app.vpsrouter.client.data.BoostResult(s.name, false, error = it.message ?: "ошибка SSH") }
    }

    // ---------------------------------------------------------------- AmneziaWG

    suspend fun deployAwg(serverId: String, port: Int, obfs: AwgObfs, firstClient: String?, log: (String) -> Unit): TunnelProfile? {
        val s = repo.server(serverId) ?: error("Сервер не найден")
        var awg = s.awg ?: AwgFactory.newServer(port, obfs)
        awg = awg.copy(port = port, obfs = obfs)
        if (firstClient != null && awg.peers.isEmpty()) awg = awg.copy(peers = listOf(AwgFactory.newPeer(awg, firstClient)))
        val ok = pushAwg(s, awg, log)
        if (!ok) throw SshException("Установка AmneziaWG завершилась с ошибкой — см. журнал")
        repo.updateServer(serverId) { it.copy(role = ServerRole.AWG, awg = awg) }
        val peer = awg.peers.firstOrNull() ?: return null
        return importPeerTunnel(serverId, peer.id)
    }

    private suspend fun pushAwg(s: ManagedServer, awg: app.vpsrouter.client.data.AwgServerState, log: (String) -> Unit): Boolean {
        val params = buildJsonObject {
            put("iface", awg.iface)
            put("port", awg.port)
            put("conf", AwgFactory.serverConfig(awg))
        }.toString()
        return runProv(s, "awg-server.sh", params, log)
    }

    suspend fun addPeer(serverId: String, name: String, log: (String) -> Unit): TunnelProfile {
        val s = repo.server(serverId) ?: error("Сервер не найден")
        val awg = s.awg ?: error("На сервере не настроен AmneziaWG")
        val peer = AwgFactory.newPeer(awg, name)
        val next = awg.copy(peers = awg.peers + peer)
        if (!pushAwg(s, next, log)) throw SshException("Не удалось применить нового клиента — см. журнал")
        repo.updateServer(serverId) { it.copy(awg = next) }
        return importPeerTunnel(serverId, peer.id)!!
    }

    suspend fun removePeer(serverId: String, peerId: String, log: (String) -> Unit) {
        val s = repo.server(serverId) ?: error("Сервер не найден")
        val awg = s.awg ?: return
        val next = awg.copy(peers = awg.peers.filterNot { it.id == peerId })
        if (!pushAwg(s, next, log)) throw SshException("Не удалось удалить клиента на сервере — см. журнал")
        repo.updateServer(serverId) { it.copy(awg = next) }
        repo.update { d -> d.copy(tunnels = d.tunnels.filterNot { it.origin == "$serverId/$peerId" }) }
    }

    /** Конфиг клиента → профиль туннеля (прямой и, если есть, через каждый ретранслятор). */
    fun importPeerTunnel(serverId: String, peerId: String): TunnelProfile? {
        val s = repo.server(serverId) ?: return null
        val awg = s.awg ?: return null
        val peer = awg.peers.firstOrNull { it.id == peerId } ?: return null
        val origin = "$serverId/$peerId"
        val cfg = AwgFactory.clientConfig(awg, peer, s.host)
        val existing = repo.data.value.tunnels.firstOrNull { it.origin == origin && it.via == null }
        val direct = (existing ?: TunnelProfile(name = "${s.name} · ${peer.name}", config = cfg, source = "local", origin = origin))
            .copy(config = cfg)
        repo.upsertTunnel(direct)
        relaysFor(serverId).forEach { relay -> upsertRelayVariant(direct, relay) }
        return direct
    }

    fun clientConfig(serverId: String, peerId: String, viaHost: String? = null): String? {
        val s = repo.server(serverId) ?: return null
        val awg = s.awg ?: return null
        val peer = awg.peers.firstOrNull { it.id == peerId } ?: return null
        return AwgFactory.clientConfig(awg, peer, viaHost ?: s.host)
    }

    suspend fun removeAwg(serverId: String, log: (String) -> Unit) {
        val s = repo.server(serverId) ?: return
        val awg = s.awg
        if (awg != null) {
            val params = buildJsonObject { put("iface", awg.iface); put("port", awg.port); put("remove", true) }.toString()
            runProv(s, "awg-server.sh", params, log)
        }
        repo.updateServer(serverId) { it.copy(role = ServerRole.NONE, awg = null) }
        repo.update { d -> d.copy(tunnels = d.tunnels.filterNot { it.origin?.startsWith("$serverId/") == true }) }
    }

    // ---------------------------------------------------------------- Ретранслятор

    private fun relaysFor(targetServerId: String) =
        repo.data.value.servers.filter { it.role == ServerRole.RELAY && it.relay?.targetServerId == targetServerId }

    private fun upsertRelayVariant(direct: TunnelProfile, relay: ManagedServer) {
        val existing = repo.data.value.tunnels.firstOrNull { it.origin == direct.origin && it.via == relay.name }
        val cfg = AwgFactory.withEndpointHost(direct.config, relay.host)
        repo.upsertTunnel(
            (existing ?: TunnelProfile(name = "${direct.name} ⇢ ${relay.name}", config = cfg, source = direct.source, origin = direct.origin, via = relay.name))
                .copy(config = cfg)
        )
    }

    /**
     * Настроить пересылку портов на ретрансляторе (deploy/provision/relay-forward.sh из vps_router).
     * Пустой список портов — снять пересылку.
     */
    suspend fun deployRelay(serverId: String, relay: RelayState, log: (String) -> Unit) {
        val s = repo.server(serverId) ?: error("Сервер не найден")
        val params = buildJsonObject {
            put("target_ip", relay.targetIp)
            put("ssh_port", s.sshPort)
            put("log_new", true)
            put("ports", JsonArray(relay.ports.map { buildJsonObject { put("port", it.port); put("proto", JsonPrimitive(it.proto)) } }))
        }.toString()
        if (!runProv(s, "relay-forward.sh", params, log)) throw SshException("Настройка ретранслятора завершилась с ошибкой — см. журнал")
        repo.updateServer(serverId) { it.copy(role = if (relay.ports.isEmpty()) ServerRole.NONE else ServerRole.RELAY, relay = relay.takeIf { r -> r.ports.isNotEmpty() }) }
        // Туннели своего AWG-сервера получают копию «через ретранслятор».
        val updated = repo.server(serverId)!!
        relay.targetServerId?.let { tid ->
            repo.data.value.tunnels.filter { it.origin?.startsWith("$tid/") == true && it.via == null }
                .forEach { upsertRelayVariant(it, updated) }
        }
    }

    /** Ретранслятор на произвольный туннель (например, к VPS Router): копия профиля с endpoint = ретранслятор. */
    fun relayCopyOfTunnel(tunnelId: String, relayServerId: String): TunnelProfile? {
        val t = repo.tunnel(tunnelId) ?: return null
        val r = repo.server(relayServerId) ?: return null
        val copy = TunnelProfile(
            name = "${t.name} ⇢ ${r.name}",
            config = AwgFactory.withEndpointHost(t.config, r.host),
            source = t.source, origin = t.origin, via = r.name,
        )
        repo.upsertTunnel(copy)
        return copy
    }

    suspend fun removeRelay(serverId: String, log: (String) -> Unit) {
        val s = repo.server(serverId) ?: return
        val r = s.relay
        if (r != null) runCatching { deployRelay(serverId, r.copy(ports = emptyList()), log) }
        repo.updateServer(serverId) { it.copy(role = ServerRole.NONE, relay = null) }
        repo.update { d -> d.copy(tunnels = d.tunnels.filterNot { it.via == s.name && it.source == "local" }) }
    }

    // ---------------------------------------------------------------- Панель VPS Router

    suspend fun installPanel(serverId: String, domain: String, log: (String) -> Unit): String? {
        val s = repo.server(serverId) ?: error("Сервер не найден")
        val params = buildJsonObject {
            put("repo", PANEL_REPO)
            put("branch", "main")
            put("domain", domain.trim())
            put("lang", "ru")
        }.toString()
        var url: String? = null
        val ok = runProv(s, "panel-install.sh", params) { line ->
            Regex("""(https?://\S+/install\.php\?t=[0-9a-fA-F]+)""").find(line)?.let { url = it.groupValues[1] }
            log(line)
        }
        if (!ok) throw SshException("Установка панели завершилась с ошибкой — см. журнал")
        repo.updateServer(serverId) { it.copy(role = ServerRole.PANEL, panelInstallUrl = url) }
        return url
    }

    /** Живой статус роли на сервере: awg show / таблица nft ретранслятора. */
    suspend fun liveStatus(serverId: String): String {
        val s = repo.server(serverId) ?: return ""
        val cmd = when (s.role) {
            ServerRole.AWG -> "awg show ${s.awg?.iface ?: "awg-vpsr"} 2>&1 | sed 's/private key: .*/private key: (скрыт)/'"
            ServerRole.RELAY -> "nft list table inet vpsr_relay 2>&1; echo; journalctl -k -g vpsr-relay --since '-1h' --no-pager 2>/dev/null | tail -n 15"
            ServerRole.PANEL -> "systemctl is-active nginx sing-box 2>&1; ls /var/www/panel >/dev/null 2>&1 && echo 'панель: /var/www/panel'"
            ServerRole.NONE -> "uptime"
        }
        return withSsh(s) { it.exec(cmd, asRoot = true).output.trim() }
    }

    companion object {
        const val PANEL_REPO = "https://github.com/iYgen/vps-router.git"
        fun parsePorts(s: String) = PortSpec.parseList(s)
    }
}
