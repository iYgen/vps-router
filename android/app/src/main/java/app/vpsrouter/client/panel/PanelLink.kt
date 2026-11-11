package app.vpsrouter.client.panel

import android.net.Uri

/**
 * Ссылка подключения панели из QR:
 *   vpsrouter://panel?url=https://p.example.com&user=admin&code=<одноразовый код>[&device=12][&alt=…][&insecure=1]
 * Код выдаёт панель (страница «Приложение» или «QR для другого устройства» в приложении):
 * приложение обменивает его на собственный ключ доступа — пароль не нужен.
 * Без кода (или голый адрес панели) — форма входа с подставленным адресом.
 */
object PanelLink {
    data class Parsed(
        val url: String,
        val user: String?,
        val alt: List<String>,
        val insecure: Boolean,
        val code: String? = null,
        val device: Int? = null,
    )

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
                code = u.getQueryParameter("code")?.takeIf { it.isNotBlank() },
                device = u.getQueryParameter("device")?.toIntOrNull(),
            )
        }
        // Голый адрес панели (одна строка, http/https, без конфигов туннеля)
        if (Regex("""^https?://[^\s/]+(/[^\s]*)?$""", RegexOption.IGNORE_CASE).matches(t)) {
            return Parsed(t, null, emptyList(), false)
        }
        return null
    }
}
