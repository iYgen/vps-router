package app.vpsrouter.client.tunnel

import app.vpsrouter.client.data.AppSettings
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File
import java.util.Base64

class ShareLinksTest {
    private fun o(s: String) = Json.parseToJsonElement(s).jsonObject
    private val uuid = "0b6b6a63-1f7e-4d55-9f43-2c6a8f0b5a11"

    private val samples = mapOf(
        "vless-reality" to "vless://$uuid@203.0.113.5:443?encryption=none&flow=xtls-rprx-vision&security=reality&sni=www.microsoft.com&fp=chrome&pbk=jNXHt1yRo0vDuchQlIP6Z0ZvjT3KtzVI-T4E7RoLJS0&sid=0123abcd&type=tcp#%D0%9C%D0%BE%D0%B9%20VLESS",
        "vless-ws" to "vless://$uuid@cdn.example.com:443?security=tls&sni=cdn.example.com&type=ws&path=%2Fws&host=cdn.example.com#ws",
        "vless-grpc" to "vless://$uuid@g.example.com:443?security=tls&type=grpc&serviceName=gun#grpc",
        "trojan" to "trojan://secret@t.example.com:2053?sni=t.example.com&allowInsecure=1#Trojan",
        "ss" to "ss://" + Base64.getUrlEncoder().withoutPadding().encodeToString("2022-blake3-aes-128-gcm:AAAAAAAAAAAAAAAAAAAAAA==".toByteArray()) + "@203.0.113.7:9443#SS",
        "ss-plain" to "ss://chacha20-ietf-poly1305:pass%40word@203.0.113.8:8388#plain",
        "hy2" to "hy2://pw@h.example.com:8443?sni=h.example.com&obfs=salamander&obfs-password=ob&insecure=1#HY2",
        "tuic" to "tuic://$uuid:pw@tu.example.com:443?congestion_control=bbr&sni=tu.example.com&alpn=h3#TUIC",
        "vmess" to "vmess://" + Base64.getEncoder().encodeToString("""{"v":"2","ps":"VM","add":"vm.example.com","port":"443","id":"$uuid","aid":"0","net":"ws","path":"/v","host":"vm.example.com","tls":"tls","sni":"vm.example.com"}""".toByteArray()),
    )

    @Test fun parsesAllSchemes() {
        val vr = ShareLinks.parse(samples["vless-reality"]!!)
        assertEquals("Мой VLESS", vr.name)
        val v = o(vr.outbound)
        assertEquals("vless", v["type"]!!.jsonPrimitive.content)
        assertEquals("xtls-rprx-vision", v["flow"]!!.jsonPrimitive.content)
        assertEquals("0123abcd", v["tls"]!!.jsonObject["reality"]!!.jsonObject["short_id"]!!.jsonPrimitive.content)
        assertEquals("VLESS+Reality", ShareLinks.protocolOf(vr.outbound))

        assertEquals("ws", o(ShareLinks.parse(samples["vless-ws"]!!).outbound)["transport"]!!.jsonObject["type"]!!.jsonPrimitive.content)
        val ss = o(ShareLinks.parse(samples["ss"]!!).outbound)
        assertEquals("2022-blake3-aes-128-gcm", ss["method"]!!.jsonPrimitive.content)
        assertEquals(9443, ss["server_port"]!!.jsonPrimitive.content.toInt())
        assertEquals("pass@word", o(ShareLinks.parse(samples["ss-plain"]!!).outbound)["password"]!!.jsonPrimitive.content)
        assertEquals("salamander", o(ShareLinks.parse(samples["hy2"]!!).outbound)["obfs"]!!.jsonObject["type"]!!.jsonPrimitive.content)
        assertEquals("VM", ShareLinks.parse(samples["vmess"]!!).name)
        assertEquals("TUIC", ShareLinks.protocolOf(ShareLinks.parse(samples["tuic"]!!).outbound))
    }

    @Test fun xhttpGoesToXray() {
        val p = ShareLinks.parse("vless://$uuid@x.example.com:443?security=tls&sni=x.example.com&fp=edge&type=xhttp&path=%2Fabc&mode=packet-up#X")
        assertTrue(BoxConfig.isXray(p.outbound))
        val ss = o(p.outbound)["streamSettings"]!!.jsonObject
        assertEquals("packet-up", ss["xhttpSettings"]!!.jsonObject["mode"]!!.jsonPrimitive.content)
        assertEquals("VLESS+XHTTP", ShareLinks.protocolOf(p.outbound))
    }

    @Test fun subscriptionBase64() {
        val sub = Base64.getEncoder().encodeToString((samples["trojan"] + "\n" + samples["hy2"]).toByteArray())
        assertEquals(2, ShareLinks.findAll(sub).size)
        assertEquals(3, ShareLinks.findAll(samples.values.take(3).joinToString("\n")).size)
    }

    @Test fun jsonFullConfigPicksProxy() {
        val full = """{"outbounds":[{"type":"direct","tag":"direct"},{"type":"trojan","tag":"x","server":"a.b","server_port":1,"password":"p"}]}"""
        val out = o(ShareLinks.outboundFromJson(full))
        assertEquals("trojan", out["type"]!!.jsonPrimitive.content)
        assertTrue(!out.containsKey("tag"))
    }

    @Test fun fieldsRoundTrip() {
        val src = ShareLinks.parse(samples["vless-ws"]!!).outbound
        val f = OutboundFields.read(src)
        assertEquals("cdn.example.com", f.server); assertEquals("/ws", f.path)
        val w = o(OutboundFields.write(src, f.copy(server = "1.2.3.4", port = "8443", path = "/new", fingerprint = "firefox")))
        assertEquals("1.2.3.4", w["server"]!!.jsonPrimitive.content)
        assertEquals(8443, w["server_port"]!!.jsonPrimitive.content.toInt())
        assertEquals("/new", w["transport"]!!.jsonObject["path"]!!.jsonPrimitive.content)
        assertEquals("firefox", w["tls"]!!.jsonObject["utls"]!!.jsonObject["fingerprint"]!!.jsonPrimitive.content)

        val x = ShareLinks.parse("vless://$uuid@x.example.com:443?security=tls&type=xhttp&path=%2Fa#X").outbound
        val xw = o(OutboundFields.write(x, OutboundFields.read(x).copy(mode = "stream-one", port = "2053")))
        val vn = xw["settings"]!!.jsonObject["vnext"]!!.jsonArray[0].jsonObject
        assertEquals(2053, vn["port"]!!.jsonPrimitive.content.toInt())
        assertEquals("stream-one", xw["streamSettings"]!!.jsonObject["xhttpSettings"]!!.jsonObject["mode"]!!.jsonPrimitive.content)
    }

    /** Полные конфиги — для проверки настоящим ядром: sing-box check -c build/links/<имя>.json */
    @Test fun dumpForCoreCheck() {
        val dir = File("build/links").apply { mkdirs() }
        samples.forEach { (k, l) ->
            File(dir, "$k.json").writeText(BoxConfig.build(ShareLinks.parse(l).outbound, AppSettings(mtu = 1380)))
        }
    }
}
