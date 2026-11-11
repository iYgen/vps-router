package app.vpsrouter.client.tunnel

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.jsonObject

/**
 * Основные поля соединения для ручной правки (как в v2rayTun): адрес, порт, SNI, отпечаток TLS,
 * путь/хост транспорта и режим XHTTP. Понимает outbound sing-box и Xray; остальное в JSON не трогается.
 */
data class OutboundFields(
    val server: String = "",
    val port: String = "",
    val sni: String = "",
    val fingerprint: String = "",
    val path: String = "",
    val host: String = "",
    /** Только XHTTP: auto / packet-up / stream-up / stream-one. */
    val mode: String = "",
    val isXray: Boolean = false,
    val hasTls: Boolean = false,
    val hasTransport: Boolean = false,
) {
    companion object {
        private val json = Json { prettyPrint = false }

        private fun JsonObject.obj(vararg path: String): JsonObject? {
            var cur: JsonObject? = this
            for (k in path) cur = cur?.get(k) as? JsonObject
            return cur
        }
        private fun JsonObject.str(k: String) = (this[k] as? JsonPrimitive)?.content.orEmpty()

        /** Записать значение по пути (пусто — удалить ключ), создавая промежуточные объекты. */
        private fun JsonObject.put(path: List<String>, v: JsonElement?): JsonObject {
            val k = path.first()
            if (path.size == 1) return JsonObject(if (v == null) this - k else this + (k to v))
            val child = (this[k] as? JsonObject) ?: if (v == null) return this else JsonObject(emptyMap())
            return JsonObject(this + (k to child.put(path.drop(1), v)))
        }
        private fun s(v: String): JsonElement? = v.trim().takeIf { it.isNotEmpty() }?.let(::JsonPrimitive)

        fun read(outbound: String): OutboundFields {
            val o = Json.parseToJsonElement(outbound).jsonObject
            return if (o.containsKey("protocol")) {
                val v = (o.obj("settings")?.get("vnext") as? JsonArray)?.firstOrNull() as? JsonObject
                    ?: ((o.obj("settings")?.get("servers") as? JsonArray)?.firstOrNull() as? JsonObject)
                val ss = o.obj("streamSettings")
                val tls = ss?.obj("tlsSettings") ?: ss?.obj("realitySettings")
                val tr = ss?.let { it.obj("xhttpSettings") ?: it.obj("wsSettings") ?: it.obj("httpupgradeSettings") ?: it.obj("grpcSettings") }
                OutboundFields(
                    server = v?.str("address").orEmpty(), port = v?.str("port").orEmpty(),
                    sni = tls?.str("serverName").orEmpty(), fingerprint = tls?.str("fingerprint").orEmpty(),
                    path = tr?.let { it.str("path").ifEmpty { it.str("serviceName") } }.orEmpty(),
                    host = tr?.str("host").orEmpty(), mode = ss?.obj("xhttpSettings")?.str("mode").orEmpty(),
                    isXray = true, hasTls = tls != null, hasTransport = tr != null,
                )
            } else {
                val tls = o.obj("tls")
                val tr = o.obj("transport")
                OutboundFields(
                    server = o.str("server"), port = o.str("server_port"),
                    sni = tls?.str("server_name").orEmpty(), fingerprint = tls?.obj("utls")?.str("fingerprint").orEmpty(),
                    path = tr?.let { it.str("path").ifEmpty { it.str("service_name") } }.orEmpty(),
                    host = tr?.let { it.obj("headers")?.str("Host") ?: it.str("host") }.orEmpty(),
                    hasTls = tls != null, hasTransport = tr != null,
                )
            }
        }

        fun write(outbound: String, f: OutboundFields): String {
            var o = Json.parseToJsonElement(outbound).jsonObject
            val port = f.port.trim().toIntOrNull()?.takeIf { it in 1..65535 } ?: throw IllegalArgumentException("Порт — число от 1 до 65535")
            if (f.server.isBlank()) throw IllegalArgumentException("Укажите адрес сервера")
            if (f.isXray) {
                val list = if (o.obj("settings")?.get("vnext") != null) "vnext" else "servers"
                val arr = (o.obj("settings")?.get(list) as? JsonArray).orEmpty()
                val first = (arr.firstOrNull() as? JsonObject ?: JsonObject(emptyMap()))
                    .put(listOf("address"), JsonPrimitive(f.server.trim())).put(listOf("port"), JsonPrimitive(port))
                o = o.put(listOf("settings", list), JsonArray(listOf(first) + arr.drop(1)))
                val ss = o.obj("streamSettings")
                val tlsKey = if (ss?.obj("realitySettings") != null) "realitySettings" else "tlsSettings"
                if (ss?.obj(tlsKey) != null) {
                    o = o.put(listOf("streamSettings", tlsKey, "serverName"), s(f.sni))
                        .put(listOf("streamSettings", tlsKey, "fingerprint"), s(f.fingerprint))
                }
                val trKey = listOf("xhttpSettings", "wsSettings", "httpupgradeSettings").firstOrNull { ss?.obj(it) != null }
                if (trKey != null) {
                    o = o.put(listOf("streamSettings", trKey, "path"), s(f.path)).put(listOf("streamSettings", trKey, "host"), s(f.host))
                    if (trKey == "xhttpSettings") o = o.put(listOf("streamSettings", trKey, "mode"), s(f.mode))
                }
            } else {
                o = o.put(listOf("server"), JsonPrimitive(f.server.trim())).put(listOf("server_port"), JsonPrimitive(port))
                if (o.obj("tls") != null) {
                    o = o.put(listOf("tls", "server_name"), s(f.sni))
                    o = if (f.fingerprint.isBlank()) o.put(listOf("tls", "utls"), null)
                    else o.put(listOf("tls", "utls"), JsonObject(mapOf("enabled" to JsonPrimitive(true), "fingerprint" to JsonPrimitive(f.fingerprint.trim()))))
                }
                val tr = o.obj("transport")
                if (tr != null) {
                    when (tr.str("type")) {
                        "grpc" -> o = o.put(listOf("transport", "service_name"), s(f.path))
                        "ws" -> o = o.put(listOf("transport", "path"), s(f.path)).put(listOf("transport", "headers"), s(f.host)?.let { JsonObject(mapOf("Host" to it)) })
                        "http" -> o = o.put(listOf("transport", "path"), s(f.path)).put(listOf("transport", "host"), s(f.host)?.let { JsonArray(listOf(it)) })
                        else -> o = o.put(listOf("transport", "path"), s(f.path)).put(listOf("transport", "host"), s(f.host))
                    }
                }
            }
            return json.encodeToString(JsonObject.serializer(), o)
        }
    }
}
