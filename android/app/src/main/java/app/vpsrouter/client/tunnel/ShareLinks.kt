package app.vpsrouter.client.tunnel

import kotlin.io.encoding.Base64
import kotlin.io.encoding.ExperimentalEncodingApi
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import kotlinx.serialization.json.putJsonArray
import kotlinx.serialization.json.putJsonObject
import java.net.URI
import java.net.URLDecoder

/**
 * Ссылки подключения, как в v2rayNG/v2rayTun/Hiddify: vless://, vmess://, trojan://, ss://,
 * hysteria2:// (hy2://), tuic://. Превращаются в outbound для ядра: sing-box, а VLESS+XHTTP — Xray.
 */
object ShareLinks {
    data class Parsed(val name: String, val outbound: String, val protocol: String)

    private val json = Json { prettyPrint = false }
    private val schemes = Regex("""^(vless|vmess|trojan|ss|hysteria2|hy2|tuic)://""", RegexOption.IGNORE_CASE)

    fun isLink(s: String) = schemes.containsMatchIn(s.trim())

    /** Все ссылки из текста (подписка, несколько строк, base64-подписка). */
    fun findAll(text: String): List<String> {
        val t = text.trim()
        val plain = t.lines().map { it.trim() }.filter { isLink(it) }
        if (plain.isNotEmpty()) return plain
        // base64-подписка: одна строка base64 со ссылками внутри
        val decoded = runCatching { b64(t) }.getOrNull() ?: return emptyList()
        return decoded.lines().map { it.trim() }.filter { isLink(it) }
    }

    fun parse(link: String): Parsed {
        val l = link.trim()
        val scheme = l.substringBefore("://").lowercase()
        return when (scheme) {
            "vless" -> vless(l)
            "vmess" -> vmess(l)
            "trojan" -> trojan(l)
            "ss" -> shadowsocks(l)
            "hysteria2", "hy2" -> hysteria2(l)
            "tuic" -> tuic(l)
            else -> throw IllegalArgumentException("Неизвестная ссылка: $scheme://")
        }
    }

    // ---------------------------------------------------------------------------------------

    private class U(link: String) {
        val uri: URI = URI(link.substringBefore('#').replace(" ", "%20"))
        val name: String = link.substringAfter('#', "").let { runCatching { URLDecoder.decode(it, "UTF-8") }.getOrDefault(it) }
        val user: String = uri.rawUserInfo?.let { URLDecoder.decode(it, "UTF-8") } ?: throw IllegalArgumentException("В ссылке нет пользователя/пароля")
        val host: String = uri.host?.removePrefix("[")?.removeSuffix("]") ?: throw IllegalArgumentException("В ссылке нет адреса сервера")
        val port: Int = uri.port.takeIf { it > 0 } ?: throw IllegalArgumentException("В ссылке нет порта")
        val q: Map<String, String> = (uri.rawQuery ?: "").split('&').filter { it.contains('=') }.associate {
            it.substringBefore('=').lowercase() to URLDecoder.decode(it.substringAfter('='), "UTF-8")
        }
        fun get(k: String) = q[k]?.takeIf { it.isNotEmpty() }
        fun flag(k: String) = q[k] == "1" || q[k].equals("true", true)
    }

    @OptIn(ExperimentalEncodingApi::class)
    private fun b64(s: String): String {
        val clean = s.filterNot { it.isWhitespace() }.trimEnd('=').replace('-', '+').replace('_', '/')
        val pad = clean + "=".repeat((4 - clean.length % 4) % 4)
        return String(Base64.Default.decode(pad), Charsets.UTF_8)
    }

