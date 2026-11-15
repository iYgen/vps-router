package app.vpsrouter.client.tunnel

import app.vpsrouter.client.data.TunnelProfile
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class AutoSwitchTest {
    private fun p(fmt: String, via: String? = null) =
        TunnelProfile(id = fmt + (via ?: ""), name = "Phone", config = "", kind = if (fmt.startsWith("amnezia")) "awg" else "singbox",
            source = "panel", origin = "panel:7:$fmt" + (via?.let { "@1" } ?: ""), via = via)

    private val reality = p("vless")
    private val awg = p("amneziawg")
    private val xhttp = p("xhttp")
    private val hy2 = p("hysteria2")
    private val ss = p("shadowsocks")
    private val realityRelay = p("vless", "Relay-1")
    private val all = listOf(ss, hy2, realityRelay, xhttp, awg, reality)

    @Test fun laddersStrongDirectThenRelayThenWeak() {
        val now = 1_000_000L
        val failed = mutableMapOf<String, Long>()
        val order = mutableListOf(reality)
        var cur = reality
        while (true) {
            failed[cur.id] = now
            cur = AutoSwitch.next(all, cur, failed, now) ?: break
            order += cur
        }
        assertEquals(listOf(reality, awg, xhttp, realityRelay, hy2, ss), order)
    }

    @Test fun failedTypesComeBackAfterCooldown() {
        val now = 10_000_000L
        val failed = mapOf(awg.id to now - 1000, xhttp.id to now - AutoSwitch.COOLDOWN_MS - 1)
        assertEquals(xhttp, AutoSwitch.next(listOf(reality, awg, xhttp), reality, failed, now))
        assertNull(AutoSwitch.next(listOf(reality, awg), reality, mapOf(awg.id to now), now))
    }

    @Test fun startsFromAnyLevelAndWrapsAround() {
        // Пользователь выбрал Shadowsocks (последний уровень) — следующий берётся с начала лестницы.
        assertEquals(reality, AutoSwitch.next(all, ss, mapOf(ss.id to 0L), 1L))
    }
}
