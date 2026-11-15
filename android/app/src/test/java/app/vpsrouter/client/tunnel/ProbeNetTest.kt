package app.vpsrouter.client.tunnel

import org.junit.Assert.assertEquals
import org.junit.Assume.assumeTrue
import org.junit.Test
import java.net.InetSocketAddress
import java.net.Socket

/** Сетевая проверка пробы (не в CI): PROBE_NET=1, для SOCKS ещё PROBE_SOCKS=порт:логин:пароль. */
class ProbeNetTest {
    @Test fun directAndSocks() {
        assumeTrue(System.getenv("PROBE_NET") == "1")
        val direct = Probe.Opener { h, p, t -> Socket().apply { soTimeout = t; connect(InetSocketAddress(h, p), t) } }
        assertEquals(Probe.Verdict.OK, Probe.check(direct))
        // Туннель не пропускает ничего — DEAD.
        val dead = Probe.Opener { _, _, _ -> throw java.net.SocketTimeoutException("timeout") }
        assertEquals(Probe.Verdict.DEAD, Probe.check(dead, 2000))
        System.getenv("PROBE_SOCKS")?.split(':')?.let { (port, u, pw) ->
            assertEquals(Probe.Verdict.OK, Probe.check(Probe.socksOpener(port.toInt(), u, pw)))
            assertEquals(Probe.Verdict.DEAD, Probe.check(Probe.socksOpener(port.toInt(), u, "wrong"), 3000))
        }
    }
}
