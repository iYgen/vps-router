package app.vpsrouter.client.awg

import app.vpsrouter.client.data.AwgObfs
import app.vpsrouter.client.data.AwgPeer
import app.vpsrouter.client.data.AwgServerState
import org.amnezia.awg.crypto.Key
import org.amnezia.awg.crypto.KeyPair
import java.security.SecureRandom

/** Ключи, параметры обфускации и конфиги AmneziaWG — то, что в панели делают AmneziaConfigBuilder/AwgParams. */
object AwgFactory {
    private val rnd = SecureRandom()

    fun keyPair(): Pair<String, String> {
        val kp = KeyPair()
        return kp.privateKey.toBase64() to kp.publicKey.toBase64()
    }

    fun presharedKey(): String {
        val b = ByteArray(32).also { rnd.nextBytes(it) }
        return Key.fromBytes(b).toBase64()
    }

    fun publicFromPrivate(priv: String): String = KeyPair(Key.fromBase64(priv)).publicKey.toBase64()

    /**
     * Случайные параметры профиля AmneziaWG 3.1 (правила как в panel/src/Amnezia/AwgParams::validate).
     * - S1..S4 ≥ 12: из начала паддинга берётся nonce шифра заголовков (amneziawg-go это проверяет).
     * - Размеры сообщений рукопожатия (148+S1, 92+S2, 64+S3) не должны совпадать.
     * - H1..H4 = 1..4: тип сообщения всё равно скрыт шифрованием заголовков.
     * - RandomTrailers не включаем: в тестах он резал скорость до ~0.1 МБ/с.
     */
    fun randomObfs(): AwgObfs {
        val jc = 3 + rnd.nextInt(4)          // 3..6 — мобильные сети не любят длинные серии
        val jmin = 40 + rnd.nextInt(30)      // 40..69
        val jmax = jmin + 30 + rnd.nextInt(120)
        var s1: Int; var s2: Int; var s3: Int
        do {
            s1 = 15 + rnd.nextInt(136)       // 15..150
            s2 = 15 + rnd.nextInt(136)
            s3 = 12 + rnd.nextInt(45)        // 12..56
        } while (setOf(148 + s1, 92 + s2, 64 + s3).size < 3)
        var s4: Int
        do { s4 = 12 + rnd.nextInt(21) } while (s4 == 16)   // 12..32
        val padLo = 16 + rnd.nextInt(32)
        return AwgObfs(
            jc, jmin, jmax, s1, s2, 1, 2, 3, 4, s3, s4,
            hpk = headerProtectionKey(),
            padding = "$padLo-${padLo + 64 + rnd.nextInt(48)}",
            i1 = dnsLikeI1(),
        )
    }

    fun headerProtectionKey(): String = Key.fromBytes(ByteArray(32).also { rnd.nextBytes(it) }).toBase64()

    private val dnsNames = listOf("yandex.ru", "vk.com", "mail.ru", "ozon.ru", "avito.ru", "ya.ru", "dzen.ru", "rutube.ru")

    /**
     * I1 в виде ответа DNS на A-запрос популярного домена: случайный ID, флаги ответа,
     * вопрос, две A-записи со случайными адресами и EDNS. Пакет из одних случайных байт
     * часть мобильных сетей отбрасывает, а «похожий на что-то» проходит.
     */
    fun dnsLikeI1(): String {
        val name = dnsNames[rnd.nextInt(dnsNames.size)]
        val q = buildString {
            name.split('.').forEach { l -> append("%02x".format(l.length)); l.forEach { append("%02x".format(it.code)) } }
            append("00" + "0001" + "0001")
        }
        fun ttl() = "%08x".format(60 + rnd.nextInt(3540))
        return "<r 2><b 0x81800001000200000001$q>" +
            "<b 0xc00c00010001${ttl()}0004><r 4>" +
            "<b 0xc00c00010001${ttl()}0004><r 4>" +
            "<b 0x0000291000000000000000>"
    }

    fun randomPort(): Int = 30000 + rnd.nextInt(30000)

    fun newServer(port: Int = randomPort(), obfs: AwgObfs = randomObfs()): AwgServerState {
        val (priv, pub) = keyPair()
        return AwgServerState(port = port, serverPrivateKey = priv, serverPublicKey = pub, obfs = obfs)
    }

