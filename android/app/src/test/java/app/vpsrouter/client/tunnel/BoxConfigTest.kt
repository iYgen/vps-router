package app.vpsrouter.client.tunnel

import app.vpsrouter.client.data.AppSettings
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File

class BoxConfigTest {
    private val vless = """{"type":"vless","server":"203.0.113.5","server_port":443,"uuid":"0b6b6a63-1f7e-4d55-9f43-2c6a8f0b5a11","flow":"xtls-rprx-vision","tls":{"enabled":true,"server_name":"www.microsoft.com","utls":{"enabled":true,"fingerprint":"edge"},"reality":{"enabled":true,"public_key":"jNXHt1yRo0vDuchQlIP6Z0ZvjT3KtzVI-T4E7RoLJS0","short_id":"0123abcd"}}}"""
    private val hy2 = """{"type":"hysteria2","server":"vpn.example.com","server_port":8443,"password":"p","tls":{"enabled":true,"server_name":"vpn.example.com","insecure":true},"obfs":{"type":"salamander","password":"o"}}"""

    private fun parse(s: String) = Json.parseToJsonElement(s).jsonObject

    @Test fun proxyOutboundTaggedAndFinal() {
        val c = parse(BoxConfig.build(vless, AppSettings()))
        val outs = c["outbounds"]!!.jsonArray.map { it.jsonObject }
        assertEquals("proxy", outs[0]["tag"]!!.jsonPrimitive.content)
        assertEquals("vless", outs[0]["type"]!!.jsonPrimitive.content)
        assertEquals("proxy", c["route"]!!.jsonObject["final"]!!.jsonPrimitive.content)
        val tun = c["inbounds"]!!.jsonArray[0].jsonObject
        assertEquals("tun", tun["type"]!!.jsonPrimitive.content)
        assertTrue(tun["address"]!!.jsonArray.size == 2)
    }

    @Test fun hiddenAppsExcluded() {
        val c = parse(BoxConfig.build(vless, AppSettings(hiddenApps = listOf("ru.mos.mesh"))))
        val tun = c["inbounds"]!!.jsonArray[0].jsonObject
        assertEquals(listOf("ru.mos.mesh"), (tun["exclude_package"] as JsonArray).map { it.jsonPrimitive.content })
        assertFalse(tun.containsKey("include_package"))
    }

    @Test fun lanBypassOptional() {
        val on = parse(BoxConfig.build(vless, AppSettings(bypassLan = true)))["inbounds"]!!.jsonArray[0].jsonObject
        val off = parse(BoxConfig.build(vless, AppSettings(bypassLan = false)))["inbounds"]!!.jsonArray[0].jsonObject
        assertTrue(on.containsKey("route_exclude_address"))
        assertFalse(off.containsKey("route_exclude_address"))
    }

    @Test fun endpoint() {
        assertEquals("vpn.example.com" to 8443, BoxConfig.endpointOf(hy2))
        assertEquals(null, BoxConfig.endpointOf("not json"))
    }

    /** Примеры конфигов — для проверки настоящим ядром: sing-box check -c build/box-*.json */
    @Test fun dumpSamples() {
        val dir = File("build/box-samples").apply { mkdirs() }
        File(dir, "vless.json").writeText(BoxConfig.build(vless, AppSettings(hiddenApps = listOf("ru.mos.mesh"))))
        File(dir, "hy2.json").writeText(BoxConfig.build(hy2, AppSettings()))
        assertTrue(parse(File(dir, "hy2.json").readText()) is JsonObject)
    }
}
