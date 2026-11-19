package app.vpsrouter.client.tunnel

import android.content.Context
import app.vpsrouter.client.data.AppSettings
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import kotlinx.serialization.json.putJsonArray
import java.io.File

/**
 * «Только РФ для приложений»: выбранные российские приложения никогда не ходят через
 * VPN-сервер. Российские адреса — напрямую с телефона (настоящий российский IP),
 * зарубежные — в режиме наблюдения тоже напрямую (только записываются в журнал),
 * в режиме запрета — блокируются.
 *
 * Решение видно по тегу outbound'а — по нему журнал (AppTrafficLog) понимает,
 * что произошло с соединением.
 */
object RfOnly {
    const val TAG_RU = "rfo-ru"           // российский адрес → напрямую
    const val TAG_ALLOW = "rfo-allow"     // разрешено вручную / сервис проверки IP → напрямую
    const val TAG_FOREIGN = "rfo-foreign" // зарубежный, режим наблюдения → напрямую
    const val TAG_BLOCK = "rfo-block"     // зарубежный, режим запрета → тупик
    const val RULE_SET = "rfo-ru-ip"
    val TAGS = setOf(TAG_RU, TAG_ALLOW, TAG_FOREIGN, TAG_BLOCK)

    /** Порт-тупик на 127.0.0.1: никто не слушает, соединение сразу отбивается (и при этом попадает в журнал). */
    private const val DEAD_END_PORT = 9

    /** Российские доменные зоны. */
    val RU_ZONES = listOf("ru", "su", "xn--p1ai", "xn--d1acj3b", "xn--80adxhks", "moscow", "tatar")

    /** Российские сервисы на доменах вне .ru (синхронно с панелью: RfOnlyGuard::RU_DOMAINS). */
    val RU_DOMAINS = listOf(
        "2ip.ru", "4pda.to", "aeroflot.ru", "api.vigo.one", "arb.ru", "avatars.vkuseraudio.net",
        "bank-wb.ru", "bank-wildberries.ru", "belparking.ru", "cbr.ru", "cdn.uxfeedback.ru", "cdnwb.ru",
        "cian.ru", "cwer.ru", "dom.ru", "dzen.ru", "energosale34.ru", "etm.ru",
        "eysk.bezformata.com", "gosuslugi.ru", "gov.ru", "habr.com", "ivi.ru", "keenetic.ru",
        "kinopoisk.ru", "kion.ru", "komus.ru", "kontur.ru", "mail.ru", "mos.ru",
        "mosenergosbyt.ru", "mosreg.ru", "mvideo.ru", "nalog.ru", "okko.ru", "ozon.app",
        "ozon.com", "ozon.dev", "ozon.global", "ozon.kz", "ozon.market", "ozon.ru",
        "ozon.travel", "pic.rutubelist.ru", "premier.one", "river-3-354.rtbcdn.ru", "rt.ru", "rts-tender.ru",
        "rutube.com", "rutube.net", "rutube.ru", "rzd.ru", "sberbank.ru", "seven-sky.net",
        "skbkontur.ru", "smotreshka.tv", "smotrim.ru", "tinkoff.ru", "tricolor.tv", "userapi.com",
        "viju.ru", "vitrina.tv", "vk.cc", "vk.com", "vk.me", "vk.ru",
        "vkhost.github.io", "vkontakte.ru", "vkplay.ru", "vlg-media.ru", "volgaparking.ru", "volgograd.ru",
        "volgograd-trv.ru", "vpravda.ru", "wb.ru", "wb-bank.ru", "wbfamily.ru", "wbpay.ru",
        "widget-api.uxfeedback.ru", "wildberries.am", "wildberries.by", "wildberries.kg", "wildberries.kz", "wildberries.md",
        "wildberries.ru", "wildberries.uz", "wildpay.ru", "wink.ru", "xn--90aijkdmaud0d.xn--p1ai", "xn--b1aew.xn--p1ai",
        "xn--c1aapkosapc.xn--p1ai", "ya.ru", "yandex.com", "yandex.net", "yandex.ru", "yandex.st",
        "yastatic.net"
    )

