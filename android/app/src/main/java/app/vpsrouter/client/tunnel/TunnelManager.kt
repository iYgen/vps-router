package app.vpsrouter.client.tunnel

import android.content.Context
import app.vpsrouter.client.data.AppLog
import app.vpsrouter.client.data.Repository
import app.vpsrouter.client.data.TunnelProfile
import app.vpsrouter.client.data.isSingbox
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import org.amnezia.awg.backend.BackendException
import org.amnezia.awg.backend.GoBackend
import org.amnezia.awg.backend.Tunnel
import org.amnezia.awg.config.Config
import java.io.BufferedReader
import java.io.StringReader

data class TunnelStatus(
    val activeId: String? = null,
    val state: Tunnel.State = Tunnel.State.DOWN,
    val rx: Long = 0,
    val tx: Long = 0,
    val lastHandshake: Long = 0,
    /** Время включения туннеля (для таймера соединения). */
    val connectedAt: Long = 0,
    /** Скорость, байт/с. */
    val rxRate: Long = 0,
    val txRate: Long = 0,
    val error: String? = null,
    val busy: Boolean = false,
)

/**
 * Один активный туннель за раз (как в AmneziaWG). Бэкенд — userspace amneziawg-go
 * через VpnService (GoBackend), root не нужен.
 */
class TunnelManager(private val context: Context, private val repo: Repository) {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val backendLazy = lazy { GoBackend(context.applicationContext) }
    private val backend by backendLazy
    private val mutex = Mutex()
    private val _status = MutableStateFlow(TunnelStatus())
    val status: StateFlow<TunnelStatus> = _status.asStateFlow()
    private var statsJob: Job? = null

    // Сколько трафика текущего туннеля уже записано в AppData.traffic (счётчики backend'а растут с нуля).
    private var savedRx = 0L
    private var savedTx = 0L

    /** Дописать прирост трафика соединения в постоянную статистику (вкладка «Связи»). */
    private fun flushTraffic() {
        val s = _status.value
        val id = s.activeId ?: return
        val dRx = s.rx - savedRx; val dTx = s.tx - savedTx
        if (dRx <= 0 && dTx <= 0) return
        savedRx = s.rx; savedTx = s.tx
        repo.update { d ->
            val old = d.traffic[id] ?: app.vpsrouter.client.data.ConnTraffic()
            d.copy(traffic = d.traffic + (id to old.copy(rx = old.rx + maxOf(0, dRx), tx = old.tx + maxOf(0, dTx), lastUsed = System.currentTimeMillis())))
        }
    }

    private inner class AppTunnel(val profileId: String, val box: Boolean = false) : Tunnel {
        override fun getName() = "vpsr"
        override fun onStateChange(newState: Tunnel.State) {
            _status.value = _status.value.copy(
                state = newState,
                activeId = if (newState == Tunnel.State.UP) profileId else _status.value.activeId,
            )
            if (newState == Tunnel.State.DOWN) { statsJob?.cancel(); flushTraffic(); SpeedNotifier.hide(context) }
        }
    }

    private var current: AppTunnel? = null

    /** Версия AmneziaWG — только если ядро уже загружено (загружать его ради надписи нельзя). */
    fun backendVersion(): String = if (backendLazy.isInitialized()) runCatching { backend.version }.getOrDefault("?") else "загрузится при подключении"

    suspend fun toggle(profile: TunnelProfile) {
        if (_status.value.activeId == profile.id && _status.value.state == Tunnel.State.UP) stop() else start(profile)
    }

    suspend fun start(profile: TunnelProfile) = withContext(Dispatchers.IO) {
        mutex.withLock {
            _status.value = _status.value.copy(busy = true, error = null)
            try {
                val settings = repo.data.value.settings
                flushTraffic(); savedRx = 0; savedTx = 0
                val t = AppTunnel(profile.id, profile.isSingbox)
                if (profile.isSingbox) {
                    // VLESS+XHTTP ведёт ядро Xray: sing-box держит TUN и отдаёт трафик в его локальный вход.
                    val bridge = if (BoxConfig.isXray(profile.config)) BoxConfig.XrayBridge(
                        port = runCatching { io.nekohasekai.libbox.Libbox.availablePort(10808) }.getOrDefault(10808),
                        user = java.util.UUID.randomUUID().toString(), pass = java.util.UUID.randomUUID().toString(),
                    ) else null
                    val xcfg = bridge?.let { BoxConfig.xrayConfig(profile.config, it) }
                    val cfg = BoxConfig.build(profile.config, settings, xray = bridge, ownPackage = context.packageName)
                    BoxVpnService.check(context, cfg)
                    xcfg?.let { io.nekohasekai.xraylite.Xraylite.check(it) }
                    // Сначала гасим AmneziaWG, если он был активен.
                    current?.let { if (!it.box) runCatching { backend.setState(it, Tunnel.State.DOWN, null) } }
                    val svc = BoxVpnService.obtain(context)
                    svc.onStopped = { reason -> scope.launch { onBoxStopped(t, reason) } }
                    current = t
                    svc.startBox(cfg, xcfg)
                    boxBase = uidBytes()
                } else {
                    val cfg = parseConfig(ConfigTransform.apply(profile.config, settings))
                    current?.let {
                        if (it.box) BoxVpnService.instance?.let { s -> s.onStopped = null; s.stopBox() }
                        else if (it.profileId != profile.id) backend.setState(it, Tunnel.State.DOWN, null)
                    }
                    current = t
                    backend.setState(t, Tunnel.State.UP, cfg)
                }
                _status.value = TunnelStatus(activeId = profile.id, state = Tunnel.State.UP, connectedAt = System.currentTimeMillis())
                AppLog.i("Туннель «${profile.name}» подключён (${ConfigTransform.describe(settings)})")
                repo.update { it.copy(lastTunnelId = profile.id) }
                startStats(t)
            } catch (e: Exception) {
                if (profile.isSingbox) {
                    BoxVpnService.instance?.let { runCatching { it.onStopped = null; it.stopBox() } }
                    current = null
                    _status.value = TunnelStatus()
                }
                _status.value = _status.value.copy(busy = false, error = describe(e))
                AppLog.e("Не удалось подключить «${profile.name}»: ${describe(e)}")
            }
        }
    }