    fun nextPeerIp(s: AwgServerState): String {
        val used = s.peers.map { it.ip.substringAfterLast('.').toInt() }.toSet()
        val n = (2..254).firstOrNull { it !in used } ?: throw IllegalStateException("Закончились адреса в сети ${s.subnetPrefix}.0/24")
        return "${s.subnetPrefix}.$n"
    }

    fun newPeer(s: AwgServerState, name: String): AwgPeer {
        val (priv, pub) = keyPair()
        return AwgPeer(name = name, privateKey = priv, publicKey = pub, presharedKey = presharedKey(), ip = nextPeerIp(s))
    }

    /** Конфиг сервера. __WAN_IF__ подставляет скрипт на сервере (интерфейс в интернет). */
    fun serverConfig(s: AwgServerState): String = buildString {
        append("# Managed by VPS Router Android. Ручные правки будут перезаписаны.\n")
        append("[Interface]\n")
        append("PrivateKey = ${s.serverPrivateKey}\n")
        append("Address = ${s.subnetPrefix}.1/24\n")
        append("ListenPort = ${s.port}\n")
        append(s.obfs.lines())
        append("PostUp = iptables -I FORWARD -i %i -j ACCEPT; iptables -I FORWARD -o %i -j ACCEPT; iptables -t nat -A POSTROUTING -s ${s.subnetPrefix}.0/24 -o __WAN_IF__ -j MASQUERADE\n")
        append("PostDown = iptables -D FORWARD -i %i -j ACCEPT; iptables -D FORWARD -o %i -j ACCEPT; iptables -t nat -D POSTROUTING -s ${s.subnetPrefix}.0/24 -o __WAN_IF__ -j MASQUERADE\n")
        for (p in s.peers) {
            append("\n# ${p.name.replace('\n', ' ')}\n[Peer]\n")
            append("PublicKey = ${p.publicKey}\n")
            append("PresharedKey = ${p.presharedKey}\n")
            append("AllowedIPs = ${p.ip}/32\n")
        }
    }

    fun clientConfig(s: AwgServerState, p: AwgPeer, endpointHost: String): String = buildString {
        append("[Interface]\n")
        append("PrivateKey = ${p.privateKey}\n")
        append("Address = ${p.ip}/32\n")
        append("DNS = ${s.dns}\n")
        append("MTU = ${s.mtu}\n")
        append(s.obfs.lines())
        append("\n[Peer]\n")
        append("PublicKey = ${s.serverPublicKey}\n")
        append("PresharedKey = ${p.presharedKey}\n")
        append("AllowedIPs = 0.0.0.0/0, ::/0\n")
        append("Endpoint = ${hostForEndpoint(endpointHost)}:${s.port}\n")
        append("PersistentKeepalive = ${s.obfs.keepalive}\n")
    }

    private fun hostForEndpoint(h: String) = if (h.contains(':') && !h.startsWith("[")) "[$h]" else h

    /** Копия конфига с Endpoint, направленным на ретранслятор (порт тот же — relay пересылает его как есть). */
    fun withEndpointHost(config: String, newHost: String): String =
        config.lines().joinToString("\n") { line ->
            val m = Regex("""^\s*Endpoint\s*=\s*(.+)$""", RegexOption.IGNORE_CASE).find(line)
            if (m == null) line else {
                val ep = m.groupValues[1].trim()
                val port = ep.substringAfterLast(':')
                "Endpoint = ${hostForEndpoint(newHost)}:$port"
            }
        }

    /** Endpoint из конфига: host и порт (для подсказок при создании ретранслятора). */
    fun endpointOf(config: String): Pair<String, Int>? {
        val line = config.lines().firstOrNull { Regex("""^\s*Endpoint\s*=""", RegexOption.IGNORE_CASE).containsMatchIn(it) } ?: return null
        val ep = line.substringAfter('=').trim()
        val host = ep.substringBeforeLast(':').removePrefix("[").removeSuffix("]")
        val port = ep.substringAfterLast(':').toIntOrNull() ?: return null
        return host to port
    }
}