    /**
     * Сервисы проверки IP: для «только РФ»-приложений идут напрямую, т.е. видят
     * настоящий российский адрес телефона (синхронно с панелью: RfOnlyGuard::IP_CHECK_DOMAINS).
     */
    val IP_CHECK_DOMAINS = listOf(
        "ipwho.is", "ipwhois.app", "ipwhois.io", "ipinfo.io", "ipapi.co", "ip-api.com",
        "ipapi.com", "ipapi.is", "ipgeolocation.io", "ipdata.co", "ipregistry.co", "ipstack.com",
        "ipbase.com", "iplocate.io", "ip2location.io", "ip2location.com", "db-ip.com", "geojs.io",
        "extreme-ip-lookup.com", "freegeoip.app", "abstractapi.com", "geoip.maxmind.com", "iplocation.net", "tools.keycdn.com",
        "ipify.org", "ifconfig.me", "ifconfig.co", "icanhazip.com", "ident.me", "myip.com",
        "myexternalip.com", "checkip.amazonaws.com", "ip.sb", "seeip.org", "wtfismyip.com", "ipecho.net",
        "my-ip.io", "checkip.dyndns.org", "whatismyipaddress.com", "whatismyip.com", "ipleak.net", "browserleaks.com",
        "whoer.net", "2ip.ru", "2ip.io", "myip.ru", "ipqualityscore.com", "proxycheck.io",
        "getipintel.net", "vpnapi.io", "iphub.info", "scamalytics.com", "spur.us", "ip-score.com",
        "ipinfo.app", "fraudguard.io", "iphunter.info"
    )

    /** Приложения, к которым правило реально применимо (скрытые от VPN идут мимо туннеля целиком). */
    fun apps(s: AppSettings): List<String> = s.rfOnlyApps.filterNot { it in s.hiddenApps }.distinct()

    /** Разрешённые вручную: домены и IP/подсети. */
    fun allowDomains(s: AppSettings) = s.rfOnlyAllow.filterNot { isIpOrCidr(it) }
    fun allowCidrs(s: AppSettings) = s.rfOnlyAllow.filter { isIpOrCidr(it) }.map { if ('/' in it) it else if (':' in it) "$it/128" else "$it/32" }

    fun isIpOrCidr(v: String) = Regex("""^[0-9a-fA-F:.]+(/\d{1,3})?$""").matches(v) && (v.count { it == '.' } == 3 || ':' in v)

    /**
     * Файл rule-set'а с российскими подсетями (из assets) в каталоге ядра.
     * Перезаписывается, если в новой версии приложения список изменился.
     */
    fun ruleSetFile(ctx: Context): File {
        val dir = File(ctx.filesDir, "box").apply { mkdirs() }
        val out = File(dir, "rfo-ru-ip.json")
        val src = ctx.assets.open("rfonly/ru-ip.txt").bufferedReader().readText()
        val json = sourceRuleSet(src)
        if (!out.exists() || out.readText() != json) out.writeText(json)
        return out
    }

    /** Текст «по подсети в строке» → rule-set sing-box в формате source. */
    fun sourceRuleSet(text: String): String {
        val cidrs = text.lineSequence().map { it.trim() }.filter { it.isNotEmpty() && !it.startsWith("#") }.toList()
        val o = buildJsonObject {
            put("version", 2)
            putJsonArray("rules") { add(buildJsonObject { put("ip_cidr", JsonArray(cidrs.map(::JsonPrimitive))) }) }
        }
        return o.toString()
    }

    fun ruleSetDef(path: String) = buildJsonObject {
        put("type", "local"); put("tag", RULE_SET); put("format", "source"); put("path", path)
    }

