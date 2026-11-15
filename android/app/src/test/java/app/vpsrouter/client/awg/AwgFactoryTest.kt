package app.vpsrouter.client.awg

import app.vpsrouter.client.data.AwgObfs
import org.amnezia.awg.config.Config
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class AwgFactoryTest {
    @Test fun randomObfsIsValid31Profile() {
        repeat(300) {
            val o = AwgFactory.randomObfs()
            assertTrue(o.isV3)
            listOf(o.s1, o.s2, o.s3, o.s4).forEach { assertTrue("S ≥ 12: $o", it >= 12) }
            assertEquals(3, setOf(148 + o.s1, 92 + o.s2, 64 + o.s3).size)
            assertTrue(o.s4 != 16)
            assertTrue(o.jmax >= o.jmin)
            assertEquals(listOf(1L, 2L, 3L, 4L), listOf(o.h1, o.h2, o.h3, o.h4))
            assertEquals(32, java.util.Base64.getDecoder().decode(o.hpk).size)
            assertTrue(Regex("""^(<(b 0x[0-9a-f]+|r \d+)>)+$""").matches(o.i1!!))
        }
    }

    /** Конфиг сервера и клиента разбирает тот же парсер amneziawg-android 3.1, что поднимает туннель. */
    @Test fun configsParseWithAmneziaWg31() {
        val s = AwgFactory.newServer(port = 47321).let { it.copy(peers = listOf(AwgFactory.newPeer(it, "phone"))) }
        val client = AwgFactory.clientConfig(s, s.peers[0], "203.0.113.5")
        val cfg = Config.parse(client.byteInputStream())
        val uapi = cfg.`interface`.toAwgUserspaceString()
        assertTrue(uapi.contains("header_protection_key=" + hex(java.util.Base64.getDecoder().decode(s.obfs.hpk))))
        assertTrue(uapi.contains("s4=${s.obfs.s4}"))
        assertTrue(uapi.contains("content_padding_addition=${s.obfs.padding}"))
        assertTrue(uapi.contains("i1=${s.obfs.i1}"))
        assertTrue(cfg.peers[0].toAwgUserspaceString().contains("persistent_keepalive_interval=22-30"))
        assertTrue(client.contains("MTU = 1280"))

        // Серверный конфиг: те же параметры обфускации, PostUp с подставляемым интерфейсом.
        val server = AwgFactory.serverConfig(s)
        assertTrue(server.contains("HeaderProtectionKey = ${s.obfs.hpk}"))
        assertTrue(server.contains("__WAN_IF__"))
    }

    /** Серверы, созданные до 0.9.0, сохранены без новых полей — их конфиг не меняется. */
    @Test fun oldProfileStaysCompatible() {
        val old = AwgObfs(jc = 5, jmin = 40, jmax = 90, s1 = 20, s2 = 30, h1 = 111, h2 = 222, h3 = 333, h4 = 444)
        assertFalse(old.isV3)
        assertEquals("Jc = 5\nJmin = 40\nJmax = 90\nS1 = 20\nS2 = 30\nH1 = 111\nH2 = 222\nH3 = 333\nH4 = 444\n", old.lines())
        assertEquals("25", old.keepalive)
        val json = """{"jc":5,"jmin":40,"jmax":90,"s1":20,"s2":30,"h1":111,"h2":222,"h3":333,"h4":444}"""
        assertEquals(old, kotlinx.serialization.json.Json.decodeFromString(AwgObfs.serializer(), json))
    }

    private fun hex(b: ByteArray) = b.joinToString("") { "%02x".format(it) }
}
