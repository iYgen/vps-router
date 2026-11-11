package app.vpsrouter.client.panel

import android.net.Uri
import app.vpsrouter.client.data.PanelProfile

/**
 * Ссылка подключения панели для QR-кода:
 *   vpsrouter://panel?url=https://p.example.com&user=admin[&alt=...&alt=...][&insecure=1][&pass=...]
 * Пароль кладётся только если пользователь явно согласился. Также принимается просто адрес
 * панели (https://… или http://IP) — тогда остальные поля заполняются вручную.
 */
object PanelLink {
    data class Parsed(val url: String, val user: String?, val alt: List<String>, val insecure: Boolean, val password: String?)

    fun encode(p: PanelProfile, withPassword: Boolean): String {
        val b = Uri.Builder().scheme("vpsrouter").authority("panel")
            .appendQueryParameter("url", p.url)
            .appendQueryParameter("user", p.username)
        p.altUrls.forEach { b.appendQueryParameter("alt", it) }
        if (p.insecureTls) b.appendQueryParameter("insecure", "1")
        if (withPassword) b.appendQueryParameter("pass", p.password)
        return b.build().toString()
    }

    fun parse(text: String?): Parsed? {
        val t = text?.trim() ?: return null
        if (t.startsWith("vpsrouter://panel", ignoreCase = true)) {
            val u = runCatching { Uri.parse(t) }.getOrNull() ?: return null
            val url = u.getQueryParameter("url")?.takeIf { it.isNotBlank() } ?: return null
            return Parsed(
                url = url,
                user = u.getQueryParameter("user"),
                alt = u.getQueryParameters("alt").filter { it.isNotBlank() },
                insecure = u.getQueryParameter("insecure") == "1",
                password = u.getQueryParameter("pass"),
            )
        }
        // Голый адрес панели (одна строка, http/https, без конфигов туннеля)
        if (Regex("""^https?://[^\s/]+(/[^\s]*)?$""", RegexOption.IGNORE_CASE).matches(t)) {
            return Parsed(t, null, emptyList(), false, null)
        }
        return null
    }
}
