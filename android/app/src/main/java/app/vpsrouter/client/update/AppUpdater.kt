package app.vpsrouter.client.update

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.core.content.FileProvider
import app.vpsrouter.client.BuildConfig
import app.vpsrouter.client.data.AppLog
import app.vpsrouter.client.panel.AppRelease
import app.vpsrouter.client.panel.PanelAppInfo
import app.vpsrouter.client.panel.PanelClient
import app.vpsrouter.client.panel.PanelException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.longOrNull
import okhttp3.OkHttpClient
import okhttp3.Request
import java.io.File
import java.io.IOException
import java.net.SocketTimeoutException
import java.net.UnknownHostException
import java.security.MessageDigest
import java.util.concurrent.TimeUnit
import javax.net.ssl.SSLException

/**
 * Обновление приложения из двух независимых источников:
 *  - GitHub — репозиторий проекта (panel/app/version.json и vps-router.apk), работает без панели;
 *  - панель VPS Router — тот же APK, приходит на сервер вместе с обновлением панели.
 * Берётся самая новая версия. APK проверяется по размеру и SHA-256, установку подтверждает
 * пользователь; Android сам проверит, что подпись совпадает с установленной.
 */
class AppUpdater(private val context: Context) {
    enum class Source(val label: String) { GITHUB("GitHub"), PANEL("панель VPS Router") }

    data class State(
        val available: AppRelease? = null,
        val source: Source? = null,
        val panelVersion: String? = null,
        val checkedAt: Long = 0,
        val progress: Float? = null,
        val error: String? = null,
        /** Итоги последней проверки по каждому источнику: «GitHub: 0.4.0», «Панель: не подключена». */
        val notes: List<String> = emptyList(),
    )

    private val _state = MutableStateFlow(State())
    val state: StateFlow<State> = _state.asStateFlow()

    val currentVersion: String get() = "${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})"

    private val http by lazy {
        OkHttpClient.Builder()
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(60, TimeUnit.SECONDS)
            .followRedirects(true)
            .build()
    }

    private var githubRelease: AppRelease? = null
    private var panelRelease: AppRelease? = null

    private fun usable(r: AppRelease?): Boolean =
        r != null && r.versionCode > BuildConfig.VERSION_CODE && Build.VERSION.SDK_INT >= r.minSdk && r.sha256.length == 64

    /** Выбрать лучший источник: новее версия; при равной — GitHub (не зависит от сессии панели). */
    private fun pick() {
        val g = githubRelease?.takeIf { usable(it) }
        val p = panelRelease?.takeIf { usable(it) }
        val (r, src) = when {
            g != null && (p == null || g.versionCode >= p.versionCode) -> g to Source.GITHUB
            p != null -> p to Source.PANEL
            else -> null to null
        }
        val was = _state.value.available?.versionCode
        _state.value = _state.value.copy(available = r, source = src, checkedAt = System.currentTimeMillis())
        if (r != null && was != r.versionCode) AppLog.i("Доступно обновление приложения: ${r.versionName} (${r.versionCode}) — ${src!!.label}")
    }

    fun onPanelInfo(info: PanelAppInfo) {
        panelRelease = info.release
        _state.value = _state.value.copy(panelVersion = info.panelVersion, error = null)
        pick()
    }

    /** Версия в GitHub. Возвращает строку-итог для пользователя. */
    suspend fun checkGithub(): String = withContext(Dispatchers.IO) {
        try {
            val r = fetchGithubRelease()
            githubRelease = r
            pick()
            "GitHub: опубликована ${r.versionName} (${r.versionCode})"
        } catch (e: Exception) {
            val why = reason(e)
            AppLog.e("Обновление из GitHub: $why")
            "GitHub: $why"
        }
    }

    /** Проверить в фоне, если давно не проверяли (раз в 6 часов). */
    suspend fun checkGithubIfStale() {
        if (System.currentTimeMillis() - lastGithubCheck < 6 * 3600_000L) return
        lastGithubCheck = System.currentTimeMillis()
        checkGithub()
    }
    private var lastGithubCheck = 0L

    /** Кнопка «Проверить»: оба источника, с понятными причинами, если что-то не получилось. */
    suspend fun checkAll(panel: PanelClient?, panelOnline: Boolean, checkPanel: suspend (PanelClient) -> Unit): List<String> {
        val notes = mutableListOf<String>()
        lastGithubCheck = System.currentTimeMillis()
        notes += checkGithub()
        notes += when {
            panel == null -> "Панель: не подключена"
            !panelOnline -> "Панель: нет связи — проверьте адрес и интернет"
            else -> {
                checkPanel(panel)
                val r = panelRelease
                val pv = _state.value.panelVersion?.let { " (панель $it)" }.orEmpty()
                if (r == null) "Панель: APK приложения на сервере нет — обновите панель"
                else "Панель: опубликована ${r.versionName} (${r.versionCode})$pv" +
                    if (r.versionCode < BuildConfig.VERSION_CODE) " — панель устарела, обновите её" else ""
            }
        }
        _state.value = _state.value.copy(notes = notes)
        pick()
        return notes
    }

