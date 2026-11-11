package app.vpsrouter.client.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilterChip
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavController
import app.vpsrouter.client.App
import app.vpsrouter.client.awg.AwgFactory
import app.vpsrouter.client.data.ManagedServer
import app.vpsrouter.client.data.PortSpec
import app.vpsrouter.client.data.RelayState
import app.vpsrouter.client.data.ServerRole
import app.vpsrouter.client.data.newId
import kotlinx.coroutines.launch

private enum class Purpose(val title: String, val desc: String) {
    AWG("VPN-сервер AmneziaWG", "Поставить AmneziaWG на этот VPS и сразу получить туннель на телефон. Как приложение AmneziaVPN, но без панели."),
    RELAY("Ретранслятор (для белых списков)", "VPS с «белым» IP пересылает порты на ваш основной сервер (nftables DNAT, как relay-forward.sh в VPS Router). Шифрование сквозное, на ретрансляторе нет VPN-ПО."),
    PANEL("Установить панель VPS Router", "git clone + deploy/bootstrap.sh. После установки откроется ссылка мастера, затем панель можно подключить во вкладке «Панель»."),
    NONE("Только сохранить доступ", "Роль можно выбрать позже в карточке сервера."),
}

/** Мастер «добавить сервер» — аналог masters/add-server-wizard.js панели, но работает напрямую по SSH. */
@Composable
fun AddServerWizard(nav: NavController) {
    val app = App.instance
    val data by app.repo.data.collectAsState()
    val scope = rememberCoroutineScope()
    val ctx = LocalContext.current

    var step by remember { mutableIntStateOf(0) }
    var name by remember { mutableStateOf("vps-${data.servers.size + 1}") }
    var host by remember { mutableStateOf("") }
    var port by remember { mutableStateOf("22") }
    var user by remember { mutableStateOf("root") }
    var useKey by remember { mutableStateOf(false) }
    var pass by remember { mutableStateOf("") }
    var key by remember { mutableStateOf("") }
    var toKey by remember { mutableStateOf(true) }
    var busy by remember { mutableStateOf(false) }
    var err by remember { mutableStateOf<String?>(null) }
    var serverId by remember { mutableStateOf<String?>(null) }
    var info by remember { mutableStateOf("") }

    var purpose by remember { mutableStateOf(Purpose.AWG) }
    // AWG
    var awgPort by remember { mutableStateOf(AwgFactory.randomPort().toString()) }
    var obfs by remember { mutableStateOf(AwgFactory.randomObfs()) }
    var client by remember { mutableStateOf(android.os.Build.MODEL ?: "phone") }
    // Relay
    var targetKind by remember { mutableStateOf("local") }
    var targetLocalId by remember { mutableStateOf<String?>(null) }
    var targetIp by remember { mutableStateOf("") }
    var ports by remember { mutableStateOf("") }
    // Panel
    var domain by remember { mutableStateOf("") }

    val log = remember { mutableStateListOf<String>() }
    var resultTunnel by remember { mutableStateOf<String?>(null) }
    var resultUrl by remember { mutableStateOf<String?>(null) }
    var finished by remember { mutableStateOf(false) }

    val awgServers = data.servers.filter { it.role == ServerRole.AWG && it.id != serverId }
    val panelRouters = data.panelCache.servers.filter { it.isSelf || it.role == "router" }

    Column(Modifier.fillMaxSize()) {
        BackBar("Новый сервер · шаг ${step + 1} из 3", nav)
        Column(Modifier.verticalScroll(rememberScrollState()).padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            when (step) {
                0 -> {
                    SectionCard {
                        Text("Доступ по SSH", fontWeight = FontWeight.SemiBold)
                        Field(name, { name = it }, "Название")
                        Field(host, { host = it.trim() }, "IP или домен")
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Field(port, { port = it.filter(Char::isDigit) }, "Порт", Modifier.weight(1f), number = true)
                            Field(user, { user = it.trim() }, "Пользователь", Modifier.weight(1f))
                        }
                        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            FilterChip(!useKey, { useKey = false }, label = { Text("Пароль") })
                            FilterChip(useKey, { useKey = true }, label = { Text("Приватный ключ") })
                        }
                        if (useKey) Field(key, { key = it }, "Ключ (OpenSSH / PEM)", singleLine = false, minLines = 4)
                        else {
                            Field(pass, { pass = it }, "Пароль", password = true)
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Checkbox(toKey, { toKey = it })
                                Muted("Создать SSH-ключ и дальше входить по нему (пароль root не хранится)")
                            }
                        }
                        if (user != "root") Muted("Не-root: команды выполняются через sudo (нужен пароль для sudo или NOPASSWD).")
                        err?.let { Text(it, color = Pal.danger, fontSize = 13.sp) }
                    }
                    Button(
                        enabled = !busy && host.isNotBlank() && (if (useKey) key.isNotBlank() else pass.isNotEmpty()),
                        modifier = Modifier.fillMaxWidth(),
                        onClick = {
                            busy = true; err = null
                            scope.launch {
                                val s = ManagedServer(
                                    id = serverId ?: newId(),
                                    name = name.ifBlank { host }, host = host, sshPort = port.toIntOrNull() ?: 22, sshUser = user.ifBlank { "root" },
                                    password = if (useKey) null else pass, privateKey = if (useKey) key.trim() + "\n" else null,
                                )
                                try {
                                    app.repo.upsertServer(s)
                                    serverId = s.id
                                    info = app.local.check(s)
                                    if (!useKey && toKey) {
                                        app.local.switchToKey(app.repo.server(s.id)!!)
                                        info += "\nВход переведён на SSH-ключ"
                                    }
                                    step = 1
                                } catch (e: Exception) {
                                    err = e.message
                                    app.repo.deleteServer(s.id); serverId = null
                                }
                                busy = false
                            }
                        },
                    ) { if (busy) CircularProgressIndicator(Modifier.height(18.dp).width(18.dp), strokeWidth = 2.dp) else Text("Проверить подключение") }
                }

                1 -> {
                    SectionCard {
                        Text("Подключение установлено", fontWeight = FontWeight.SemiBold, color = Pal.ok)
                        Text(info, fontFamily = FontFamily.Monospace, fontSize = 12.sp)
                    }
                    Text("Что сделать на сервере?", fontWeight = FontWeight.SemiBold)
                    Purpose.entries.forEach { p ->
                        SectionCard(Modifier.clickable { purpose = p }) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                RadioButton(purpose == p, { purpose = p })
                                Column { Text(p.title, fontWeight = FontWeight.Medium); Muted(p.desc) }
                            }
                        }
                    }
                    Button(modifier = Modifier.fillMaxWidth(), onClick = {
                        if (purpose == Purpose.NONE) nav.popBackStack() else step = 2
                    }) { Text(if (purpose == Purpose.NONE) "Готово" else "Далее") }
                }

                2 -> {
                    when (purpose) {
                        Purpose.AWG -> SectionCard {
                            Text("AmneziaWG", fontWeight = FontWeight.SemiBold)
                            Field(awgPort, { awgPort = it.filter(Char::isDigit) }, "UDP-порт", number = true)
                            Field(client, { client = it }, "Имя первого клиента (этот телефон)")
                            Muted("Обфускация: Jc=${obfs.jc} Jmin=${obfs.jmin} Jmax=${obfs.jmax} S1=${obfs.s1} S2=${obfs.s2} H1..H4 случайные")
                            TextButton(onClick = { obfs = AwgFactory.randomObfs() }) { Text("Сгенерировать заново") }
                        }

                        Purpose.RELAY -> SectionCard {
                            Text("Куда пересылать трафик", fontWeight = FontWeight.SemiBold)
                            @Composable
                            fun opt(k: String, label: String, enabled: Boolean = true, onPick: () -> Unit = {}) =
                                Row(Modifier.fillMaxWidth().clickable(enabled) { targetKind = k; onPick() }, verticalAlignment = Alignment.CenterVertically) {
                                    RadioButton(targetKind == k, { targetKind = k; onPick() }, enabled = enabled)
                                    Text(label, fontSize = 14.sp, color = if (enabled) Pal.text else Pal.muted)
                                }
                            awgServers.forEach { a ->
                                opt("local:${a.id}", "Мой AmneziaWG: ${a.name} (${a.host})") {
                                    targetLocalId = a.id; targetIp = a.host; ports = "${a.awg?.port}/udp"
                                }
                            }
                            panelRouters.forEach { r ->
                                opt("panel:${r.id}", "VPS Router: ${r.name} (${r.host})") {
                                    targetLocalId = null; targetIp = r.host.orEmpty(); if (ports.isBlank()) ports = "443/tcp"
                                }
                            }
                            opt("manual", "Другой сервер — указать IP вручную") { targetLocalId = null }
                            Field(targetIp, { targetIp = it.trim() }, "IPv4 цели")
                            Field(ports, { ports = it }, "Порты", placeholder = "443/tcp, 51820/udp")
                            Muted("Для VPS Router — порты входов роутера (Настройки → «Протоколы подключения устройств»): обычно 443/tcp для VLESS+Reality и UDP-порт AmneziaWG. SSH-порт ретранслятора пересылать нельзя.")
                        }

                        Purpose.PANEL -> SectionCard {
                            Text("Панель VPS Router", fontWeight = FontWeight.SemiBold)
                            Field(domain, { domain = it.trim() }, "Домен панели (необязательно)", placeholder = "пусто = по IP")
                            Muted("Нужен свежий Ubuntu/Debian/RHEL-VPS. Установщик поставит PHP, nginx и код панели; тяжёлые шаги (sing-box, AmneziaWG, сертификат) выполнит браузерный мастер.")
                        }

                        Purpose.NONE -> {}
                    }

                    err?.let { Text(it, color = Pal.danger, fontSize = 13.sp) }
                    if (!finished) Button(
                        enabled = !busy,
                        modifier = Modifier.fillMaxWidth(),
                        onClick = {
                            busy = true; err = null; log.clear()
                            val sid = serverId!!
                            scope.launch {
                                try {
                                    when (purpose) {
                                        Purpose.AWG -> {
                                            val p = awgPort.toIntOrNull()?.takeIf { it in 1..65535 } ?: throw IllegalArgumentException("Неверный порт")
                                            val t = app.local.deployAwg(sid, p, obfs, client.ifBlank { "phone" }) { log += it }
                                            resultTunnel = t?.id
                                        }
                                        Purpose.RELAY -> {
                                            require(Regex("""^\d{1,3}(\.\d{1,3}){3}$""").matches(targetIp)) { "Цель должна быть IPv4-адресом" }
                                            val list = PortSpec.parseList(ports)
                                            require(list.isNotEmpty()) { "Укажите хотя бы один порт" }
                                            val label = when {
                                                targetKind.startsWith("local:") -> data.servers.firstOrNull { it.id == targetLocalId }?.name ?: targetIp
                                                targetKind.startsWith("panel:") -> "VPS Router"
                                                else -> targetIp
                                            }
                                            app.local.deployRelay(sid, RelayState(targetIp, label, targetLocalId, list)) { log += it }
                                        }
                                        Purpose.PANEL -> resultUrl = app.local.installPanel(sid, domain) { log += it }
                                        Purpose.NONE -> {}
                                    }
                                    finished = true
                                } catch (e: Exception) {
                                    err = e.message
                                }
                                busy = false
                            }
                        },
                    ) { if (busy) { CircularProgressIndicator(Modifier.height(18.dp).width(18.dp), strokeWidth = 2.dp); Spacer(Modifier.width(8.dp)); Text("Выполняется на сервере…") } else Text("Установить") }

                    if (log.isNotEmpty()) LogView(log)

                    if (finished) SectionCard {
                        Text("Готово", fontWeight = FontWeight.SemiBold, color = Pal.ok)
                        when (purpose) {
                            Purpose.AWG -> {
                                Muted("Туннель добавлен на вкладку «Туннели». Откройте UDP ${awgPort} во внешнем файрволе хостера, если он есть.")
                                Button(onClick = { nav.navigate("tunnels") { popUpTo("tunnels") { inclusive = true } } }) { Text("К туннелям") }
                            }
                            Purpose.RELAY -> {
                                Muted("Ретранслятор пересылает ${ports} → $targetIp. " + if (targetLocalId != null) "Туннели этого сервера получили копии «через ретранслятор»." else "Чтобы ходить через него, откройте туннель → «Через ретранслятор…», или добавьте адрес ретранслятора как запасной адрес панели.")
                                Button(onClick = { nav.popBackStack() }) { Text("Закрыть") }
                            }
                            Purpose.PANEL -> {
                                resultUrl?.let { u ->
                                    Text(u, fontFamily = FontFamily.Monospace, fontSize = 12.sp)
                                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                        Button(onClick = { ctx.openLink(u) }) { Text("Открыть мастер") }
                                        OutlinedButton(onClick = { ctx.copy(u) }) { Text("Копировать") }
                                    }
                                } ?: Muted("Ссылку мастера найдите в журнале выше.")
                                Muted("После мастера войдите в панель на вкладке «Панель».")
                                TextButton(onClick = {
                                    val base = resultUrl?.substringBefore("/install.php") ?: "http://$host"
                                    ctx.copy(base, "Адрес панели скопирован")
                                    nav.navigate("panel")
                                }) { Text("Перейти к панели") }
                            }
                            Purpose.NONE -> {}
                        }
                    }
                }
            }
            Spacer(Modifier.height(24.dp))
        }
    }
}
