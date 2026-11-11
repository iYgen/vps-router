package app.vpsrouter.client.diag

import app.vpsrouter.client.App
import app.vpsrouter.client.awg.AwgFactory
import app.vpsrouter.client.data.AppLog
import app.vpsrouter.client.data.ServerRole
import app.vpsrouter.client.panel.PanelClient
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.withContext
import org.amnezia.awg.backend.Tunnel
import java.net.InetAddress
import java.net.InetSocketAddress
import java.net.Socket

enum class CheckState { OK, WARN, FAIL }

data class CheckResult(val title: String, val state: CheckState, val detail: String, val fix: String? = null)

/**
 * «Проверить соединение»: DNS, доступность серверов по SSH, панели, состояние туннеля.
 * Каждая проблема — с понятной причиной и способом исправления.
 */
object Diagnostics {
    private fun tcp(host: String, port: Int, timeoutMs: Int = 4000): Long? = try {
        val t0 = System.currentTimeMillis()
        Socket().use { it.connect(InetSocketAddress(host, port), timeoutMs) }
        System.currentTimeMillis() - t0
    } catch (e: Exception) { null }

    private fun resolve(host: String): String? = try { InetAddress.getByName(host).hostAddress } catch (e: Exception) { null }

    suspend fun run(): List<CheckResult> = withContext(Dispatchers.IO) {
        val app = App.instance
        val data = app.repo.data.value
        val st = app.tunnels.status.value
        val out = mutableListOf<CheckResult>()

        // 1. Туннель
        val active = data.tunnels.firstOrNull { it.id == st.activeId }
        if (st.state == Tunnel.State.UP && active != null) {
            val age = if (st.lastHandshake > 0) (System.currentTimeMillis() - st.lastHandshake) / 1000 else -1
            out += when {
                age < 0 -> CheckResult("VPN-туннель «${active.name}»", CheckState.FAIL, "Туннель включён, но рукопожатия с сервером нет.",
                    "Сервер недоступен из этой сети или закрыт UDP-порт. Проверьте файрвол хостера, попробуйте вариант «через ретранслятор» или другую сеть.")
                age > 180 -> CheckResult("VPN-туннель «${active.name}»", CheckState.WARN, "Последнее рукопожатие ${age / 60} мин назад.",
                    "Связь с сервером нестабильна: возможно, UDP режется оператором. Попробуйте ретранслятор.")
                else -> CheckResult("VPN-туннель «${active.name}»", CheckState.OK, "Рукопожатие $age с назад, принято ${st.rx / 1024} КБ.")
            }
        } else {
            out += CheckResult("VPN-туннель", CheckState.WARN, "Туннель не подключён.", "Нажмите кнопку подключения на главном экране.")
        }

        // 2. DNS
        out += if (resolve("ya.ru") != null || resolve("dns.google") != null) CheckResult("DNS", CheckState.OK, "Имена разрешаются.")
        else CheckResult("DNS", CheckState.FAIL, "Не удаётся разрешить ни одного имени.",
            "Нет интернета или DNS заблокирован. Используйте IP-адреса серверов или задайте свои DNS в настройках.")

        // 3. Свои серверы: SSH и UDP-порт (UDP проверить нельзя без ответа сервера — показываем только SSH)
        coroutineScope {
            data.servers.map { s ->
                async {
                    val ms = tcp(s.host, s.sshPort)
                    val role = when (s.role) { ServerRole.AWG -> "AWG"; ServerRole.RELAY -> "ретранслятор"; ServerRole.PANEL -> "панель"; ServerRole.NONE -> "сервер" }
                    if (ms != null) CheckResult("${s.name} ($role)", CheckState.OK, "SSH ${s.host}:${s.sshPort} отвечает, $ms мс.")
                    else CheckResult("${s.name} ($role)", CheckState.FAIL, "SSH ${s.host}:${s.sshPort} не отвечает.",
                        "Сервер выключен, SSH на другом порту или адрес блокируется в этой сети (белые списки). Для управления нужен доступный канал — например, другая сеть или ретранслятор.")
                }
            }.awaitAll()
        }.let { out += it }

        // 4. Ретрансляторы: доступна ли цель с телефона (для понимания, нужен ли ретранслятор)
        data.servers.filter { it.role == ServerRole.RELAY }.forEach { r ->
            val target = r.relay ?: return@forEach
            val tcpPort = target.ports.firstOrNull { it.proto == "tcp" }
            if (tcpPort != null) {
                val viaRelay = tcp(r.host, tcpPort.port)
                out += if (viaRelay != null) CheckResult("Ретранслятор ${r.name}", CheckState.OK, "Порт ${tcpPort} на ретрансляторе принимает соединения.")
                else CheckResult("Ретранслятор ${r.name}", CheckState.FAIL, "Порт ${tcpPort} на ${r.host} не отвечает.",
                    "Пересылка не работает: откройте порт в файрволе облака ретранслятора или нажмите «Применить заново» в карточке.")
            }
        }

        // 5. Панель
        val p = data.panel
        if (p != null) {
            val client = PanelClient(p)
            val url = client.pickReachable()
            out += if (url != null) CheckResult("Панель VPS Router", CheckState.OK, "Доступна: $url")
            else CheckResult("Панель VPS Router", CheckState.FAIL, "Не отвечает ни по одному адресу.",
                "Туннели и свои серверы работают без панели. Если панель за белыми списками — добавьте адрес через ретранслятор как запасной.")
        }

        // 6. Endpoint'ы туннелей: резолвятся ли домены
        data.tunnels.mapNotNull { t -> AwgFactory.endpointOf(t.config)?.let { t to it } }
            .filter { (_, ep) -> !ep.first.matches(Regex("""[\d.]+|.*:.*""")) }
            .distinctBy { it.second.first }
            .forEach { (t, ep) ->
                if (resolve(ep.first) == null) out += CheckResult("Адрес ${ep.first}", CheckState.FAIL, "Домен из туннеля «${t.name}» не разрешается.",
                    "Проверьте DNS-запись домена или используйте IP сервера в Endpoint.")
            }

        AppLog.i("Диагностика: " + out.groupingBy { it.state }.eachCount().entries.joinToString { "${it.key}=${it.value}" })
        out
    }
}
