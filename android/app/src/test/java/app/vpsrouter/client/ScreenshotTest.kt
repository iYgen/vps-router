package app.vpsrouter.client

import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onAllNodesWithContentDescription
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.hasScrollAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.performScrollToNode
import app.vpsrouter.client.awg.AwgFactory
import app.vpsrouter.client.data.AppData
import app.vpsrouter.client.data.AppSettings
import app.vpsrouter.client.data.ConnTraffic
import app.vpsrouter.client.data.PanelRoute
import app.vpsrouter.client.data.RouteMode
import app.vpsrouter.client.data.ManagedServer
import app.vpsrouter.client.data.PanelCache
import app.vpsrouter.client.data.PanelConnection
import app.vpsrouter.client.data.PanelDevice
import app.vpsrouter.client.data.PanelProfile
import app.vpsrouter.client.data.PanelServer
import app.vpsrouter.client.data.PortSpec
import app.vpsrouter.client.data.RelayState
import app.vpsrouter.client.data.SecureStore
import app.vpsrouter.client.data.ServerRole
import app.vpsrouter.client.data.TunnelProfile
import com.github.takahirom.roborazzi.captureRoboImage
import kotlinx.serialization.json.Json
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/** В Robolectric нет AndroidKeyStore — состояние держим в памяти. */
class MemoryStore(context: android.content.Context, private val initial: String?) : SecureStore(context) {
    private val files = mutableMapOf<String, String>().apply { initial?.let { put("state.json", it) } }
    override fun write(name: String, plain: String) { files[name] = plain }
    override fun read(name: String): String? = files[name]
}

/** Приложение с пустым состоянием — первый запуск. */
class EmptyApp : App() {
    override fun createStore(): SecureStore = MemoryStore(this, null)
}

/**
 * Приложение с примером инфраструктуры для скриншотов: свой AWG-сервер с клиентами,
 * ретранслятор и кэш панели. Только для тестов — в самом приложении данных-заглушек нет.
 */
class SeededApp : App() {
    override fun createStore(): SecureStore {
        var awg = AwgFactory.newServer(port = 47321)
        val p1 = AwgFactory.newPeer(awg, "Pixel 8"); awg = awg.copy(peers = listOf(p1))
        val p2 = AwgFactory.newPeer(awg, "Ноутбук"); awg = awg.copy(peers = listOf(p1, p2))
        val nl = ManagedServer(id = "srv1", name = "Амстердам", host = "203.0.113.10", role = ServerRole.AWG, awg = awg, status = "online",
            privateKey = "-", hostKeyFp = "SHA256:Zx1Qb5mT0dKq3tVwXx9fP2aHcY7nRe4uLs6oB8iJkGg", lastCheckAt = System.currentTimeMillis() - 120_000,
            osInfo = "Ubuntu 24.04.1 LTS\n6.8.0-45-generic\nup 12 days, 3 hours\nawg: есть\nnftables: есть")
        val relay = ManagedServer(id = "srv2", name = "Белый IP (Москва)", host = "198.51.100.7", role = ServerRole.RELAY, status = "online",
            privateKey = "-", relay = RelayState("203.0.113.10", "Амстердам", "srv1", listOf(PortSpec(47321, "udp"))))
        val direct = TunnelProfile(id = "t1", name = "Амстердам · Pixel 8", config = AwgFactory.clientConfig(awg, p1, nl.host), source = "local", origin = "srv1/${p1.id}")
        val viaRelay = direct.copy(id = "t2", name = "Амстердам · Pixel 8 ⇢ Белый IP (Москва)", config = AwgFactory.withEndpointHost(direct.config, relay.host), via = relay.name)
        val fromPanel = TunnelProfile(id = "t3", name = "Мой телефон", config = AwgFactory.clientConfig(awg, p2, "panel.example.com"), source = "panel", origin = "panel:4:amneziawg")
        val panelRelay = fromPanel.copy(id = "t4", name = "Мой телефон ⇢ relay-1", origin = "panel:4:amneziawg@5", via = "relay-1")
        val panelVless = TunnelProfile(id = "t5", name = "Мой телефон", kind = "singbox", source = "panel", origin = "panel:4:vless",
            config = """{"type":"vless","server":"panel.example.com","server_port":443,"uuid":"u"}""")
        // Добавлено вручную по ссылке vless:// (как в v2rayTun).
        val manual = app.vpsrouter.client.tunnel.ShareLinks.parse("vless://0b6b6a63-1f7e-4d55-9f43-2c6a8f0b5a11@cdn.example.com:443?security=tls&sni=cdn.example.com&fp=chrome&type=ws&path=%2Fws#Финляндия").let {
            TunnelProfile(id = "t6", name = it.name, config = it.outbound, kind = "singbox")
        }
        val data = AppData(
            tunnels = listOf(direct, viaRelay, fromPanel, panelRelay, panelVless, manual),
            traffic = mapOf(
                "t3" to ConnTraffic(rx = 3_400_000_000, tx = 420_000_000, lastUsed = System.currentTimeMillis() - 5 * 3600_000),
                "t4" to ConnTraffic(rx = 810_000_000, tx = 95_000_000, lastUsed = System.currentTimeMillis() - 26 * 3600_000),
                "t1" to ConnTraffic(rx = 1_200_000_000, tx = 160_000_000, lastUsed = System.currentTimeMillis() - 600_000),
            ),
            servers = listOf(nl, relay),
            panel = PanelProfile("http://127.0.0.1:9", listOf("http://127.0.0.1:10"), "admin", appToken = "test-token", myDeviceId = 4),
            panelCache = PanelCache(
                fetchedAt = System.currentTimeMillis() - 3 * 3600_000,
                servers = listOf(
                    PanelServer(1, "Router RU", "router", "panel.example.com", "online", isSelf = true),
                    PanelServer(2, "Exit NL", "exit", "203.0.113.10", "online", country = "NL"),
                    PanelServer(3, "relay-1", "relay", "198.51.100.7", "warning"),
                ),
                devices = listOf(
                    PanelDevice(4, "Мой телефон", online = true, formats = listOf("amneziawg", "vless", "amneziawg@5", "vless@5")),
                    PanelDevice(6, "Телевизор", formats = listOf("vless", "hysteria2")),
                    PanelDevice(7, "Старый ноутбук", revoked = true),
                ),
                relays = listOf(PanelConnection(5, "relay", 3, 1, provisionStatus = "provisioned", ports = listOf("443/tcp", "38020/udp"))),
                routes = listOf(
                    PanelRoute(1, "YouTube", true, target = "Exit NL", rules = listOf("domain_suffix:youtube.com", "domain_suffix:googlevideo.com", "domain_suffix:ytimg.com")),
                    PanelRoute(2, "Банки и госуслуги", true, rules = listOf("domain_suffix:sberbank.ru", "domain_suffix:gosuslugi.ru")),
                    PanelRoute(3, "ChatGPT", false, target = "Exit NL", rules = listOf("domain_suffix:openai.com", "domain_suffix:chatgpt.com")),
                ),
            ),
            lastTunnelId = "t1",
            settings = AppSettings(onboarded = true, consentVersion = app.vpsrouter.client.ui.CONSENT_VERSION, githubUpdates = false, routeMode = RouteMode.EXCLUDE_APPS, apps = listOf("com.example.torrent"), bypassLan = true, hiddenApps = listOf("ru.sberbankmobile", "ru.gosuslugi")),
        )
        return MemoryStore(this, Json { encodeDefaults = true }.encodeToString(AppData.serializer(), data))
    }
}

