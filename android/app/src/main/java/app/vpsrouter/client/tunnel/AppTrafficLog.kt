package app.vpsrouter.client.tunnel

import android.content.Context
import app.vpsrouter.client.data.AppLog
import io.nekohasekai.libbox.CommandClient
import io.nekohasekai.libbox.CommandClientHandler
import io.nekohasekai.libbox.CommandClientOptions
import io.nekohasekai.libbox.ConnectionEvents
import io.nekohasekai.libbox.Libbox
import io.nekohasekai.libbox.LogIterator
import io.nekohasekai.libbox.OutboundGroupItemIterator
import io.nekohasekai.libbox.OutboundGroupIterator
import io.nekohasekai.libbox.StatusMessage
import io.nekohasekai.libbox.StringIterator
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
import kotlinx.serialization.Serializable
import kotlinx.serialization.builtins.ListSerializer
import kotlinx.serialization.json.Json
import java.io.File

/**
 * Журнал «Только РФ»: куда ходят отмеченные приложения и что с этим сделал VPN.
 * Собирается автоматически, пока работает ядро sing-box: подписка на соединения
 * через CommandClient, решение — по тегу outbound'а (см. RfOnly). Записи
 * сворачиваются по «приложение + адрес + решение», хранятся в файле.
 */
object AppTrafficLog {
    @Serializable
    data class Entry(
        val pkg: String,
        val host: String,
        val port: Int = 0,
        val ip: String = "",
        val verdict: String,
        val count: Int = 1,
        val first: Long,
        val last: Long,
    ) {
        val key get() = "$pkg|$host|$verdict"
    }

    private const val MAX = 3000
    private val json = Json { ignoreUnknownKeys = true }
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    private val _entries = MutableStateFlow<List<Entry>>(emptyList())
    val entries: StateFlow<List<Entry>> = _entries.asStateFlow()

    private val map = LinkedHashMap<String, Entry>()
    private val seen = LinkedHashSet<String>()
    private var file: File? = null
    private var dirty = false
    private var client: CommandClient? = null
    private var job: Job? = null

    /** Загрузить сохранённый журнал (для экрана, даже без запущенного VPN). */
    @Synchronized
    fun load(ctx: Context) {
        if (file != null) return
        val f = File(ctx.filesDir, "rfonly-log.json")
        file = f
        runCatching {
            if (f.exists()) json.decodeFromString(ListSerializer(Entry.serializer()), f.readText()).forEach { map[it.key] = it }
        }
        publish()
    }

    fun start(ctx: Context) {
        load(ctx)
        stop()
        job = scope.launch {
            val handler = Handler()
            val opts = CommandClientOptions().apply { addCommand(Libbox.CommandConnections); statusInterval = 1_000_000_000L }
            val c = Libbox.newCommandClient(handler, opts)
            // Сервер команд поднимается вместе с ядром — подключаемся с повторами.
            var ok = false
            for (i in 0 until 30) {
                if (!isActive) return@launch
                if (runCatching { c.connect() }.isSuccess) { ok = true; break }
                delay(300)
            }
            if (!ok) { AppLog.e("Журнал «Только РФ»: не удалось подключиться к ядру"); return@launch }
            client = c
            AppLog.i("Журнал «Только РФ»: сбор соединений включён")
            while (isActive) { delay(5000); save() }
        }
    }

    fun stop() {
        job?.cancel(); job = null
        client?.let { runCatching { it.disconnect() } }
        client = null
        save()
    }

    @Synchronized
    fun clear() {
        map.clear(); seen.clear(); dirty = true
        publish(); save()
    }

    /** Добавить соединение (вызывается из обработчика событий ядра; вынесено для тестов). */
    @Synchronized
    fun record(pkg: String, domain: String, destination: String, outboundTag: String, now: Long = System.currentTimeMillis()) {
        val verdict = RfOnly.Verdict.of(outboundTag) ?: return
        val ip = destination.substringBeforeLast(':').trim('[', ']')
        val port = destination.substringAfterLast(':', "").toIntOrNull() ?: 0
        val host = domain.ifBlank { ip }
        if (host.isBlank()) return
        val k = "$pkg|$host|${verdict.name}"
        val old = map.remove(k)
        map[k] = old?.copy(count = old.count + 1, last = now, ip = ip.ifBlank { old.ip }, port = port)
            ?: Entry(pkg, host, port, ip, verdict.name, 1, now, now)
        while (map.size > MAX) map.remove(map.keys.first())
        dirty = true
        publish()
    }

    /** Каждое соединение учитываем один раз (при сбросе ядро присылает все текущие заново). */
    @Synchronized
    private fun markSeen(id: String): Boolean {
        if (id.isEmpty() || !seen.add(id)) return false
        if (seen.size > 5000) seen.remove(seen.first())
        return true
    }

    private fun publish() { _entries.value = map.values.reversed() }

    @Synchronized
    private fun save() {
        val f = file ?: return
        if (!dirty) return
        dirty = false
        runCatching { f.writeText(json.encodeToString(ListSerializer(Entry.serializer()), map.values.toList())) }
    }

    private class Handler : CommandClientHandler {
        override fun writeConnectionEvents(events: ConnectionEvents) {
            val it = events.iterator()
            while (it.hasNext()) {
                val e = it.next()
                if (e.type.toLong() != Libbox.ConnectionEventNew) continue
                if (!markSeen(e.id.orEmpty())) continue
                val conn = e.connection ?: continue
                val tag = conn.outbound ?: continue
                if (tag !in RfOnly.TAGS) continue
                val pkgs = runCatching { conn.processInfo?.packageNames()?.toList() }.getOrNull().orEmpty()
                val pkg = pkgs.firstOrNull() ?: "?"
                record(pkg, conn.domain.orEmpty(), conn.destination.orEmpty(), tag)
            }
        }
        override fun clearLogs() {}
        override fun connected() {}
        override fun disconnected(message: String?) {}
        override fun initializeClashMode(modes: StringIterator?, current: String?) {}
        override fun setDefaultLogLevel(level: Int) {}
        override fun updateClashMode(mode: String?) {}
        override fun writeGroups(groups: OutboundGroupIterator?) {}
        override fun writeLogs(logs: LogIterator?) {}
        override fun writeOutbounds(outbounds: OutboundGroupItemIterator?) {}
        override fun writeStatus(message: StatusMessage?) {}

        private fun StringIterator.toList(): List<String> { val r = mutableListOf<String>(); while (hasNext()) r += next(); return r }
    }
}
