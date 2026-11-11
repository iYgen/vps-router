package app.vpsrouter.client.panel

import app.vpsrouter.client.data.PanelConnection
import app.vpsrouter.client.data.PanelDevice
import app.vpsrouter.client.data.PanelProfile
import app.vpsrouter.client.data.PanelRoute
import app.vpsrouter.client.data.PanelServer
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import okhttp3.Cookie
import okhttp3.CookieJar
import okhttp3.FormBody
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import java.net.URLDecoder
import java.security.SecureRandom
import java.security.cert.X509Certificate
import java.util.concurrent.TimeUnit
import javax.net.ssl.SSLContext
import javax.net.ssl.X509TrustManager

class PanelException(msg: String, val code: Int = 0) : Exception(msg)
class TwoFactorRequired : Exception("Требуется код 2FA")

/**
 * Клиент веб-панели VPS Router. У панели нет отдельного токен-API: используется та же
 * сессия, что и в браузере (cookie PHPSESSID) + CSRF-токен в заголовке X-CSRF-Token
 * для api/…php (см. App\Http::guard). Вход — форма login.php (+ шаг TOTP, если включена 2FA).
 */
class PanelClient(private val profile: PanelProfile) {
    private val cookies = mutableMapOf<String, MutableList<Cookie>>()
    private val json = Json { ignoreUnknownKeys = true }
    private var csrf: String? = null
    /** Адрес, на котором удалось войти (основной или запасной). */
    var baseUrl: String = normalize(profile.url)
        private set

    private val http: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(10, TimeUnit.MINUTES) // apply/provision на панели идут минутами
        .followRedirects(false)
        .cookieJar(object : CookieJar {
            override fun saveFromResponse(url: HttpUrl, cookies: List<Cookie>) {
                val list = this@PanelClient.cookies.getOrPut(url.host) { mutableListOf() }
                cookies.forEach { c -> list.removeAll { it.name == c.name }; list += c }
            }
            override fun loadForRequest(url: HttpUrl): List<Cookie> = cookies[url.host].orEmpty()
        })
        .apply {
            if (profile.insecureTls) {
                val tm = object : X509TrustManager {
                    override fun checkClientTrusted(chain: Array<out X509Certificate>?, authType: String?) {}
                    override fun checkServerTrusted(chain: Array<out X509Certificate>?, authType: String?) {}
                    override fun getAcceptedIssuers(): Array<X509Certificate> = emptyArray()
                }
                val ctx = SSLContext.getInstance("TLS").apply { init(null, arrayOf(tm), SecureRandom()) }
                sslSocketFactory(ctx.socketFactory, tm)
                hostnameVerifier { _, _ -> true }
            }
        }
        .build()

    // ------------------------------------------------------------------ вход

    /** Быстрая проверка доступности: отвечает ли login.php. */
    suspend fun ping(url: String = baseUrl): Boolean = withContext(Dispatchers.IO) {
        runCatching {
            val quick = http.newBuilder().connectTimeout(5, TimeUnit.SECONDS).readTimeout(8, TimeUnit.SECONDS).build()
            quick.newCall(Request.Builder().url("$url/login.php").build()).execute().use { it.code in 200..399 }
        }.getOrDefault(false)
    }

    /** Найти первый живой адрес (основной → запасные). */
    suspend fun pickReachable(): String? {
        for (u in listOf(profile.url) + profile.altUrls) {
            val n = normalize(u)
            if (n.isNotBlank() && ping(n)) { baseUrl = n; return n }
        }
        return null
    }

    /** Вход. Бросает [TwoFactorRequired], если нужен код — затем вызвать [submitTotp]. */
    suspend fun login() = withContext(Dispatchers.IO) {
        pickReachable() ?: throw PanelException("Панель недоступна ни по одному адресу")
        val page = get("/login.php")
        page.use {
            val loc = it.header("Location").orEmpty()
            if (loc.contains("install.php")) throw PanelException("Панель ещё не установлена — завершите мастер установки в браузере")
            if (loc.contains("dashboard.php")) { loadCsrf(); return@withContext }
        }
        val token = formToken(getText("/login.php"))
        val resp = post("/login.php", FormBody.Builder().add("csrf_token", token).add("username", profile.username).add("password", profile.password).build())
        handleLoginResponse(resp)
    }

