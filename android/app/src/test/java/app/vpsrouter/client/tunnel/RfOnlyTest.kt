package app.vpsrouter.client.tunnel

import app.vpsrouter.client.data.AppSettings
import app.vpsrouter.client.data.RouteMode
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.boolean
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File

class RfOnlyTest {
    private val vless = """{"type":"vless","server":"203.0.113.5","server_port":443,"uuid":"0b6b6a63-1f7e-4d55-9f43-2c6a8f0b5a11"}"""
    private fun parse(s: String) = Json.parseToJsonElement(s).jsonObject
    private val ozon = "ru.ozon.app.android"

    @Test fun noAppsNoRules() {
        val c = parse(BoxConfig.build(vless, AppSettings(ruDirect = false), rfRuleSetPath = "/x.json"))
        assertNull(c["route"]!!.jsonObject["rule_set"])
        assertFalse(c.toString().contains("rfo-"))
    }

    @Test fun observeModeRoutesForeignDirect() {
        val c = parse(BoxConfig.build(vless, AppSettings(rfOnlyApps = listOf(ozon)), rfRuleSetPath = "/x.json"))
        val route = c["route"]!!.jsonObject
        assertTrue(route["find_process"]!!.jsonPrimitive.boolean)
        assertEquals("rfo-ru-ip", route["rule_set"]!!.jsonArray[0].jsonObject["tag"]!!.jsonPrimitive.content)
        val rules = route["rules"]!!.jsonArray.map { it.jsonObject }.filter { it.containsKey("package_name") }
        assertEquals(listOf("rfo-allow", "rfo-ru", "rfo-foreign"), rules.map { it["outbound"]!!.jsonPrimitive.content })
        assertTrue(rules[0]["domain_suffix"]!!.jsonArray.any { it.jsonPrimitive.content == "ipwho.is" })
        assertTrue(rules[1]["domain_suffix"]!!.jsonArray.any { it.jsonPrimitive.content == "ru" })
        // Через VPN-сервер (proxy) эти приложения не идут никогда: последнее их правило ловит всё.
        assertFalse(rules.last().containsKey("domain_suffix"))
        assertEquals("proxy", route["final"]!!.jsonPrimitive.content)
        val tags = c["outbounds"]!!.jsonArray.map { it.jsonObject["tag"]!!.jsonPrimitive.content }
        assertTrue(tags.containsAll(RfOnly.TAGS))
    }

    @Test fun blockModeAndAllowList() {
        val s = AppSettings(rfOnlyApps = listOf(ozon), rfOnlyBlock = true, rfOnlyAllow = listOf("appsflyersdk.com", "3.174.113.99", "52.85.0.0/16"))
        val rules = parse(BoxConfig.build(vless, s, rfRuleSetPath = "/x.json"))["route"]!!.jsonObject["rules"]!!.jsonArray
            .map { it.jsonObject }.filter { it.containsKey("package_name") }
        assertEquals("rfo-block", rules.last()["outbound"]!!.jsonPrimitive.content)
        assertTrue(rules[0]["domain_suffix"]!!.jsonArray.any { it.jsonPrimitive.content == "appsflyersdk.com" })
        assertEquals(listOf("3.174.113.99/32", "52.85.0.0/16"), rules[0]["ip_cidr"]!!.jsonArray.map { it.jsonPrimitive.content })
    }

    @Test fun rfAppsAreKeptInsideTun() {
        // «Все, кроме выбранных» с Ozon в исключениях — для «Только РФ» его нужно оставить в TUN.
        val s = AppSettings(routeMode = RouteMode.EXCLUDE_APPS, apps = listOf(ozon, "com.bank"), rfOnlyApps = listOf(ozon))
        val tun = parse(BoxConfig.build(vless, s, rfRuleSetPath = "/x.json"))["inbounds"]!!.jsonArray[0].jsonObject
        assertEquals(listOf("com.bank"), (tun["exclude_package"] as JsonArray).map { it.jsonPrimitive.content })
        // «Только выбранные» — добавляем в список включённых.
        val s2 = AppSettings(routeMode = RouteMode.ONLY_APPS, onlyApps = listOf("org.browser"), rfOnlyApps = listOf(ozon))
        val tun2 = parse(BoxConfig.build(vless, s2, rfRuleSetPath = "/x.json"))["inbounds"]!!.jsonArray[0].jsonObject
        assertEquals(listOf("org.browser", ozon), (tun2["include_package"] as JsonArray).map { it.jsonPrimitive.content })
        // Скрытые от VPN — остаются снаружи, правило к ним не применяется.
        val s3 = AppSettings(hiddenApps = listOf(ozon), rfOnlyApps = listOf(ozon), ruDirect = false)
        assertFalse(BoxConfig.build(vless, s3, rfRuleSetPath = "/x.json").contains("rfo-"))
    }

