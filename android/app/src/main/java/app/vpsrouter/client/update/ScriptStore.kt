package app.vpsrouter.client.update

import android.content.Context
import app.vpsrouter.client.BuildConfig
import app.vpsrouter.client.data.AppLog
import app.vpsrouter.client.panel.PanelAppInfo
import app.vpsrouter.client.panel.PanelClient
import java.io.File
import java.security.MessageDigest

/**
 * Скрипты установки серверов. Встроенные в APK берутся из deploy/provision при сборке;
 * если подключена панель той же или более новой версии, приложение подтягивает из неё
 * свежие _lib.sh / relay-forward.sh — исправления в скриптах доходят до телефона без
 * новой версии APK. Панель старее приложения не используется (её скрипты могут не знать
 * того, на что рассчитывает приложение).
 */
object ScriptStore {
    /** Какие скрипты приложение берёт из панели (остальные — только встроенные). */
    private val SYNCED = setOf("_lib.sh", "relay-forward.sh")

    private fun dir(ctx: Context) = File(ctx.filesDir, "provision")

    private fun sha256(b: ByteArray) = MessageDigest.getInstance("SHA-256").digest(b).joinToString("") { "%02x".format(it) }

    private fun bundled(ctx: Context, name: String): String = ctx.assets.open("provision/$name").bufferedReader().use { it.readText() }

    /** Актуальный текст скрипта: из панели, если синхронизирован, иначе встроенный. */
    fun read(ctx: Context, name: String): String =
        File(dir(ctx), name).takeIf { name in SYNCED && it.isFile }?.readText() ?: bundled(ctx, name)

    /** Откуда сейчас берутся скрипты — для экрана настроек. */
    fun source(ctx: Context): String {
        val synced = SYNCED.filter { File(dir(ctx), it).isFile }
        return if (synced.isEmpty()) "встроенные в приложение" else "из панели: " + synced.joinToString()
    }

    fun reset(ctx: Context) { dir(ctx).listFiles()?.forEach { it.delete() } }

    /** Сверить хэши с панелью и скачать изменившиеся. Возвращает число обновлённых скриптов. */
    suspend fun sync(ctx: Context, client: PanelClient, info: PanelAppInfo): Int {
        val panelApp = info.release?.versionCode ?: 0
        if (panelApp < BuildConfig.VERSION_CODE) {
            // Панель старее приложения — работаем на встроенных скриптах.
            reset(ctx)
            return 0
        }
        var n = 0
        dir(ctx).mkdirs()
        for ((name, sha) in info.scripts) {
            if (name !in SYNCED || sha.length != 64) continue
            if (sha256(read(ctx, name).toByteArray()) == sha) continue
            val text = client.script(name)
            if (sha256(text.toByteArray()) != sha) { AppLog.e("Скрипт $name из панели не совпал с хэшем — пропущен"); continue }
            val f = File(dir(ctx), name)
            val tmp = File(f.path + ".tmp")
            tmp.writeText(text)
            if (tmp.renameTo(f)) { n++; AppLog.i("Скрипт $name обновлён из панели") } else tmp.delete()
        }
        return n
    }
}
