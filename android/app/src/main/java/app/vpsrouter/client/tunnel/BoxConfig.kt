package app.vpsrouter.client.tunnel

import app.vpsrouter.client.data.AppSettings
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
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

    fun build(outboundJson: String, s: AppSettings, dnsOverride: String = s.dnsOverride): String {
        val outbound = JsonObject(Json.parseToJsonElement(outboundJson).jsonObject + ("tag" to JsonPrimitive("proxy")))
        val (included, excluded) = ConfigTransform.appLists(s)
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
                    put("mtu", 1400)
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
        val host = (o["server"] as? JsonPrimitive)?.content ?: return null
        host to ((o["server_port"] as? JsonPrimitive)?.content?.toIntOrNull() ?: 0)
    }.getOrNull()
}
