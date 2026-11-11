package app.vpsrouter.client.data

import kotlinx.serialization.Serializable
import java.util.UUID

fun newId(): String = UUID.randomUUID().toString().substring(0, 8)

/**
 * Профиль соединения. kind = "awg": config — текст awg-quick (.conf), ядро AmneziaWG.
 * kind = "singbox": config — JSON outbound sing-box (VLESS+Reality, Hysteria2, Trojan,
 * Shadowsocks) из панели, ядро sing-box (libbox).
 */
@Serializable
data class TunnelProfile(
    val id: String = newId(),
    val name: String,
    val config: String,
    val kind: String = "awg",
    /** manual | panel | local — откуда взялся (импорт / панель VPS Router / свой сервер). */
    val source: String = "manual",
    /** Для panel: id устройства в панели; для local: "<serverId>/<peerId>". */
    val origin: String? = null,
    /** Имя ретранслятора, если endpoint указывает на ретранслятор. */
    val via: String? = null,
    val createdAt: Long = System.currentTimeMillis(),
)

enum class ServerRole { NONE, AWG, RELAY, PANEL }

@Serializable
data class PortSpec(val port: Int, val proto: String = "tcp") {
    override fun toString() = "$port/$proto"

    companion object {
        /** "443/tcp, 51820/udp 80" → список; протокол по умолчанию tcp. */
        fun parseList(s: String): List<PortSpec> = s.split(',', ' ', ';', '\n')
            .map { it.trim() }.filter { it.isNotEmpty() }
            .map {
                val parts = it.split('/')
                val port = parts[0].toIntOrNull() ?: throw IllegalArgumentException("Неверный порт: $it")
                require(port in 1..65535) { "Порт вне диапазона: $it" }
                val proto = parts.getOrNull(1)?.lowercase() ?: "tcp"
                require(proto == "tcp" || proto == "udp") { "Протокол должен быть tcp или udp: $it" }
                PortSpec(port, proto)
            }.distinct()
    }
}

/** Параметры обфускации AmneziaWG 1.x (совместимы с amneziawg-tools 1.x и 2.x). */
@Serializable
data class AwgObfs(
    val jc: Int = 4, val jmin: Int = 40, val jmax: Int = 70,
    val s1: Int = 0, val s2: Int = 0,
    val h1: Long = 1, val h2: Long = 2, val h3: Long = 3, val h4: Long = 4,
) {
    fun lines(): String = "Jc = $jc\nJmin = $jmin\nJmax = $jmax\nS1 = $s1\nS2 = $s2\nH1 = $h1\nH2 = $h2\nH3 = $h3\nH4 = $h4\n"
}

val TunnelProfile.isSingbox: Boolean get() = kind == "singbox"

@Serializable
data class AwgPeer(
    val id: String = newId(),
    val name: String,
    val privateKey: String,
    val publicKey: String,
    val presharedKey: String,
    /** Адрес клиента в туннеле, напр. 10.66.66.2 */
    val ip: String,
    val createdAt: Long = System.currentTimeMillis(),
)

@Serializable
data class AwgServerState(
    val iface: String = "awg-vpsr",
    val port: Int,
    /** Сеть туннеля, первые три октета: 10.66.66 → сервер .1, клиенты .2+ */
    val subnetPrefix: String = "10.66.66",
    val serverPrivateKey: String,
    val serverPublicKey: String,
    val obfs: AwgObfs,
    val dns: String = "1.1.1.1, 8.8.8.8",
    val mtu: Int = 1280,
    val peers: List<AwgPeer> = emptyList(),
)

@Serializable
data class RelayState(
    val targetIp: String,
    /** Описание цели: «VPS Router (панель)», имя своего сервера или «IP вручную». */
    val targetLabel: String,
    /** id своего сервера-цели (если ретранслятор ведёт на AWG-сервер из приложения). */
    val targetServerId: String? = null,
    val ports: List<PortSpec>,
)

@Serializable
data class ManagedServer(
    val id: String = newId(),
    val name: String,
    val host: String,
    val sshPort: Int = 22,
    val sshUser: String = "root",
    /** Пароль SSH (хранится, только если пользователь не перевёл узел на ключ). */
    val password: String? = null,
    /** Приватный SSH-ключ (PEM / OpenSSH), созданный приложением или вставленный вручную. */
    val privateKey: String? = null,
    /** Отпечаток ключа хоста (TOFU): при смене ключа подключение прерывается. */
    val hostKeyFp: String? = null,
    val role: ServerRole = ServerRole.NONE,
    val osInfo: String? = null,
    val status: String = "unknown",
    val lastCheckAt: Long? = null,
    val lastLog: String? = null,
    val awg: AwgServerState? = null,
    val relay: RelayState? = null,
    /** Ссылка мастера установки панели VPS Router (если ставили панель на этот узел). */
    val panelInstallUrl: String? = null,
)

