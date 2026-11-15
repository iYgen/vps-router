package app.vpsrouter.client.tunnel

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import java.io.InputStream
import java.net.InetSocketAddress
import java.net.Socket
import javax.net.ssl.SSLSocket
import javax.net.ssl.SSLSocketFactory

/**
 * Проверка, что через туннель реально ходит трафик — а не только «включено».
 *
 * Две пробы: короткий ответ (generate_204) и скачивание 64 КБ. ТСПУ часто не рвёт
 * соединение, а «замораживает» его после ~16 КБ: короткие запросы проходят, большие
 * зависают. Поэтому «короткий есть, длинного нет» — отдельный вердикт FREEZE.
 *
 * Запрос идёт либо через сеть VPN (сокет привязан к ней), либо — для XHTTP, где само
 * приложение выведено из туннеля, — через локальный SOCKS-вход Xray.
 */
object Probe {
    enum class Verdict { OK, FREEZE, DEAD, UNKNOWN }

    data class Target(val host: String, val path: String, val minBytes: Int)

    val SMALL = listOf(Target("www.gstatic.com", "/generate_204", 0), Target("cp.cloudflare.com", "/generate_204", 0))
    val LARGE = listOf(Target("speed.cloudflare.com", "/__down?bytes=65536", 60_000), Target("cachefly.cachefly.net", "/100kb.test", 60_000))

    /** Как открыть TCP-соединение до host:443 через туннель. */
    fun interface Opener { fun open(host: String, port: Int, timeoutMs: Int): Socket }

    /** Сокеты, привязанные к сети VPN; null — сети VPN нет. */
    fun vpnOpener(ctx: Context): Opener? {
        val cm = ctx.getSystemService(ConnectivityManager::class.java)
        @Suppress("DEPRECATION")
        val vpn = cm.allNetworks.firstOrNull { cm.getNetworkCapabilities(it)?.hasTransport(NetworkCapabilities.TRANSPORT_VPN) == true } ?: return null
        return Opener { host, port, t ->
            val addr = vpn.getByName(host)
            vpn.socketFactory.createSocket().apply { soTimeout = t; connect(InetSocketAddress(addr, port), t) }
        }
    }

    /** Через SOCKS5-вход Xray с логином (адрес резолвит сам Xray на сервере). */
    fun socksOpener(port: Int, user: String, pass: String) = Opener { host, dstPort, t ->
        val s = Socket()
        try {
            s.soTimeout = t
            s.connect(InetSocketAddress("127.0.0.1", port), t)
            val o = s.getOutputStream(); val i = s.getInputStream()
            o.write(byteArrayOf(5, 1, 2)); o.flush()
            require(readN(i, 2)[1].toInt() == 2) { "SOCKS: нет входа по паролю" }
            val u = user.toByteArray(); val p = pass.toByteArray()
            o.write(byteArrayOf(1, u.size.toByte()) + u + byteArrayOf(p.size.toByte()) + p); o.flush()
            require(readN(i, 2)[1].toInt() == 0) { "SOCKS: пароль не принят" }
            val h = host.toByteArray()
            o.write(byteArrayOf(5, 1, 0, 3, h.size.toByte()) + h + byteArrayOf((dstPort shr 8).toByte(), dstPort.toByte())); o.flush()
            val head = readN(i, 4)
            require(head[1].toInt() == 0) { "SOCKS: соединение отклонено (${head[1]})" }
            when (head[3].toInt()) { 1 -> readN(i, 6); 4 -> readN(i, 18); 3 -> readN(i, (readN(i, 1)[0].toInt() and 0xff) + 2) }
            s
        } catch (e: Exception) { runCatching { s.close() }; throw e }
    }

    private fun readN(i: InputStream, n: Int): ByteArray {
        val b = ByteArray(n); var off = 0
        while (off < n) { val r = i.read(b, off, n - off); if (r < 0) throw java.io.EOFException(); off += r }
        return b
    }

    /**
     * HTTPS GET; возвращает число байт тела (или -1, если ответ не 2xx).
     * Таймаут — на каждое чтение: «заморозка» = чтение зависло.
     */
    fun fetch(op: Opener, t: Target, timeoutMs: Int): Int {
        val raw = op.open(t.host, 443, timeoutMs)
        val tls = (SSLSocketFactory.getDefault() as SSLSocketFactory).createSocket(raw, t.host, 443, true) as SSLSocket
        tls.use { s ->
            s.soTimeout = timeoutMs
            s.startHandshake()
            s.outputStream.write("GET ${t.path} HTTP/1.1\r\nHost: ${t.host}\r\nUser-Agent: Mozilla/5.0\r\nAccept: */*\r\nConnection: close\r\n\r\n".toByteArray())
            s.outputStream.flush()
            val inp = s.inputStream
            val head = StringBuilder()
            while (!head.endsWith("\r\n\r\n")) { val c = inp.read(); if (c < 0) break; head.append(c.toChar()); if (head.length > 16384) break }
            val code = head.toString().substringAfter(' ').take(3).toIntOrNull() ?: return -1
            if (code !in 200..299) return -1
            val buf = ByteArray(16384); var total = 0
            while (true) { val r = inp.read(buf); if (r < 0) break; total += r; if (t.minBytes in 1..total) break }
            return total
        }
    }

    private fun anyOk(op: Opener, list: List<Target>, timeoutMs: Int): Boolean? {
        for (t in list) {
            try {
                val n = fetch(op, t, timeoutMs)
                if (n >= t.minBytes) return true
            } catch (e: java.net.SocketException) {
                // EPERM/ENETUNREACH до самого сервера: приложение не пускают в эту сеть (исключено из VPN).
                if (e.message?.contains("EPERM") == true || e.message?.contains("Permission denied") == true) return null
            } catch (_: Exception) {}
        }
        return false
    }

    /** Проверить туннель. UNKNOWN — проверить нельзя (приложение не ходит через VPN). */
    fun check(op: Opener?, timeoutMs: Int = 8000): Verdict {
        op ?: return Verdict.UNKNOWN
        val small = anyOk(op, SMALL, timeoutMs) ?: return Verdict.UNKNOWN
        val large = anyOk(op, LARGE, timeoutMs) ?: return Verdict.UNKNOWN
        return when {
            small && large -> Verdict.OK
            small -> Verdict.FREEZE
            large -> Verdict.OK
            else -> Verdict.DEAD
        }
    }

    /**
     * Есть ли интернет помимо VPN (обычная сеть телефона). Если нет — туннель не виноват,
     * переключать бессмысленно. Проверяем российские адреса: они доступны и при белых списках.
     */
    fun underlyingOnline(ctx: Context): Boolean {
        val cm = ctx.getSystemService(ConnectivityManager::class.java)
        @Suppress("DEPRECATION")
        val nets: List<Network> = cm.allNetworks.filter {
            val c = cm.getNetworkCapabilities(it)
            c != null && !c.hasTransport(NetworkCapabilities.TRANSPORT_VPN) && c.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
        }
        if (nets.isEmpty()) return false
        var denied = false
        val ok = nets.any { n ->
            listOf("77.88.8.8" to 53, "ya.ru" to 443).any { (h, p) ->
                try { n.socketFactory.createSocket().use { s -> s.connect(InetSocketAddress(n.getByName(h), p), 4000) }; true }
                catch (e: Exception) { if (e is SecurityException || e.message?.contains("EPERM") == true) denied = true; false }
            }
        }
        // Система не дала выйти мимо VPN — проверить нельзя, считаем, что сеть есть.
        return ok || denied
    }
}