    private fun fetchGithubRelease(): AppRelease {
        val req = Request.Builder().url(GITHUB_RAW + "version.json?t=" + System.currentTimeMillis())
            .header("Cache-Control", "no-cache").build()
        http.newCall(req).execute().use { r ->
            if (r.code == 404) throw IOException("файл версии не найден в репозитории")
            if (!r.isSuccessful) throw IOException("HTTP ${r.code}")
            val o = Json.parseToJsonElement(r.body?.string().orEmpty()).jsonObject
            return AppRelease(
                versionCode = o["versionCode"]?.jsonPrimitive?.intOrNull ?: throw IOException("в version.json нет versionCode"),
                versionName = o["versionName"]?.jsonPrimitive?.content.orEmpty(),
                size = o["size"]?.jsonPrimitive?.longOrNull ?: 0,
                sha256 = o["sha256"]?.jsonPrimitive?.content.orEmpty().lowercase(),
                minSdk = o["minSdk"]?.jsonPrimitive?.intOrNull ?: 24,
            )
        }
    }

    private fun reason(e: Exception): String = when (e) {
        is UnknownHostException -> "нет интернета или GitHub недоступен (DNS)"
        is SocketTimeoutException -> "GitHub не отвечает (возможно, заблокирован — включите VPN и повторите)"
        is SSLException -> "ошибка защищённого соединения с GitHub (${e.message})"
        is PanelException -> e.message ?: "ошибка панели"
        else -> e.message ?: e.javaClass.simpleName
    }

    private fun apkFile(r: AppRelease) = File(File(context.cacheDir, "updates").apply { mkdirs() }, "vps-router-${r.versionCode}.apk")

    private fun sha256(f: File): String {
        val md = MessageDigest.getInstance("SHA-256")
        f.inputStream().use { inp -> val buf = ByteArray(64 * 1024); while (true) { val n = inp.read(buf); if (n < 0) break; md.update(buf, 0, n) } }
        return md.digest().joinToString("") { "%02x".format(it) }
    }

    private fun downloadGithub(dest: File, onProgress: (Long, Long) -> Unit) {
        val req = Request.Builder().url(GITHUB_RAW + "vps-router.apk").build()
        http.newCall(req).execute().use { r ->
            if (!r.isSuccessful) throw IOException("GitHub не отдал APK: HTTP ${r.code}")
            val body = r.body ?: throw IOException("Пустой ответ GitHub")
            val total = body.contentLength()
            dest.outputStream().use { out ->
                body.byteStream().use { inp ->
                    val buf = ByteArray(64 * 1024)
                    var done = 0L
                    while (true) {
                        val n = inp.read(buf); if (n < 0) break
                        out.write(buf, 0, n); done += n
                        onProgress(done, total)
                    }
                }
            }
        }
    }

    /** Скачать (если ещё нет) и проверить APK. Возвращает файл, готовый к установке. */
    suspend fun download(client: PanelClient?): File = withContext(Dispatchers.IO) {
        val r = _state.value.available ?: throw IllegalStateException("Обновлений нет")
        val src = _state.value.source ?: Source.GITHUB
        val f = apkFile(r)
        if (f.exists() && f.length() == r.size && sha256(f) == r.sha256) return@withContext f
        // Старые скачанные версии больше не нужны
        f.parentFile?.listFiles()?.forEach { it.delete() }
        val tmp = File(f.path + ".part")
        try {
            _state.value = _state.value.copy(progress = 0f, error = null)
            val progress = { done: Long, total: Long ->
                val t = if (total > 0) total else r.size
                _state.value = _state.value.copy(progress = if (t > 0) (done.toFloat() / t).coerceIn(0f, 1f) else null)
            }
            when (src) {
                Source.GITHUB -> try { downloadGithub(tmp, progress) } catch (e: IOException) { throw IOException(reason(e), e) }
                Source.PANEL -> (client ?: throw IllegalStateException("Панель недоступна — обновление из неё не скачать")).downloadApk(tmp, progress)
            }
            if (r.size > 0 && tmp.length() != r.size) throw IllegalStateException("Размер APK не совпал (${tmp.length()} вместо ${r.size}) — загрузка оборвалась")
            val got = sha256(tmp)
            if (got != r.sha256) throw IllegalStateException("Контрольная сумма APK не совпала — файл повреждён или подменён")
            if (!tmp.renameTo(f)) throw IllegalStateException("Не удалось сохранить APK")
            AppLog.i("Обновление ${r.versionName} скачано (${src.label}), SHA-256 совпадает")
            f
        } catch (e: Exception) {
            tmp.delete()
            _state.value = _state.value.copy(error = e.message)
            AppLog.e("Обновление: ${e.message}")
            throw e
        } finally {
            _state.value = _state.value.copy(progress = null)
        }
    }

    /** Нужно ли сначала разрешить установку из этого приложения (Android 8+). */
    fun needsInstallPermission(): Boolean =
        Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && !context.packageManager.canRequestPackageInstalls()

    fun installPermissionIntent(): Intent =
        Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:${context.packageName}"))

    fun installIntent(apk: File): Intent {
        val uri = FileProvider.getUriForFile(context, "${context.packageName}.updates", apk)
        return Intent(Intent.ACTION_VIEW)
            .setDataAndType(uri, "application/vnd.android.package-archive")
            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
    }

    companion object {
        /** Каталог с APK в репозитории проекта (ветка main). */
        val GITHUB_RAW: String = BuildConfig.UPDATE_RAW_URL
    }
}