    suspend fun stop() = withContext(Dispatchers.IO) {
        mutex.withLock {
            val t = current ?: return@withLock
            flushTraffic(); savedRx = 0; savedTx = 0
            try {
                if (t.box) BoxVpnService.instance?.let { it.onStopped = null; it.stopBox() }
                else backend.setState(t, Tunnel.State.DOWN, null)
            } catch (e: Exception) {
                _status.value = _status.value.copy(error = describe(e))
            }
            statsJob?.cancel()
            current = null
            _status.value = TunnelStatus()
            SpeedNotifier.hide(context)
            AppLog.i("Туннель отключён")
        }
    }

    /** Перезапуск активного туннеля с новым конфигом (после правки профиля). */
    suspend fun restartIfActive(profile: TunnelProfile) {
        if (_status.value.activeId == profile.id && _status.value.state == Tunnel.State.UP) start(profile)
    }

    private fun startStats(t: AppTunnel) {
        statsJob?.cancel()
        statsJob = scope.launch {
            var tick = 0
            while (isActive) {
                runCatching {
                    val prev = _status.value
                    var hs = 0L
                    val rx: Long; val tx: Long
                    if (t.box) {
                        // У sing-box нет «рукопожатия»: соединение живо, пока приходит трафик.
                        val (r, w) = uidBytes()
                        rx = maxOf(0, r - boxBase.first); tx = maxOf(0, w - boxBase.second)
                        hs = if (rx > prev.rx || prev.lastHandshake == 0L) System.currentTimeMillis() else prev.lastHandshake
                    } else {
                        val st = backend.getStatistics(t)
                        for (k in st.peers()) {
                            st.peer(k)?.let { hs = maxOf(hs, it.latestHandshakeEpochMillis()) }
                        }
                        rx = st.totalRx(); tx = st.totalTx()
                    }
                    _status.value = prev.copy(
                        rx = rx, tx = tx, lastHandshake = hs, busy = false,
                        rxRate = if (prev.rx in 1..rx) rx - prev.rx else 0,
                        txRate = if (prev.tx in 1..tx) tx - prev.tx else 0,
                    )
                    if (++tick % 15 == 0) flushTraffic()
                    val cur = _status.value
                    if (repo.data.value.settings.speedInStatusBar) {
                        SpeedNotifier.update(context, cur.rxRate, cur.txRate, repo.tunnel(t.profileId)?.name ?: "VPS Router")
                    } else SpeedNotifier.hide(context)
                }
                delay(1000)
            }
        }
    }

    /** Ядро sing-box остановилось само или система отозвала VPN. */
    private suspend fun onBoxStopped(t: AppTunnel, reason: String?) = mutex.withLock {
        if (current !== t) return@withLock
        flushTraffic(); savedRx = 0; savedTx = 0
        statsJob?.cancel()
        current = null
        _status.value = TunnelStatus(error = reason)
        SpeedNotifier.hide(context)
    }

    // Трафик ядра sing-box: все его сокеты к серверу принадлежат нашему приложению.
    private var boxBase = 0L to 0L
    private fun uidBytes(): Pair<Long, Long> {
        val uid = android.os.Process.myUid()
        return maxOf(0L, android.net.TrafficStats.getUidRxBytes(uid)) to maxOf(0L, android.net.TrafficStats.getUidTxBytes(uid))
    }

    private fun describe(e: Exception): String = when (e) {
        is BackendException -> when (e.reason) {
            BackendException.Reason.VPN_NOT_AUTHORIZED -> "Нет разрешения на VPN — подтвердите запрос системы"
            BackendException.Reason.UNABLE_TO_START_VPN -> "Не удалось запустить VPN-службу"
            BackendException.Reason.TUN_CREATION_ERROR -> "Не удалось создать TUN-интерфейс (возможно, активен другой VPN)"
            BackendException.Reason.GO_ACTIVATION_ERROR_CODE -> "Ошибка запуска AmneziaWG (код ${e.format.joinToString()})"
            else -> "Ошибка туннеля: ${e.reason}"
        }
        else -> e.message ?: e.javaClass.simpleName
    }

    companion object {
        fun parseConfig(text: String): Config = Config.parse(BufferedReader(StringReader(text)))
    }
}
