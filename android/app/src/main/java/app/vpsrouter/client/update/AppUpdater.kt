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
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.withContext
import java.io.File
import java.security.MessageDigest

/**
 * Обновление приложения из панели VPS Router. Панель и приложение выпускаются из одного
 * репозитория: APK лежит в panel/app/ и приходит на сервер вместе с обновлением панели.
 * Приложение сверяет versionCode, скачивает APK через сессию панели, проверяет размер и
 * SHA-256 и отдаёт системному установщику. Android сам проверит, что подпись совпадает.
 */
class AppUpdater(private val context: Context) {
    data class State(
        val available: AppRelease? = null,
        val panelVersion: String? = null,
        val checkedAt: Long = 0,
        val progress: Float? = null,
        val error: String? = null,
    )

    private val _state = MutableStateFlow(State())
    val state: StateFlow<State> = _state.asStateFlow()

    val currentVersion: String get() = "${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})"

    fun onPanelInfo(info: PanelAppInfo) {
        val r = info.release
        val newer = r != null && r.versionCode > BuildConfig.VERSION_CODE && Build.VERSION.SDK_INT >= r.minSdk && r.sha256.length == 64
        _state.value = _state.value.copy(available = if (newer) r else null, panelVersion = info.panelVersion, checkedAt = System.currentTimeMillis(), error = null)
        if (newer) AppLog.i("Доступно обновление приложения: ${r!!.versionName} (${r.versionCode})")
    }

    private fun apkFile(r: AppRelease) = File(File(context.cacheDir, "updates").apply { mkdirs() }, "vps-router-${r.versionCode}.apk")

    private fun sha256(f: File): String {
        val md = MessageDigest.getInstance("SHA-256")
        f.inputStream().use { inp -> val buf = ByteArray(64 * 1024); while (true) { val n = inp.read(buf); if (n < 0) break; md.update(buf, 0, n) } }
        return md.digest().joinToString("") { "%02x".format(it) }
    }

    /** Скачать (если ещё нет) и проверить APK. Возвращает файл, готовый к установке. */
    suspend fun download(client: PanelClient): File = withContext(Dispatchers.IO) {
        val r = _state.value.available ?: throw IllegalStateException("Обновлений нет")
        val f = apkFile(r)
        if (f.exists() && f.length() == r.size && sha256(f) == r.sha256) return@withContext f
        // Старые скачанные версии больше не нужны
        f.parentFile?.listFiles()?.forEach { it.delete() }
        val tmp = File(f.path + ".part")
        try {
            _state.value = _state.value.copy(progress = 0f, error = null)
            client.downloadApk(tmp) { done, total ->
                val t = if (total > 0) total else r.size
                _state.value = _state.value.copy(progress = if (t > 0) (done.toFloat() / t).coerceIn(0f, 1f) else null)
            }
            if (r.size > 0 && tmp.length() != r.size) throw IllegalStateException("Размер APK не совпал (${tmp.length()} вместо ${r.size}) — загрузка оборвалась")
            val got = sha256(tmp)
            if (got != r.sha256) throw IllegalStateException("Контрольная сумма APK не совпала — файл повреждён или подменён")
            if (!tmp.renameTo(f)) throw IllegalStateException("Не удалось сохранить APK")
            AppLog.i("Обновление ${r.versionName} скачано, SHA-256 совпадает")
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
}
