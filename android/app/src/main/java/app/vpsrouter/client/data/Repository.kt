package app.vpsrouter.client.data

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.serialization.json.Json

/** Всё состояние приложения — один зашифрованный JSON (данных немного, так проще и надёжнее). */
class Repository(private val store: SecureStore) {
    private val json = Json { ignoreUnknownKeys = true; encodeDefaults = true }
    private val _data = MutableStateFlow(load())
    val data: StateFlow<AppData> = _data.asStateFlow()

    private fun load(): AppData = store.read(FILE)?.let {
        runCatching { json.decodeFromString(AppData.serializer(), it) }.getOrNull()
    } ?: AppData()

    @Synchronized
    fun update(fn: (AppData) -> AppData) {
        val next = fn(_data.value)
        _data.value = next
        store.write(FILE, json.encodeToString(AppData.serializer(), next))
    }

    /** Резервная копия: всё состояние, зашифрованное паролем (PBKDF2 + AES-GCM). */
    fun exportBackup(password: String): String = Backup.encrypt(json.encodeToString(AppData.serializer(), data.value), password)

    /** Восстановить из копии. Бросает исключение при неверном пароле или повреждённом файле. */
    fun importBackup(text: String, password: String) {
        val restored = json.decodeFromString(AppData.serializer(), Backup.decrypt(text, password))
        update { restored.copy(settings = restored.settings.copy(onboarded = true)) }
    }

    fun tunnel(id: String) = data.value.tunnels.firstOrNull { it.id == id }
    fun server(id: String) = data.value.servers.firstOrNull { it.id == id }

    fun upsertTunnel(t: TunnelProfile) = update { d ->
        d.copy(tunnels = if (d.tunnels.any { it.id == t.id }) d.tunnels.map { if (it.id == t.id) t else it } else d.tunnels + t)
    }

    fun deleteTunnel(id: String) = update { d -> d.copy(tunnels = d.tunnels.filterNot { it.id == id }) }

    fun upsertServer(s: ManagedServer) = update { d ->
        d.copy(servers = if (d.servers.any { it.id == s.id }) d.servers.map { if (it.id == s.id) s else it } else d.servers + s)
    }

    fun updateServer(id: String, fn: (ManagedServer) -> ManagedServer) = update { d ->
        d.copy(servers = d.servers.map { if (it.id == id) fn(it) else it })
    }

    fun deleteServer(id: String) = update { d -> d.copy(servers = d.servers.filterNot { it.id == id }) }

    private companion object {
        const val FILE = "state.json"
    }
}
