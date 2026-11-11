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

    /** Случайные, но валидные параметры (правила как в panel/src/Amnezia/AwgParams::validate). */
    fun randomObfs(): AwgObfs {
        val jc = 4 + rnd.nextInt(7)          // 4..10
        val jmin = 40 + rnd.nextInt(20)      // 40..59
        val jmax = jmin + 20 + rnd.nextInt(60)
        var s1: Int
        var s2: Int
        do {
            s1 = 15 + rnd.nextInt(136)       // 15..150
            s2 = 15 + rnd.nextInt(136)
        } while (s1 + 56 == s2)
        val hs = mutableSetOf<Long>()
        while (hs.size < 4) hs += 5L + rnd.nextInt(Int.MAX_VALUE - 5)
        val h = hs.toList()
        return AwgObfs(jc, jmin, jmax, s1, s2, h[0], h[1], h[2], h[3])
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
        append("PersistentKeepalive = 25\n")
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