    suspend fun submitTotp(code: String) = withContext(Dispatchers.IO) {
        val token = formToken(getText("/login.php"))
        handleLoginResponse(post("/login.php", FormBody.Builder().add("csrf_token", token).add("totp_code", code.trim()).build()))
    }

    private fun handleLoginResponse(resp: Response) {
        resp.use {
            if (it.code in 300..399 && it.header("Location").orEmpty().contains("dashboard")) {
                loadCsrf(); return
            }
            val body = it.body?.string().orEmpty()
            if (body.contains("name=\"totp_code\"")) {
                if (body.contains("class=\"err") || body.contains("error")) {
                    // Второй шаг уже был — значит код неверный.
                }
                throw TwoFactorRequired()
            }
            if (body.contains("blocked", true) || body.contains("заблок", true)) throw PanelException("Вход временно заблокирован панелью (много неудачных попыток с этого IP)")
            throw PanelException("Неверный логин или пароль")
        }
    }

    private fun loadCsrf() {
        val html = getText("/dashboard.php")
        csrf = Regex("""<meta name="csrf-token" content="([^"]+)"""").find(html)?.groupValues?.get(1)
            ?: throw PanelException("Не удалось получить CSRF-токен панели (сессия не установилась)")
    }

    private fun formToken(html: String) = Regex("""name="csrf_token" value="([^"]+)"""").find(html)?.groupValues?.get(1)
        ?: throw PanelException("На странице входа нет CSRF-токена — это точно панель VPS Router?")

    // ------------------------------------------------------------------ HTTP

    private fun url(path: String) = (baseUrl + path).toHttpUrl()

    private fun get(path: String): Response = http.newCall(Request.Builder().url(url(path)).build()).execute()

    private fun getText(path: String): String = get(path).use { r ->
        if (r.code in 300..399 && r.header("Location").orEmpty().contains("login")) throw PanelException("Сессия панели истекла", 401)
        if (!r.isSuccessful) throw PanelException("HTTP ${r.code} на $path", r.code)
        r.body?.string().orEmpty()
    }

    private fun post(path: String, body: okhttp3.RequestBody): Response =
        http.newCall(Request.Builder().url(url(path)).post(body).apply { csrf?.let { header("X-CSRF-Token", it) } }.build()).execute()

    private fun api(method: String, path: String, body: JsonObject? = null): JsonElement {
        val rb = (body ?: JsonObject(emptyMap())).toString().toRequestBody("application/json".toMediaType())
        val req = Request.Builder().url(url(path)).apply {
            csrf?.let { header("X-CSRF-Token", it) }
            header("Accept", "application/json")
            when (method) {
                "GET" -> get()
                "DELETE" -> delete(rb)
                "PUT" -> put(rb)
                else -> post(rb)
            }
        }.build()
        http.newCall(req).execute().use { r ->
            val text = r.body?.string().orEmpty()
            val el = runCatching { json.parseToJsonElement(text) }.getOrNull()
            if (r.code == 401) throw PanelException("Сессия панели истекла — войдите снова", 401)
            if (!r.isSuccessful) {
                val msg = (el as? JsonObject)?.get("error")?.jsonPrimitive?.contentOrNull ?: "HTTP ${r.code}"
                throw PanelException(msg, r.code)
            }
            return el ?: throw PanelException("Панель вернула не-JSON ответ")
        }
    }

    // ------------------------------------------------------------------ данные

    private fun JsonObject.str(k: String) = (this[k] as? JsonPrimitive)?.contentOrNull
    private fun JsonObject.int(k: String) = (this[k] as? JsonPrimitive)?.let { it.intOrNull ?: it.contentOrNull?.toIntOrNull() }
    private fun JsonObject.bool(k: String) = (this[k] as? JsonPrimitive)?.let { it.booleanOrNull ?: (it.contentOrNull == "1") } ?: false

    suspend fun servers(): List<PanelServer> = withContext(Dispatchers.IO) {
        api("GET", "/api/servers.php").jsonArray.map { it.jsonObject }.map {
            PanelServer(
                id = it.int("id") ?: 0, name = it.str("name").orEmpty(), role = it.str("role").orEmpty(),
                host = it.str("host"), status = it.str("status"), isSelf = it.bool("is_self"), country = it.str("country"),
            )
        }
    }

    suspend fun relays(): List<PanelConnection> = withContext(Dispatchers.IO) {
        api("GET", "/api/connections.php").jsonArray.map { it.jsonObject }.filter { it.str("type") == "relay" }.map {
            val cfg = it.str("config")?.let { c -> runCatching { json.parseToJsonElement(c).jsonObject }.getOrNull() }
            PanelConnection(
                id = it.int("id") ?: 0, type = "relay",
                sourceId = it.int("source_server_id") ?: 0, targetId = it.int("target_server_id") ?: 0,
                label = it.str("label"),
                provisionStatus = cfg?.str("provision_status"),
                ports = (cfg?.get("ports") as? JsonArray)?.mapNotNull { p -> (p as? JsonPrimitive)?.contentOrNull }.orEmpty(),
            )
        }
    }

    /**
     * Устройства: JSON-эндпоинта списка в панели нет, поэтому разбираем devices.php —
     * блоки dev-item с именем и ссылками device-config.php?id=N&format=X.
     */
    suspend fun devices(): List<PanelDevice> = withContext(Dispatchers.IO) {
        val html = getText("/devices.php")
        html.split("<div class=\"dev-item\">").drop(1).mapNotNull { chunk ->
            val name = Regex("""class="dev-name" title="([^"]*)"""").find(chunk)?.groupValues?.get(1)?.let(::htmlDecode) ?: return@mapNotNull null
            val links = Regex("""device-config\.php\?id=(\d+)(?:&amp;|&)format=([^"&]+)""").findAll(chunk).toList()
            val id = links.firstOrNull()?.groupValues?.get(1)?.toIntOrNull()
                ?: Regex("""name="id" value="(\d+)"""").find(chunk)?.groupValues?.get(1)?.toIntOrNull()
                ?: return@mapNotNull null
            val formats = links.map { URLDecoder.decode(it.groupValues[2], "UTF-8") }.filter { it != "singbox" }.distinct()
            PanelDevice(
                id = id, name = name,
                revoked = chunk.substringBefore("dev-toggle").contains("revoked"),
                online = chunk.contains("dev-pill on"),
                formats = formats,
            )
        }
    }

    /** Текст конфига устройства: ссылка (vless://…) или .conf (amneziawg/wireguard). */
    suspend fun deviceConfig(id: Int, format: String): String = withContext(Dispatchers.IO) {
        val path = "/device-config.php?id=$id&format=" + java.net.URLEncoder.encode(format, "UTF-8") + "&inline=1"
        get(path).use { r ->
            val body = r.body?.string().orEmpty()
            if (!r.isSuccessful) throw PanelException(body.ifBlank { "HTTP ${r.code}" }, r.code)
            body
        }
    }

    /** Мастер добавления устройства (как кнопка в devices.php): включит протокол, создаст и применит. */
    suspend fun createDevice(name: String, protocol: String, deviceType: String): PanelDevice? = withContext(Dispatchers.IO) {
        val token = csrf ?: throw PanelException("Нет сессии")
        post(
            "/devices.php",
            FormBody.Builder().add("csrf_token", token).add("action", "create_device")
                .add("name", name).add("protocol", protocol).add("device_type", deviceType).build()
        ).close()
        devices().lastOrNull { it.name == name }
    }

    suspend fun setRevoked(id: Int, revoked: Boolean) = withContext(Dispatchers.IO) {
        val token = csrf ?: throw PanelException("Нет сессии")
        post("/devices.php", FormBody.Builder().add("csrf_token", token).add("action", "revoke").add("id", "$id").add("revoked", if (revoked) "1" else "0").build()).close()
    }

    suspend fun deleteDevice(id: Int) = withContext(Dispatchers.IO) {
        val token = csrf ?: throw PanelException("Нет сессии")
        post("/devices.php", FormBody.Builder().add("csrf_token", token).add("action", "delete").add("id", "$id").build()).close()
    }

    suspend fun createServer(name: String, role: String, host: String, sshPort: Int, sshUser: String): PanelServer = withContext(Dispatchers.IO) {
        val o = api("POST", "/api/servers.php", buildJsonObject {
            put("name", name); put("role", role); put("host", host); put("ssh_port", sshPort); put("ssh_user", sshUser); put("enabled", 1)
        }).jsonObject
        PanelServer(o.int("id") ?: 0, o.str("name").orEmpty(), o.str("role").orEmpty(), o.str("host"))
    }

    /** Панель сама создаёт SSH-ключ по паролю и дальше ходит на узел по ключу. */
    suspend fun bootstrapSsh(serverId: Int, password: String): String? = withContext(Dispatchers.IO) {
        val o = api("POST", "/api/servers.php?action=bootstrap-ssh&id=$serverId", buildJsonObject { put("password", password) }).jsonObject
        if (o.bool("ok")) null else (o.str("raw_error") ?: o.str("error") ?: "Не удалось войти по SSH")
    }

    suspend fun deleteServer(id: Int) = withContext(Dispatchers.IO) { api("DELETE", "/api/servers.php?id=$id") }

    suspend fun createRelay(relayServerId: Int, routerId: Int): Int = withContext(Dispatchers.IO) {
        api("POST", "/api/connections.php", buildJsonObject {
            put("source_server_id", relayServerId); put("target_server_id", routerId); put("type", "relay")
        }).jsonObject.int("id") ?: throw PanelException("Панель не вернула id связи")
    }

    /** Применить пересылку на ретрансляторе (App\Relay::sync). Возвращает null или текст ошибки + лог. */
    suspend fun provisionConnection(id: Int): Pair<Boolean, String> = withContext(Dispatchers.IO) {
        val o = api("POST", "/api/connections.php?action=provision&id=$id").jsonObject
        val log = listOfNotNull(o.str("stdout"), o.str("stderr"), o.str("raw_error")).joinToString("\n").trim()
        o.bool("ok") to log
    }

    suspend fun deleteConnection(id: Int) = withContext(Dispatchers.IO) { api("DELETE", "/api/connections.php?id=$id") }

    /** Маршруты (группы правил) роутера с правилами и выходом. */
    suspend fun routes(): List<PanelRoute> = withContext(Dispatchers.IO) {
        api("GET", "/api/routes.php").jsonArray.map { it.jsonObject }.map { g ->
            PanelRoute(
                id = g.int("id") ?: 0, name = g.str("name").orEmpty(), enabled = g.bool("enabled"),
                parentId = g.int("parent_id"),
                target = g.str("server_set_name")?.let { "набор «$it»" } ?: g.str("exit_name"),
                rules = (g["rules"] as? JsonArray)?.mapNotNull { r -> (r as? JsonObject)?.let { "${it.str("type")}:${it.str("value")}" } }.orEmpty(),
            )
        }
    }

    suspend fun setRouteEnabled(id: Int, enabled: Boolean) = withContext(Dispatchers.IO) {
        api("PUT", "/api/routes.php?id=$id", buildJsonObject { put("enabled", enabled) })
    }

    /** Добавить домен в маршрут (domain_suffix: сам домен и все поддомены). */
    suspend fun addDomain(routeId: Int, domain: String) = withContext(Dispatchers.IO) {
        api("POST", "/api/routes.php?action=add-rule&id=$routeId", buildJsonObject { put("type", "domain_suffix"); put("value", domain.trim().lowercase()) })
    }

    suspend fun apply(): String = withContext(Dispatchers.IO) {
        val o = api("POST", "/api/infrastructure.php?action=apply", buildJsonObject { put("description", "Apply из Android-приложения") }).jsonObject
        val r = o["result"] as? JsonObject
        val code = r?.int("exit_code")
        if (code == 0) "Конфигурация применена" else "Apply завершился с кодом ${code ?: "?"}: ${r?.str("output")?.takeLast(500).orEmpty()}"
    }

    // ------------------------------------------------------------------ приложение (api/app.php)

    /** Что панель раздаёт для Android-приложения: APK, версия панели, хэши скриптов установки. */
    suspend fun appInfo(): PanelAppInfo = withContext(Dispatchers.IO) {
        val o = api("GET", "/api/app.php").jsonObject
        val a = (o["app"] as? JsonObject)
        PanelAppInfo(
            release = a?.let {
                AppRelease(
                    versionCode = it["versionCode"]?.jsonPrimitive?.intOrNull ?: 0,
                    versionName = it["versionName"]?.jsonPrimitive?.contentOrNull.orEmpty(),
                    size = it["size"]?.jsonPrimitive?.contentOrNull?.toLongOrNull() ?: 0,
                    sha256 = it["sha256"]?.jsonPrimitive?.contentOrNull.orEmpty().lowercase(),
                    minSdk = it["minSdk"]?.jsonPrimitive?.intOrNull ?: 24,
                )
            },
            panelVersion = o["panelVersion"]?.jsonPrimitive?.contentOrNull,
            scripts = (o["scripts"] as? JsonObject)?.mapValues { it.value.jsonPrimitive.contentOrNull.orEmpty().lowercase() }.orEmpty(),
        )
    }

    /** Скрипт установки из deploy/provision панели (белый список на стороне панели). */
    suspend fun script(name: String): String = withContext(Dispatchers.IO) {
        getText("/api/app.php?action=script&name=" + java.net.URLEncoder.encode(name, "UTF-8"))
    }

    /** Скачать APK в файл с прогрессом. Размер и SHA-256 проверяет вызывающий. */
    suspend fun downloadApk(dest: java.io.File, onProgress: (Long, Long) -> Unit) = withContext(Dispatchers.IO) {
        get("/api/app.php?action=download").use { r ->
            if (r.code == 401 || (r.code in 300..399)) throw PanelException("Сессия панели истекла — войдите снова", 401)
            if (!r.isSuccessful) throw PanelException("Панель не отдала APK: HTTP ${r.code}", r.code)
            val body = r.body ?: throw PanelException("Пустой ответ")
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


    companion object {
        fun normalize(u: String): String {
            var s = u.trim().trimEnd('/')
            if (s.isEmpty()) return s
            if (!s.startsWith("http://") && !s.startsWith("https://")) s = "https://$s"
            return s
        }

        fun htmlDecode(s: String) = s.replace("&quot;", "\"").replace("&#039;", "'").replace("&lt;", "<").replace("&gt;", ">").replace("&amp;", "&")

        /** Ключ формата → человекочитаемо: "amneziawg@3" → "AmneziaWG через ретранслятор #3". */
        fun formatLabel(f: String): String {
            val (proto, via) = f.split('@').let { it[0] to it.getOrNull(1) }
            val name = when (proto) {
                "amneziawg" -> "AmneziaWG"; "wireguard" -> "WireGuard"; "vless" -> "VLESS+Reality"
                "xhttp" -> "VLESS+XHTTP"; "shadowsocks" -> "Shadowsocks"; "trojan" -> "Trojan"
                "hysteria2" -> "Hysteria2"; else -> proto
            }
            return if (via != null) "$name ⇢ ретранслятор #$via" else name
        }

        fun isTunnelFormat(f: String) = f.startsWith("amneziawg") || f.startsWith("wireguard")
    }
}

data class AppRelease(val versionCode: Int, val versionName: String, val size: Long, val sha256: String, val minSdk: Int)

data class PanelAppInfo(val release: AppRelease?, val panelVersion: String?, val scripts: Map<String, String>)
