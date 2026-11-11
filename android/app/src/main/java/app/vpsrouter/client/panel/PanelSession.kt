package app.vpsrouter.client.panel

import app.vpsrouter.client.data.AppLog
import app.vpsrouter.client.data.PanelCache
import app.vpsrouter.client.data.PanelDevice
import app.vpsrouter.client.data.PanelProfile
import app.vpsrouter.client.data.Repository
import app.vpsrouter.client.data.TunnelProfile
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

enum class PanelState { NOT_CONFIGURED, OFFLINE, NEED_2FA, CONNECTING, ONLINE }

/**
 * Сессия с панелью VPS Router. Если панель недоступна — состояние OFFLINE, а данные
 * берутся из кэша; приложение при этом полностью работает в автономном режиме.
 */
class PanelSession(private val repo: Repository) {
    private val _state = MutableStateFlow(if (repo.data.value.panel == null) PanelState.NOT_CONFIGURED else PanelState.OFFLINE)
    val state: StateFlow<PanelState> = _state.asStateFlow()
    private val _error = MutableStateFlow<String?>(null)
    val error: StateFlow<String?> = _error.asStateFlow()

    var client: PanelClient? = null
        private set

    val activeUrl: String? get() = client?.baseUrl

    /** Данные из отсканированного QR панели — подставляются в форму входа. */
    val prefill = MutableStateFlow<PanelLink.Parsed?>(null)

    /** После успешного входа импортировать конфиги всех устройств. */
    private var importAllAfterLogin = false

    /** Итог последнего массового импорта — для сообщения в UI. */
    private val _importResult = MutableStateFlow<String?>(null)
    val importResult: StateFlow<String?> = _importResult.asStateFlow()
    fun clearImportResult() { _importResult.value = null }

    suspend fun connect(profile: PanelProfile? = repo.data.value.panel, importAll: Boolean = false) {
        if (importAll) importAllAfterLogin = true
        if (profile == null) { _state.value = PanelState.NOT_CONFIGURED; return }
        _state.value = PanelState.CONNECTING
        _error.value = null
        val c = PanelClient(profile)
        client = c
        try {
            c.login()
            repo.update { it.copy(panel = profile) }
            _state.value = PanelState.ONLINE
            AppLog.i("Панель: вход выполнен (${c.baseUrl})")
            refresh()
        } catch (e: TwoFactorRequired) {
            repo.update { it.copy(panel = profile) }
            _state.value = PanelState.NEED_2FA
        } catch (e: Exception) {
            _error.value = e.message
            _state.value = PanelState.OFFLINE
            AppLog.e("Панель недоступна: ${e.message}")
        }
    }

    suspend fun submitTotp(code: String) {
        val c = client ?: return
        try {
            c.submitTotp(code)
            _state.value = PanelState.ONLINE
            refresh()
        } catch (e: TwoFactorRequired) {
            _error.value = "Неверный код 2FA"
        } catch (e: Exception) {
            _error.value = e.message
        }
    }

    /** Обновить кэш серверов/устройств/ретрансляторов. При 401 — переподключиться. */
    suspend fun refresh() {
        val c = client ?: return
        try {
            val servers = c.servers()
            val devices = c.devices()
            val relays = runCatching { c.relays() }.getOrDefault(emptyList())
            val routes = runCatching { c.routes() }.getOrDefault(repo.data.value.panelCache.routes)
            repo.update { it.copy(panelCache = PanelCache(System.currentTimeMillis(), servers, devices, relays, routes)) }
            _error.value = null
            checkApp(c)
            if (importAllAfterLogin) {
                importAllAfterLogin = false
                val (ok, failed) = importAll()
                _importResult.value = when {
                    ok == 0 && failed == 0 -> "В панели нет устройств с AmneziaWG/WireGuard — создайте устройство на вкладке «Устройства»."
                    failed == 0 -> "Импортировано туннелей: $ok"
                    else -> "Импортировано туннелей: $ok, с ошибкой: $failed (подробности в журнале)"
                }
            }
        } catch (e: PanelException) {
            if (e.code == 401) connect() else { _error.value = e.message; if (e.code == 0) _state.value = PanelState.OFFLINE }
        } catch (e: Exception) {
            _error.value = e.message
            _state.value = PanelState.OFFLINE
        }
    }