    /**
     * Outbound из JSON: готовый outbound sing-box ("type") или Xray ("protocol"), либо полный
     * конфиг — тогда берётся первый outbound, который ведёт на сервер (не direct/block/dns).
     */
    fun outboundFromJson(text: String): String {
        val root = runCatching { Json.parseToJsonElement(text).jsonObject }.getOrElse { throw IllegalArgumentException("JSON не читается: ${it.message}") }
        val skip = setOf("direct", "block", "dns", "selector", "urltest", "freedom", "blackhole", "loopback")
        fun ok(o: JsonObject): Boolean {
            val k = (o["type"] ?: o["protocol"])?.jsonPrimitive?.content ?: return false
            return k !in skip
        }
        val o = when {
            root.containsKey("outbounds") -> (root["outbounds"] as? JsonArray)?.map { it.jsonObject }?.firstOrNull(::ok)
                ?: throw IllegalArgumentException("В конфиге нет outbound, ведущего на сервер")
            ok(root) -> root
            else -> throw IllegalArgumentException("В JSON нет ни \"type\" (sing-box), ни \"protocol\" (Xray)")
        }
        // Метку и привязки к другим outbound убираем — приложение задаёт свои.
        val clean = JsonObject(o - "tag" - "detour" - "domain_resolver")
        return enc(clean)
    }

    private fun enc(o: JsonObject) = json.encodeToString(JsonObject.serializer(), o)

    private fun title(u: U, proto: String) = u.name.ifBlank { "$proto ${u.host}" }

    /** TLS-блок sing-box из параметров ссылки (security=tls|reality). */
    private fun singTls(u: U, sniDefault: String = u.host, forceTls: Boolean = false): JsonObject? {
        val sec = u.get("security") ?: if (forceTls) "tls" else "none"
        if (sec == "none") return null
        return buildJsonObject {
            put("enabled", true)
            put("server_name", u.get("sni") ?: u.get("peer") ?: sniDefault)
            if (u.flag("allowinsecure") || u.flag("insecure")) put("insecure", true)
            u.get("alpn")?.let { a -> putJsonArray("alpn") { a.split(',').forEach { add(JsonPrimitive(it.trim())) } } }
            u.get("fp")?.let { putJsonObject("utls") { put("enabled", true); put("fingerprint", it) } }
            if (sec == "reality") {
                if (u.get("fp") == null) putJsonObject("utls") { put("enabled", true); put("fingerprint", "chrome") }
                putJsonObject("reality") {
                    put("enabled", true)
                    put("public_key", u.get("pbk") ?: throw IllegalArgumentException("Reality: нет pbk (публичного ключа)"))
                    u.get("sid")?.let { put("short_id", it) }
                }
            }
        }
    }

    /** Транспорт sing-box (ws, grpc, httpupgrade, http). tcp — без транспорта. */
    private fun singTransport(u: U): JsonObject? = when (val t = u.get("type") ?: "tcp") {
        "tcp", "raw" -> if (u.get("headertype") == "http") buildJsonObject { put("type", "http"); u.get("path")?.let { put("path", it) } } else null
        "ws" -> buildJsonObject {
            put("type", "ws"); put("path", u.get("path") ?: "/")
            u.get("host")?.let { putJsonObject("headers") { put("Host", it) } }
        }
        "grpc" -> buildJsonObject { put("type", "grpc"); put("service_name", u.get("servicename") ?: u.get("path") ?: "") }
        "httpupgrade" -> buildJsonObject { put("type", "httpupgrade"); put("path", u.get("path") ?: "/"); u.get("host")?.let { put("host", it) } }
        "h2", "http" -> buildJsonObject {
            put("type", "http"); u.get("path")?.let { put("path", it) }
            u.get("host")?.let { h -> putJsonArray("host") { add(JsonPrimitive(h)) } }
        }
        else -> throw IllegalArgumentException("Транспорт «$t» не поддерживается")
    }

    private fun vless(l: String): Parsed {
        val u = U(l)
        if (u.get("type") == "xhttp" || u.get("type") == "splithttp") return vlessXhttp(u)
        val o = buildJsonObject {
            put("type", "vless"); put("server", u.host); put("server_port", u.port); put("uuid", u.user)
            u.get("flow")?.let { put("flow", it) }
            singTls(u)?.let { put("tls", it) }
            singTransport(u)?.let { put("transport", it) }
            if (u.get("packetencoding") != null) put("packet_encoding", u.get("packetencoding")!!)
        }
        return Parsed(title(u, "VLESS"), enc(o), "VLESS")
    }