@Serializable
data class PanelProfile(
    val url: String,
    /** Запасные адреса панели (напр. через ретранслятор) — пробуются по очереди. */
    val altUrls: List<String> = emptyList(),
    val username: String,
    /** Пусто, если приложение привязано по QR (вход по [appToken]). */
    val password: String = "",
    /** Разрешить самоподписанные/не совпадающие по имени сертификаты (панель по IP). */
    val insecureTls: Boolean = false,
    /** Ключ доступа приложения, выданный панелью по одноразовому коду из QR (можно отозвать в панели). */
    val appToken: String? = null,
    /** Каким устройством панели является этот телефон — его соединения показываются в приложении. */
    val myDeviceId: Int? = null,
)

/** Режим маршрутизации на устройстве (применяется к любому туннелю при подключении). */
enum class RouteMode { ALL, EXCLUDE_APPS, ONLY_APPS }

@Serializable
data class AppSettings(
    val onboarded: Boolean = false,
    /** Подключать последний туннель при запуске приложения. */
    val autoConnect: Boolean = false,
    val routeMode: RouteMode = RouteMode.ALL,
    /** Пакеты приложений для EXCLUDE_APPS / ONLY_APPS. */
    val apps: List<String> = emptyList(),
    /** Локальная сеть (192.168.x, 10.x …) мимо VPN. */
    val bypassLan: Boolean = false,
    /** Свои DNS вместо указанных в конфиге (пусто — из конфига). */
    val dnsOverride: String = "",
    /**
     * «Скрыть VPN от приложений»: эти приложения всегда идут мимо туннеля, при любом профиле.
     * Для них сеть по умолчанию — обычная (без признака VPN), а адрес — домашний.
     */
    val hiddenApps: List<String> = emptyList(),
    /** Скорость VPN в строке состояния (значок уведомления у камеры). */
    val speedInStatusBar: Boolean = false,
    /** Какую версию условий (права и данные) пользователь принял; 0 — ещё не видел. */
    val consentVersion: Int = 0,
    /** Проверять обновления приложения в GitHub (сетевой запрос к github.com). */
    val githubUpdates: Boolean = true,
)

/** Маршрут (группа правил) панели VPS Router: что и через какой выход идёт на сервере. */
@Serializable
data class PanelRoute(
    val id: Int,
    val name: String,
    val enabled: Boolean,
    val parentId: Int? = null,
    /** Куда идёт трафик группы: exit-сервер или набор серверов; null — напрямую. */
    val target: String? = null,
    /** Правила "тип:значение", напр. "domain_suffix:youtube.com". */
    val rules: List<String> = emptyList(),
)

/** Кэш последнего ответа панели — показывается, когда панель недоступна. */
@Serializable
data class PanelCache(
    val fetchedAt: Long = 0,
    val servers: List<PanelServer> = emptyList(),
    val devices: List<PanelDevice> = emptyList(),
    val relays: List<PanelConnection> = emptyList(),
    val routes: List<PanelRoute> = emptyList(),
)

@Serializable
data class PanelServer(
    val id: Int,
    val name: String,
    val role: String,
    val host: String? = null,
    val status: String? = null,
    val isSelf: Boolean = false,
    val country: String? = null,
)

@Serializable
data class PanelConnection(
    val id: Int,
    val type: String,
    val sourceId: Int,
    val targetId: Int,
    val label: String? = null,
    val provisionStatus: String? = null,
    val ports: List<String> = emptyList(),
)

@Serializable
data class PanelDevice(
    val id: Int,
    val name: String,
    val revoked: Boolean = false,
    val online: Boolean = false,
    /** Ключи конфигов: "amneziawg", "vless", "amneziawg@3" (через ретранслятор id=3)… */
    val formats: List<String> = emptyList(),
)

@Serializable
data class AppData(
    val tunnels: List<TunnelProfile> = emptyList(),
    val servers: List<ManagedServer> = emptyList(),
    val panel: PanelProfile? = null,
    val panelCache: PanelCache = PanelCache(),
    val lastTunnelId: String? = null,
    val settings: AppSettings = AppSettings(),
    /** Трафик по соединениям, посчитанный на телефоне: id туннеля → итоги. */
    val traffic: Map<String, ConnTraffic> = emptyMap(),
)

@Serializable
data class ConnTraffic(val rx: Long = 0, val tx: Long = 0, val lastUsed: Long = 0)