    fun outbounds(): List<JsonObject> = listOf(
        buildJsonObject { put("type", "direct"); put("tag", TAG_RU) },
        buildJsonObject { put("type", "direct"); put("tag", TAG_ALLOW) },
        buildJsonObject { put("type", "direct"); put("tag", TAG_FOREIGN) },
        buildJsonObject {
            put("type", "socks"); put("tag", TAG_BLOCK); put("server", "127.0.0.1"); put("server_port", DEAD_END_PORT)
        },
    )

    /** Правила маршрутизации (ставятся после sniff/DNS/локальной сети, до final=proxy). */
    fun rules(s: AppSettings): List<JsonObject> {
        val apps = apps(s)
        if (apps.isEmpty()) return emptyList()
        val pkgs = JsonArray(apps.map(::JsonPrimitive))
        val out = mutableListOf<JsonObject>()
        val allowDom = (IP_CHECK_DOMAINS + allowDomains(s)).distinct()
        val allowIp = allowCidrs(s)
        out += buildJsonObject {
            put("package_name", pkgs)
            put("domain_suffix", JsonArray(allowDom.map(::JsonPrimitive)))
            if (allowIp.isNotEmpty()) put("ip_cidr", JsonArray(allowIp.map(::JsonPrimitive)))
            put("outbound", TAG_ALLOW)
        }
        out += buildJsonObject {
            put("package_name", pkgs)
            put("domain_suffix", JsonArray((RU_ZONES + RU_DOMAINS).map(::JsonPrimitive)))
            put("rule_set", JsonArray(listOf(JsonPrimitive(RULE_SET))))
            put("outbound", TAG_RU)
        }
        out += buildJsonObject {
            put("package_name", pkgs)
            put("outbound", if (s.rfOnlyBlock) TAG_BLOCK else TAG_FOREIGN)
        }
        return out
    }

    /** Нужен ли rule-set российских подсетей (хоть одно из правил включено). */
    fun needsRuleSet(s: AppSettings) = s.ruDirect || apps(s).isNotEmpty()

    /**
     * «Российские сайты мимо VPN» для всех приложений в туннеле (после правил «Только РФ»):
     * исключения — через VPN, российское и проверки IP — напрямую.
     */
    fun ruDirectRules(s: AppSettings): List<JsonObject> {
        if (!s.ruDirect) return emptyList()
        val out = mutableListOf<JsonObject>()
        val except = s.ruDirectExcept.map { it.trim().lowercase() }.filter { it.isNotEmpty() }.distinct()
        if (except.isNotEmpty()) out += buildJsonObject {
            put("domain_suffix", JsonArray(except.map(::JsonPrimitive))); put("outbound", "proxy")
        }
        out += buildJsonObject {
            put("domain_suffix", JsonArray((RU_ZONES + RU_DOMAINS + IP_CHECK_DOMAINS).map(::JsonPrimitive)))
            put("rule_set", JsonArray(listOf(JsonPrimitive(RULE_SET))))
            put("outbound", "direct")
        }
        return out
    }

    /** Что журнал покажет по тегу outbound'а. */
    enum class Verdict(val label: String) {
        RU("Россия, напрямую"), ALLOWED("Разрешено, напрямую"), FOREIGN("Зарубежный, пропущен"), BLOCKED("Зарубежный, заблокирован");
        companion object {
            fun of(tag: String): Verdict? = when (tag) {
                TAG_RU -> RU; TAG_ALLOW -> ALLOWED; TAG_FOREIGN -> FOREIGN; TAG_BLOCK -> BLOCKED; else -> null
            }
        }
    }

    /** Что добавить в «разрешённые» для адреса из журнала: домен второго уровня (или IP). */
    fun allowKey(host: String): String {
        val h = host.trim().trimEnd('.').lowercase()
        if (isIpOrCidr(h)) return h
        val parts = h.split('.')
        if (parts.size <= 2) return h
        // co.uk, com.tr и т.п. — берём три уровня
        val sld = parts[parts.size - 2]
        val n = if (sld.length <= 3 && sld in setOf("co", "com", "net", "org", "gov", "edu", "ac")) 3 else 2
        return parts.takeLast(n).joinToString(".")
    }
}