    /** VLESS+XHTTP — только ядро Xray: outbound в формате Xray. */
    private fun vlessXhttp(u: U): Parsed {
        val sec = u.get("security") ?: "none"
        val stream = buildJsonObject {
            put("network", "xhttp")
            put("security", sec)
            when (sec) {
                "tls" -> putJsonObject("tlsSettings") {
                    put("serverName", u.get("sni") ?: u.host)
                    u.get("fp")?.let { put("fingerprint", it) }
                    u.get("alpn")?.let { a -> putJsonArray("alpn") { a.split(',').forEach { add(JsonPrimitive(it.trim())) } } }
                    if (u.flag("allowinsecure") || u.flag("insecure")) put("allowInsecure", true)
                }
                "reality" -> putJsonObject("realitySettings") {
                    put("serverName", u.get("sni") ?: u.host)
                    put("fingerprint", u.get("fp") ?: "chrome")
                    put("publicKey", u.get("pbk") ?: throw IllegalArgumentException("Reality: нет pbk"))
                    u.get("sid")?.let { put("shortId", it) }
                    u.get("spx")?.let { put("spiderX", it) }
                }
            }
            putJsonObject("xhttpSettings") {
                u.get("host")?.let { put("host", it) }
                put("path", u.get("path") ?: "/")
                put("mode", u.get("mode") ?: "auto")
                u.get("extra")?.let { e -> runCatching { Json.parseToJsonElement(e).jsonObject }.getOrNull()?.let { put("extra", it) } }
            }
        }
        val o = buildJsonObject {
            put("protocol", "vless")
            putJsonObject("settings") {
                putJsonArray("vnext") {
                    add(buildJsonObject {
                        put("address", u.host); put("port", u.port)
                        putJsonArray("users") { add(buildJsonObject { put("id", u.user); put("encryption", u.get("encryption") ?: "none"); u.get("flow")?.let { put("flow", it) } }) }
                    })
                }
            }
            put("streamSettings", stream)
        }
        return Parsed(title(u, "VLESS XHTTP"), enc(o), "VLESS+XHTTP")
    }

    private fun vmess(l: String): Parsed {
        val body = l.substringAfter("://").substringBefore('#')
        val j = runCatching { Json.parseToJsonElement(b64(body)).jsonObject }.getOrElse { throw IllegalArgumentException("vmess: ожидается base64 JSON") }
        fun s(k: String) = (j[k] as? JsonPrimitive)?.content?.takeIf { it.isNotEmpty() }
        val host = s("add") ?: throw IllegalArgumentException("vmess: нет адреса")
        val port = s("port")?.toIntOrNull() ?: throw IllegalArgumentException("vmess: нет порта")
        val net = s("net") ?: "tcp"
        val o = buildJsonObject {
            put("type", "vmess"); put("server", host); put("server_port", port)
            put("uuid", s("id") ?: throw IllegalArgumentException("vmess: нет id"))
            put("security", s("scy") ?: "auto"); put("alter_id", s("aid")?.toIntOrNull() ?: 0)
            if (s("tls") == "tls") putJsonObject("tls") {
                put("enabled", true); put("server_name", s("sni") ?: s("host") ?: host)
                s("fp")?.let { putJsonObject("utls") { put("enabled", true); put("fingerprint", it) } }
                s("alpn")?.let { a -> putJsonArray("alpn") { a.split(',').forEach { add(JsonPrimitive(it.trim())) } } }
            }
            when (net) {
                "ws" -> putJsonObject("transport") { put("type", "ws"); put("path", s("path") ?: "/"); s("host")?.let { putJsonObject("headers") { put("Host", it) } } }
                "grpc" -> putJsonObject("transport") { put("type", "grpc"); put("service_name", s("path") ?: "") }
                "h2", "http" -> putJsonObject("transport") { put("type", "http"); s("path")?.let { put("path", it) } }
                "httpupgrade" -> putJsonObject("transport") { put("type", "httpupgrade"); put("path", s("path") ?: "/") }
            }
        }
        return Parsed(s("ps") ?: "VMess $host", enc(o), "VMess")
    }

    private fun trojan(l: String): Parsed {
        val u = U(l)
        val o = buildJsonObject {
            put("type", "trojan"); put("server", u.host); put("server_port", u.port); put("password", u.user)
            singTls(u, forceTls = true)?.let { put("tls", it) }
            singTransport(u)?.let { put("transport", it) }
        }
        return Parsed(title(u, "Trojan"), enc(o), "Trojan")
    }