private const val OUT = "../docs/screenshots"

private fun ComposeRule.shot(name: String) { waitForIdle(); onRoot().captureRoboImage("$OUT/$name.png") }
private fun ComposeRule.click(text: String) { onAllNodesWithText(text, useUnmergedTree = true)[0].performClick(); waitForIdle() }
private fun ComposeRule.clickLast(text: String) {
    val nodes = onAllNodesWithText(text, useUnmergedTree = true)
    nodes[nodes.fetchSemanticsNodes().size - 1].performClick(); waitForIdle()
}
private typealias ComposeRule = androidx.compose.ui.test.junit4.AndroidComposeTestRule<*, *>

@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w400dp-h860dp-xxhdpi", application = SeededApp::class)
class SeededScreenshotTest {
    @get:Rule val rule = createAndroidComposeRule<MainActivity>()

    @Test fun home() {
        rule.shot("01_home")
        rule.clickLast("Связи")
        rule.shot("05_links")
    }

    @Test fun picker() {
        rule.onAllNodesWithContentDescription("Типы соединения")[0].performClick(); rule.waitForIdle()
        rule.shot("04_connection_picker")
    }


    @Test fun manualConnection() {
        rule.click("Финляндия")
        rule.onAllNodes(hasScrollAction())[0].performScrollToNode(hasText("Финляндия"))
        rule.onAllNodesWithContentDescription("Подробнее")[0].performClick(); rule.waitForIdle()
        rule.shot("14_manual_fields")
    }

    @Test fun servers() {
        rule.clickLast("Серверы")
        rule.shot("02_servers")
        rule.click("Амстердам")
        rule.shot("08_server_awg")
    }

    @Test fun routes() {
        rule.clickLast("Маршруты")
        Thread.sleep(1500) // панель недоступна → маршруты из кэша
        rule.shot("03_routes")
    }

    @Test fun settings() {
        rule.clickLast("Настройки")
        rule.shot("06_settings")
        rule.onAllNodes(hasScrollAction())[0].performScrollToNode(hasText("Права и данные")); rule.click("Права и данные")
        rule.shot("13_permissions")
    }

    @Test fun panel() {
        rule.clickLast("Серверы")
        rule.click("Панель VPS Router")
        Thread.sleep(1500) // попытка входа в недоступную панель → автономный режим с кэшем
        rule.shot("07_panel_devices")
    }
}

@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w400dp-h860dp-xxhdpi", application = EmptyApp::class)
class FirstRunScreenshotTest {
    @get:Rule val rule = createAndroidComposeRule<MainActivity>()

    @Test fun firstRun() {
        rule.shot("00_consent")
        rule.click("Согласен")
        rule.shot("10_onboarding")
        rule.click("Подключить существующий сервер")
        rule.shot("11_onboarding_connect")
        rule.click("Панель VPS Router")
        rule.shot("12_panel_login_qr")
    }
}