    @Test fun ruleSetFromAsset() {
        val asset = File("src/main/assets/rfonly/ru-ip.txt").readText()
        val rs = parse(RfOnly.sourceRuleSet(asset))
        val cidrs = rs["rules"]!!.jsonArray[0].jsonObject["ip_cidr"]!!.jsonArray.map { it.jsonPrimitive.content }
        assertTrue(cidrs.size > 5000)
        assertTrue(cidrs.all { '/' in it })
        assertTrue(cidrs.none { it.startsWith("#") })
    }

    @Test fun ruDirectForAllApps() {
        val s = AppSettings(ruDirectExcept = listOf("Blocked-Media.ru"))
        val route = parse(BoxConfig.build(vless, s, rfRuleSetPath = "/x.json"))["route"]!!.jsonObject
        assertEquals("rfo-ru-ip", route["rule_set"]!!.jsonArray[0].jsonObject["tag"]!!.jsonPrimitive.content)
        assertNull(route["find_process"]) // по приложениям не разбираем — дешевле
        val rules = route["rules"]!!.jsonArray.map { it.jsonObject }
        val ex = rules.first { it["outbound"]?.jsonPrimitive?.content == "proxy" }
        assertEquals(listOf("blocked-media.ru"), ex["domain_suffix"]!!.jsonArray.map { it.jsonPrimitive.content })
        val direct = rules.last()
        assertEquals("direct", direct["outbound"]!!.jsonPrimitive.content)
        val doms = direct["domain_suffix"]!!.jsonArray.map { it.jsonPrimitive.content }
        assertTrue("ru" in doms && "ipwho.is" in doms && "mos.ru" in doms)
        assertTrue(rules.indexOf(ex) < rules.indexOf(direct))
        // «Только РФ» по приложениям — раньше общего правила.
        val both = parse(BoxConfig.build(vless, AppSettings(rfOnlyApps = listOf(ozon)), rfRuleSetPath = "/x.json"))["route"]!!.jsonObject["rules"]!!.jsonArray.map { it.jsonObject }
        assertTrue(both.indexOfFirst { it.containsKey("package_name") } < both.indexOfLast { it["outbound"]?.jsonPrimitive?.content == "direct" })
        // Без файла с подсетями правило не ставится.
        assertFalse(BoxConfig.build(vless, AppSettings()).contains("rfo-ru-ip"))
    }

    @Test fun allowKeyAndVerdicts() {
        assertEquals("appsflyersdk.com", RfOnly.allowKey("ezfweb.launches.appsflyersdk.com"))
        assertEquals("bbc.co.uk", RfOnly.allowKey("news.bbc.co.uk"))
        assertEquals("8.47.69.5", RfOnly.allowKey("8.47.69.5"))
        assertEquals(RfOnly.Verdict.BLOCKED, RfOnly.Verdict.of("rfo-block"))
        assertNull(RfOnly.Verdict.of("proxy"))
    }

    @Test fun logAggregates() {
        AppTrafficLog.clear()
        AppTrafficLog.record(ozon, "", "3.174.113.99:443", "rfo-foreign", now = 1)
        AppTrafficLog.record(ozon, "", "3.174.113.99:443", "rfo-foreign", now = 2)
        AppTrafficLog.record(ozon, "api.ozon.ru", "95.163.61.56:443", "rfo-ru", now = 3)
        AppTrafficLog.record(ozon, "x.com", "1.2.3.4:443", "proxy", now = 4) // не наше правило — пропуск
        val e = AppTrafficLog.entries.value
        assertEquals(2, e.size)
        assertEquals("api.ozon.ru", e[0].host)
        assertEquals(2, e[1].count)
        assertEquals("3.174.113.99", e[1].host)
    }
}