    private fun shadowsocks(l: String): Parsed {
        val name = l.substringAfter('#', "").let { runCatching { URLDecoder.decode(it, "UTF-8") }.getOrDefault(it) }
        var body = l.substringAfter("://").substringBefore('#').substringBefore('?')
        // Старый формат: ss://base64(method:password@host:port)
        if (!body.contains('@')) body = b64(body)
        val userinfo = body.substringBeforeLast('@')
        val hostPort = body.substringAfterLast('@').trimEnd('/')
        val creds = if (userinfo.contains(':') || userinfo.contains("%3A", true)) URLDecoder.decode(userinfo, "UTF-8") else b64(userinfo)
        val method = creds.substringBefore(':')
        val password = creds.substringAfter(':')
        val host = hostPort.substringBeforeLast(':').removePrefix("[").removeSuffix("]")
        val port = hostPort.substringAfterLast(':').toIntOrNull() ?: throw IllegalArgumentException("ss: нет порта")
        if (method.isBlank() || password.isBlank()) throw IllegalArgumentException("ss: нет метода или пароля")
        val o = buildJsonObject { put("type", "shadowsocks"); put("server", host); put("server_port", port); put("method", method); put("password", password) }
        return Parsed(name.ifBlank { "Shadowsocks $host" }, enc(o), "Shadowsocks")
    }

    private fun hysteria2(l: String): Parsed {
        val u = U(l.replaceFirst(Regex("^hy2://", RegexOption.IGNORE_CASE), "hysteria2://"))
        val o = buildJsonObject {
            put("type", "hysteria2"); put("server", u.host); put("server_port", u.port); put("password", u.user)
            putJsonObject("tls") {
                put("enabled", true); put("server_name", u.get("sni") ?: u.host)
                if (u.flag("insecure")) put("insecure", true)
                u.get("alpn")?.let { a -> putJsonArray("alpn") { a.split(',').forEach { add(JsonPrimitive(it.trim())) } } }
            }
            u.get("obfs")?.let { ob -> putJsonObject("obfs") { put("type", ob); put("password", u.get("obfs-password") ?: "") } }
        }
        return Parsed(title(u, "Hysteria2"), enc(o), "Hysteria2")
    }

    private fun tuic(l: String): Parsed {
        val u = U(l)
        val o = buildJsonObject {
            put("type", "tuic"); put("server", u.host); put("server_port", u.port)
            put("uuid", u.user.substringBefore(':')); put("password", u.user.substringAfter(':', ""))
            put("congestion_control", u.get("congestion_control") ?: "bbr")
            u.get("udp_relay_mode")?.let { put("udp_relay_mode", it) }
            putJsonObject("tls") {
                put("enabled", true); put("server_name", u.get("sni") ?: u.host)
                if (u.flag("allow_insecure") || u.flag("insecure")) put("insecure", true)
                putJsonArray("alpn") { (u.get("alpn") ?: "h3").split(',').forEach { add(JsonPrimitive(it.trim())) } }
            }
        }
        return Parsed(title(u, "TUIC"), enc(o), "TUIC")
    }

    /** Название протокола по outbound (для списка соединений). */
    fun protocolOf(outbound: String): String = runCatching {
        val o = Json.parseToJsonElement(outbound).jsonObject
        if (o.containsKey("protocol")) {
            val net = o["streamSettings"]?.jsonObject?.get("network")?.jsonPrimitive?.content
            return "VLESS" + if (net == "xhttp") "+XHTTP" else ""
        }
        val tls = o["tls"]?.jsonObject
        when (o["type"]?.jsonPrimitive?.content) {
            "vless" -> "VLESS" + when { tls?.get("reality") != null -> "+Reality"; o["transport"] != null -> "+" + o["transport"]!!.jsonObject["type"]!!.jsonPrimitive.content.uppercase(); tls != null -> "+TLS"; else -> "" }
            "vmess" -> "VMess"; "trojan" -> "Trojan"; "shadowsocks" -> "Shadowsocks"; "hysteria2" -> "Hysteria2"; "tuic" -> "TUIC"
            else -> "sing-box"
        }
    }.getOrDefault("sing-box")

    @Suppress("unused") private fun JsonArray.strings() = map { it.jsonPrimitive.content }
}
