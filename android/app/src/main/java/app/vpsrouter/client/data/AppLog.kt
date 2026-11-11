package app.vpsrouter.client.data

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/** Журнал событий приложения (в памяти, последние 500 записей): подключения, ошибки, операции с серверами. */
object AppLog {
    data class Entry(val time: Long, val level: Char, val text: String) {
        override fun toString() = SimpleDateFormat("HH:mm:ss", Locale.ROOT).format(Date(time)) + " $level " + text
    }

    private val _entries = MutableStateFlow<List<Entry>>(emptyList())
    val entries: StateFlow<List<Entry>> = _entries.asStateFlow()

    @Synchronized
    private fun add(level: Char, text: String) {
        _entries.value = (_entries.value + Entry(System.currentTimeMillis(), level, text)).takeLast(500)
        android.util.Log.println(if (level == 'E') android.util.Log.ERROR else android.util.Log.INFO, "VpsRouter", text)
    }

    fun i(text: String) = add('I', text)
    fun e(text: String) = add('E', text)
    fun clear() { _entries.value = emptyList() }
}
