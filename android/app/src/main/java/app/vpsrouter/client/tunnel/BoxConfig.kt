package app.vpsrouter.client.tunnel

import app.vpsrouter.client.data.AppSettings
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put
import kotlinx.serialization.json.putJsonArray
import kotlinx.serialization.json.putJsonObject

/**
 * Клиентский конфиг sing-box для соединения из панели (VLESS+Reality, Hysteria2, Trojan,
 * Shadowsocks). Весь трафик телефона через TUN уходит на РФ-сервер, а маршруты, exit-серверы,
 * блокировка «без РФ IP» и умный DNS работают уже там — как и для AmneziaWG.
 *
 * DNS: запросы идут обычным UDP:53 через этот же туннель; на сервере их перехватывает
 * sing-box панели (умный DNS: домены exit-маршрутов резолвятся через exit).
 */
object BoxConfig {
    private val json = Json { prettyPrint = false }

    /** Локальный вход Xray для XHTTP: порт и одноразовые логин/пароль (чтобы им не пользовались другие приложения). */
    data class XrayBridge(val port: Int, val user: String, val pass: String)

    /** Outbound в формате Xray (ключ "protocol") — его запускает ядро Xray (VLESS+XHTTP). */
    fun isXray(outboundJson: String): Boolean = runCatching {
        Json.parseToJsonElement(outboundJson).jsonObject.containsKey("protocol")
    }.getOrDefault(false)

    /** Конфиг Xray: SOCKS-вход на 127.0.0.1 с паролем → outbound из панели. */
    fun xrayConfig(outboundJson: String, b: XrayBridge): String {
        val outbound = JsonObject(Json.parseToJsonElement(outboundJson).jsonObject + ("tag" to JsonPrimitive("proxy")))
        val cfg = buildJsonObject {
            putJsonObject("log") { put("loglevel", "warning") }
            putJsonArray("inbounds") {
                add(buildJsonObject {
                    put("tag", "in"); put("listen", "127.0.0.1"); put("port", b.port); put("protocol", "socks")
                    putJsonObject("settings") {
                        put("auth", "password"); put("udp", true)
                        putJsonArray("accounts") { add(buildJsonObject { put("user", b.user); put("pass", b.pass) }) }
                    }
                })
            }
            putJsonArray("outbounds") { add(outbound) }
        }
        return json.encodeToString(JsonObject.serializer(), cfg)
    }

    fun build(outboundJson: String, s: AppSettings, dnsOverride: String = s.dnsOverride, xray: XrayBridge? = null, ownPackage: String? = null): String {
        val outbound = if (xray != null) buildJsonObject {
            // Трафик из TUN — в локальный вход Xray, наружу его ведёт уже Xray (XHTTP).
            put("type", "socks"); put("tag", "proxy"); put("server", "127.0.0.1"); put("server_port", xray.port)
            put("version", "5"); put("username", xray.user); put("password", xray.pass)
        } else JsonObject(Json.parseToJsonElement(outboundJson).jsonObject + ("tag" to JsonPrimitive("proxy")))
        val (included, excludedBase) = ConfigTransform.appLists(s)
        // Сокеты Xray не умеют protect(), поэтому само приложение выводим из туннеля.
        val excluded = if (xray != null && ownPackage != null && included.isEmpty()) (excludedBase + ownPackage).distinct() else excludedBase
        val dns = dnsOverride.split(',', ' ').map { it.trim() }.firstOrNull { it.isNotEmpty() } ?: "1.1.1.1"

        val cfg = buildJsonObject {
            putJsonObject("log") { put("level", "warn") }
            putJsonObject("dns") {
                putJsonArray("servers") {
                    // DNS через туннель по TCP — работает с любым протоколом, даже если UDP режется.
                    add(buildJsonObject { put("type", "tcp"); put("tag", "remote"); put("server", dns); put("detour", "proxy") })
                    // Только чтобы узнать IP самого сервера, если в панели он задан именем.
                    add(buildJsonObject { put("type", "udp"); put("tag", "local"); put("server", "77.88.8.8") })
                }
                put("final", "remote")
                put("strategy", "ipv4_only")
            }
            putJsonArray("inbounds") {
                add(buildJsonObject {
                    put("type", "tun")
                    put("tag", "tun-in")
                    // IPv6-адрес нужен, чтобы IPv6-трафик тоже шёл в туннель, а не мимо него.
                    putJsonArray("address") { add(JsonPrimitive("172.19.0.1/30")); add(JsonPrimitive("fdfe:dcba:9876::1/126")) }
                    put("mtu", if (s.mtu > 0) s.mtu else 1400)
                    put("auto_route", true)
                    put("strict_route", true)
                    put("stack", "gvisor")
                    if (included.isNotEmpty()) put("include_package", JsonArray(included.map(::JsonPrimitive)))
                    else if (excluded.isNotEmpty()) put("exclude_package", JsonArray(excluded.map(::JsonPrimitive)))
                    if (s.bypassLan) {
                        // Локальная сеть мимо VPN: эти адреса не заворачиваются в TUN.
                        put("route_exclude_address", buildJsonArray {
                            listOf("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "169.254.0.0/16").forEach { add(JsonPrimitive(it)) }
                        })
                    }
                })
            }
            putJsonArray("outbounds") {
                add(outbound)
                add(buildJsonObject { put("type", "direct"); put("tag", "direct") })
            }
            putJsonObject("route") {
                putJsonArray("rules") {
                    add(buildJsonObject { put("action", "sniff") })
                    add(buildJsonObject { put("protocol", "dns"); put("action", "hijack-dns") })
                    if (s.bypassLan) add(buildJsonObject { put("ip_is_private", true); put("outbound", "direct") })
                }
                put("final", "proxy")
                put("auto_detect_interface", true)
                put("default_domain_resolver", "local")
            }
        }
        return json.encodeToString(JsonObject.serializer(), cfg)
    }

    /** Адрес сервера из outbound — для экрана «Связи» и подробностей. */
    fun endpointOf(outboundJson: String): Pair<String, Int>? = runCatching {
        val o = Json.parseToJsonElement(outboundJson).jsonObject
        if (o.containsKey("protocol")) {
            // Xray: settings.vnext[0].address/port
            val v = o["settings"]!!.jsonObject["vnext"]!!.jsonArray[0].jsonObject
            return (v["address"] as JsonPrimitive).content to ((v["port"] as? JsonPrimitive)?.content?.toIntOrNull() ?: 0)
        }
        val host = (o["server"] as? JsonPrimitive)?.content ?: return null
        host to ((o["server_port"] as? JsonPrimitive)?.content?.toIntOrNull() ?: 0)
    }.getOrNull()
}