    /** Есть ли в панели новая версия приложения; подтянуть свежие скрипты установки. */
    suspend fun checkApp(c: PanelClient? = client) {
        c ?: return
        try {
            val info = c.appInfo()
            val a = app.vpsrouter.client.App.instance
            a.updater.onPanelInfo(info)
            app.vpsrouter.client.update.ScriptStore.sync(a, c, info)
        } catch (e: PanelException) {
            // Панель старой версии без /api/app.php — просто нет обновлений через неё.
            if (e.code == 404) AppLog.i("Панель не раздаёт приложение (старая версия панели)") else AppLog.e("Проверка обновления: ${e.message}")
        } catch (e: Exception) {
            AppLog.e("Проверка обновления: ${e.message}")
        }
    }

    fun forget() {
        client = null
        repo.update { it.copy(panel = null, panelCache = PanelCache()) }
        _state.value = PanelState.NOT_CONFIGURED
    }

    fun setError(msg: String?) { _error.value = msg }

    /** Скачать конфиг устройства и сохранить как туннель (или обновить существующий). */
    suspend fun importDeviceTunnel(deviceId: Int, deviceName: String, format: String): TunnelProfile {
        val c = client ?: throw PanelException("Панель не подключена")
        val conf = c.deviceConfig(deviceId, format)
        app.vpsrouter.client.tunnel.TunnelManager.parseConfig(conf) // валидация
        val origin = "panel:$deviceId:$format"
        val existing = repo.data.value.tunnels.firstOrNull { it.origin == origin }
        val name = deviceName + if (format.contains('@')) " ⇢ " + relayName(format.substringAfter('@')) else ""
        val t = (existing ?: TunnelProfile(name = name, config = conf, source = "panel", origin = origin,
            via = if (format.contains('@')) relayName(format.substringAfter('@')) else null)).copy(config = conf)
        repo.upsertTunnel(t)
        return t
    }

    /** Форматы устройства, которые можно добавить как туннель (AWG/WG, включая варианты через ретрансляторы). */
    fun tunnelFormats(d: PanelDevice): List<String> = if (d.revoked) emptyList() else d.formats.filter { PanelClient.isTunnelFormat(it) }

    /** Уже добавлен ли этот конфиг устройства в туннели. */
    fun isImported(deviceId: Int, format: String) = repo.data.value.tunnels.any { it.origin == "panel:$deviceId:$format" }

    /** Добавить все туннельные конфиги одного устройства. Возвращает (успешно, ошибок). */
    suspend fun importDevice(d: PanelDevice): Pair<Int, Int> {
        var ok = 0; var failed = 0
        tunnelFormats(d).forEach { f ->
            try { importDeviceTunnel(d.id, d.name, f); ok++ } catch (e: Exception) {
                failed++; AppLog.e("Импорт ${d.name} ($f): ${e.message}")
            }
        }
        return ok to failed
    }

    /** Подтянуть конфиги всех устройств панели. */
    suspend fun importAll(): Pair<Int, Int> {
        var ok = 0; var failed = 0
        repo.data.value.panelCache.devices.forEach { d -> importDevice(d).let { ok += it.first; failed += it.second } }
        AppLog.i("Панель: импортировано туннелей $ok, ошибок $failed")
        return ok to failed
    }

    private fun relayName(connId: String): String {
        val cache = repo.data.value.panelCache
        val conn = cache.relays.firstOrNull { it.id.toString() == connId } ?: return "ретранслятор #$connId"
        return cache.servers.firstOrNull { it.id == conn.sourceId }?.name ?: "ретранслятор #$connId"
    }
}
