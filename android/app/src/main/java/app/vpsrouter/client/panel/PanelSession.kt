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
 *
 * Телефон в панели — одно устройство («это устройство», [PanelProfile.myDeviceId]):
 * в туннели подтягиваются только его соединения (напрямую и через каждый ретранслятор).
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

    /** Панель подключена, но не выбрано «это устройство» — показать выбор. */
    private val _needsDevice = MutableStateFlow(false)
    val needsDevice: StateFlow<Boolean> = _needsDevice.asStateFlow()

    /** Только что вошли (по QR или паролем) — UI переводит на главный экран. */
    val justLoggedIn = MutableStateFlow(false)

    /** Итог последнего импорта — для сообщения в UI. */
    private val _importResult = MutableStateFlow<String?>(null)
    val importResult: StateFlow<String?> = _importResult.asStateFlow()
    fun clearImportResult() { _importResult.value = null }

    /**
     * Вход по QR панели: одноразовый код → собственный ключ приложения (пароль не нужен).
     * Устройство из QR (если выбрано в панели) сразу становится «этим устройством».
     */
    suspend fun pairAndConnect(p: PanelLink.Parsed): Boolean {
        val code = p.code ?: return false
        _state.value = PanelState.CONNECTING
        _error.value = null
        val insecure = p.insecure || Regex("""^https://[\d.]+""").containsMatchIn(p.url)
        val draft = PanelProfile(p.url, p.alt, p.user.orEmpty(), insecureTls = insecure)
        return try {
            val r = PanelClient(draft).pair(code, deviceLabel())
            val profile = draft.copy(username = r.username.ifBlank { draft.username }, appToken = r.token, myDeviceId = p.device ?: r.clientId)
            pruneOthers = true
            connect(profile)
            _state.value == PanelState.ONLINE
        } catch (e: Exception) {
            _error.value = e.message
            _state.value = if (repo.data.value.panel == null) PanelState.NOT_CONFIGURED else PanelState.OFFLINE
            AppLog.e("Привязка к панели: ${e.message}")
            false
        }
    }

    fun deviceLabel(): String {
        val man = android.os.Build.MANUFACTURER.orEmpty().replaceFirstChar { it.uppercase() }
        val model = android.os.Build.MODEL.orEmpty()
        return (if (model.startsWith(man, ignoreCase = true)) model else "$man $model").trim().ifBlank { "Android" }
    }

    suspend fun connect(profile: PanelProfile? = repo.data.value.panel) {
        if (profile == null) { _state.value = PanelState.NOT_CONFIGURED; return }
        val firstLogin = repo.data.value.panel == null
        _state.value = PanelState.CONNECTING
        _error.value = null
        val c = PanelClient(profile)
        client = c
        try {
            c.login()
            repo.update { it.copy(panel = profile) }
            _state.value = PanelState.ONLINE
            if (firstLogin) justLoggedIn.value = true
            AppLog.i("Панель: вход выполнен (${c.baseUrl})")
            refresh()
        } catch (e: TwoFactorRequired) {
            repo.update { it.copy(panel = profile) }
            _state.value = PanelState.NEED_2FA
        } catch (e: Exception) {
            _error.value = e.message
            _state.value = if (firstLogin) PanelState.NOT_CONFIGURED else PanelState.OFFLINE
            AppLog.e("Панель недоступна: ${e.message}")
        }
    }

    suspend fun submitTotp(code: String) {
        val c = client ?: return
        try {
            c.submitTotp(code)
            _state.value = PanelState.ONLINE
            justLoggedIn.value = true
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
            syncMyDevice(devices)
        } catch (e: PanelException) {
            if (e.code == 401) connect() else { _error.value = e.message; if (e.code == 0) _state.value = PanelState.OFFLINE }
        } catch (e: Exception) {
            _error.value = e.message
            _state.value = PanelState.OFFLINE
        }
    }

    /**
     * Подтянуть соединения «этого устройства» (напрямую + через каждый ретранслятор).
     * Чужие устройства панели в туннели не попадают.
     */
    private suspend fun syncMyDevice(devices: List<PanelDevice>) {
        val my = repo.data.value.panel?.myDeviceId
        val d = my?.let { id -> devices.firstOrNull { it.id == id } }
        if (d == null) { _needsDevice.value = true; return }
        _needsDevice.value = false
        if (pruneOthers) { pruneOthers = false; pruneOtherDevices(d.id) }
        val (ok, failed) = importDevice(d)
        _importResult.value = when {
            failed > 0 -> "Соединений обновлено: $ok, с ошибкой: $failed (см. журнал)"
            ok == 0 -> "У «${d.name}» нет AmneziaWG/WireGuard — включите протокол устройству в панели"
            else -> _importResult.value
        }
    }

    /** После привязки убрать из туннелей соединения других устройств панели (раньше импортировались все). */
    private var pruneOthers = false

    private fun pruneOtherDevices(myId: Int) {
        val active = app.vpsrouter.client.App.instance.tunnels.status.value.activeId
        repo.update { d ->
            d.copy(tunnels = d.tunnels.filterNot { t ->
                t.source == "panel" && t.id != active && t.origin?.split(':')?.getOrNull(1)?.toIntOrNull().let { it != null && it != myId }
            })
        }
    }

    /** Выбрать «это устройство» (id) или создать новое по имени. */
    suspend fun chooseDevice(id: Int?, newName: String? = null) {
        val c = client ?: throw PanelException("Панель не подключена")
        val devId = id ?: c.createDevice(newName?.ifBlank { null } ?: deviceLabel(), "amneziawg", "phone")?.id
            ?: throw PanelException("Не удалось создать устройство в панели")
        val p = repo.data.value.panel ?: return
        val np = p.copy(myDeviceId = devId)
        pruneOthers = true
        repo.update { it.copy(panel = np) }
        // Запомнить выбор и в панели (у ключа приложения) — для списка привязанных телефонов.
        if (p.appToken != null) runCatching { c.bindDevice(np) }
        _needsDevice.value = false
        refresh()
        val d = repo.data.value.panelCache.devices.firstOrNull { it.id == devId }
        if (d != null) _importResult.value = "Соединения «${d.name}» добавлены: ${tunnelFormats(d).size}"
    }

    /** Не привязывать устройство сейчас (можно выбрать позже в разделе панели). */
    fun skipDevice() { _needsDevice.value = false }

    /** Снова показать выбор «этого устройства». */
    fun askDevice() { _needsDevice.value = true }

    fun myDevice(): PanelDevice? {
        val id = repo.data.value.panel?.myDeviceId ?: return null
        return repo.data.value.panelCache.devices.firstOrNull { it.id == id }
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
        _needsDevice.value = false
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
        val via = if (format.contains('@')) relayName(format.substringAfter('@')) else null
        val name = deviceName + if (via != null) " ⇢ $via" else ""
        val t = (existing ?: TunnelProfile(name = name, config = conf, source = "panel", origin = origin, via = via)).copy(config = conf, via = via)
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

    fun relayName(connId: String): String {
        val cache = repo.data.value.panelCache
        val conn = cache.relays.firstOrNull { it.id.toString() == connId } ?: return "ретранслятор #$connId"
        return cache.servers.firstOrNull { it.id == conn.sourceId }?.name ?: "ретранслятор #$connId"
    }
}
