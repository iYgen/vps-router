package app.vpsrouter.client.tunnel

import app.vpsrouter.client.data.AppSettings
import app.vpsrouter.client.data.RouteMode

/**
 * Профиль маршрутизации на устройстве накладывается на конфиг в момент подключения,
 * сам сохранённый профиль туннеля не меняется:
 *  - исключить приложения / только выбранные приложения (Excluded/IncludedApplications);
 *  - локальная сеть мимо VPN (AllowedIPs без частных диапазонов);
 *  - свои DNS-серверы.
 */
object ConfigTransform {
    /** 0.0.0.0/0 без 10/8, 172.16/12, 192.168/16, 169.254/16, 127/8 (тот же набор, что в WireGuard для Android). */
    const val PUBLIC_V4 = "0.0.0.0/5, 8.0.0.0/7, 11.0.0.0/8, 12.0.0.0/6, 16.0.0.0/4, 32.0.0.0/3, 64.0.0.0/2, 128.0.0.0/3, " +
        "160.0.0.0/5, 168.0.0.0/6, 172.0.0.0/12, 172.32.0.0/11, 172.64.0.0/10, 172.128.0.0/9, 173.0.0.0/8, 174.0.0.0/7, " +
        "176.0.0.0/4, 192.0.0.0/9, 192.128.0.0/11, 192.160.0.0/13, 192.169.0.0/16, 192.170.0.0/15, 192.172.0.0/14, " +
        "192.176.0.0/12, 192.192.0.0/10, 193.0.0.0/8, 194.0.0.0/7, 196.0.0.0/6, 200.0.0.0/5, 208.0.0.0/4"

    private fun key(line: String) = line.substringBefore('=').trim().lowercase()

    fun apply(config: String, s: AppSettings): String {
        val out = mutableListOf<String>()
        var section = ""
        var interfaceEnd = -1
        for (raw in config.lines()) {
            val line = raw.trimEnd()
            val t = line.trim()
            if (t.startsWith("[")) {
                if (section == "interface") interfaceEnd = out.size
                section = t.removePrefix("[").removeSuffix("]").lowercase()
                out += line; continue
            }
            if (section == "interface" && t.contains('=')) {
                when (key(t)) {
                    "dns" -> if (s.dnsOverride.isNotBlank()) continue
                    "excludedapplications", "includedapplications" -> if (s.routeMode != RouteMode.ALL) continue
                }
            }
            if (section == "peer" && t.contains('=') && key(t) == "allowedips" && s.bypassLan) {
                val nets = t.substringAfter('=').split(',').map { it.trim() }
                if ("0.0.0.0/0" in nets) {
                    out += "AllowedIPs = " + (nets.filter { it != "0.0.0.0/0" } + PUBLIC_V4).joinToString(", ")
                    continue
                }
            }
            out += line
        }
        if (section == "interface") interfaceEnd = out.size
        val extra = mutableListOf<String>()
        if (s.dnsOverride.isNotBlank()) extra += "DNS = ${s.dnsOverride.trim()}"
        if (s.apps.isNotEmpty()) when (s.routeMode) {
            RouteMode.EXCLUDE_APPS -> extra += "ExcludedApplications = ${s.apps.joinToString(", ")}"
            RouteMode.ONLY_APPS -> extra += "IncludedApplications = ${s.apps.joinToString(", ")}"
            RouteMode.ALL -> {}
        }
        if (extra.isNotEmpty() && interfaceEnd >= 0) {
            // Вставляем в конец секции [Interface] (перед пустыми строками, отделяющими [Peer]).
            var at = interfaceEnd
            while (at > 0 && out[at - 1].isBlank()) at--
            out.addAll(at, extra)
        }
        return out.joinToString("\n")
    }

    fun describe(s: AppSettings): String = buildString {
        append(
            when (s.routeMode) {
                RouteMode.ALL -> "Весь трафик через VPN"
                RouteMode.EXCLUDE_APPS -> if (s.apps.isEmpty()) "Весь трафик через VPN" else "Всё, кроме ${s.apps.size} прил."
                RouteMode.ONLY_APPS -> if (s.apps.isEmpty()) "Весь трафик через VPN" else "Только ${s.apps.size} прил."
            }
        )
        if (s.bypassLan) append(" · без локальной сети")
    }
}
