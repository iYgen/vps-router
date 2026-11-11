package app.vpsrouter.client.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavController
import app.vpsrouter.client.data.isSingbox
import app.vpsrouter.client.App
import app.vpsrouter.client.awg.AwgFactory
import app.vpsrouter.client.data.ServerRole
import app.vpsrouter.client.tunnel.BoxConfig
import app.vpsrouter.client.tunnel.OutboundFields
import androidx.compose.ui.text.font.FontWeight
import app.vpsrouter.client.tunnel.TunnelManager
import kotlinx.coroutines.launch

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun BackBar(title: String, nav: NavController, actions: @Composable () -> Unit = {}) = TopAppBar(
    title = { Text(title, maxLines = 1) },
    navigationIcon = { IconButton(onClick = { nav.popBackStack() }) { Icon(Icons.AutoMirrored.Filled.ArrowBack, "Назад") } },
    actions = { actions() },
    colors = TopAppBarDefaults.topAppBarColors(containerColor = Pal.bg),
)

private fun maskKeys(conf: String) = conf.lines().joinToString("\n") {
    if (Regex("""^\s*(PrivateKey|PresharedKey)\s*=""", RegexOption.IGNORE_CASE).containsMatchIn(it)) it.substringBefore('=') + "= ••••••••" else it
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
fun TunnelDetailScreen(id: String, nav: NavController) {
    val app = App.instance
    val data by app.repo.data.collectAsState()
    val t = data.tunnels.firstOrNull { it.id == id }
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    if (t == null) { nav.popBackStack(); return }

    var name by remember(t.id) { mutableStateOf(t.name) }
    var conf by remember(t.id) { mutableStateOf(t.config) }
    var editing by remember { mutableStateOf(false) }
    var err by remember { mutableStateOf<String?>(null) }
    var qr by remember { mutableStateOf(false) }
    var del by remember { mutableStateOf(false) }
    var relayPick by remember { mutableStateOf(false) }

    Column(Modifier.fillMaxSize()) {
        BackBar(t.name, nav)
        Column(Modifier.verticalScroll(rememberScrollState()).padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            SectionCard {
                Field(name, { name = it }, "Название")
                (if (t.isSingbox) BoxConfig.endpointOf(t.config) else AwgFactory.endpointOf(t.config))?.let { (h, p) -> Row2("Сервер", "$h:$p") }
                Row2("Протокол", connKind(t).substringAfter("· ", connKind(t)) + when { !t.isSingbox -> ""; BoxConfig.isXray(t.config) -> " · ядро Xray"; else -> " · ядро sing-box" })
                Row2("Источник", when (t.source) { "panel" -> "панель VPS Router"; "local" -> "свой сервер"; else -> "добавлено вручную" })
                t.via?.let { Row2("Ретранслятор", it) }
                val parsed = remember(t.config) { if (t.isSingbox) null else runCatching { TunnelManager.parseConfig(t.config) }.getOrNull() }
                parsed?.let { c ->
                    Row2("Адрес", c.`interface`.addresses.joinToString())
                    Row2("Обфускация AWG", if (c.`interface`.junkPacketCount.isPresent) "включена" else "нет (WireGuard)")
                }
            }
            // Ручная правка полей (как в v2rayTun). Соединения из панели она перезаписывает при синхронизации.
            if (t.isSingbox && t.source != "panel") {
                val init = remember(t.config) { runCatching { OutboundFields.read(t.config) }.getOrNull() }
                if (init != null) SectionCard {
                    var f by remember(t.config) { mutableStateOf(init) }
                    var ferr by remember(t.config) { mutableStateOf<String?>(null) }
                    Text("Параметры", fontWeight = FontWeight.SemiBold, color = Pal.accent2)
                    Field(f.server, { f = f.copy(server = it) }, "Адрес сервера")
                    Field(f.port, { f = f.copy(port = it.filter(Char::isDigit)) }, "Порт")
                    if (f.hasTls) {
                        Field(f.sni, { f = f.copy(sni = it) }, "SNI (имя сервера TLS)")
                        Field(f.fingerprint, { f = f.copy(fingerprint = it) }, "Отпечаток (chrome, firefox, safari, edge, random…)")
                    }
                    if (f.hasTransport) {
                        Field(f.path, { f = f.copy(path = it) }, "Путь / имя сервиса")
                        Field(f.host, { f = f.copy(host = it) }, "Host")
                        if (f.isXray && BoxConfig.isXray(t.config) && t.config.contains("xhttp")) {
                            Muted("Режим XHTTP")
                            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                listOf("auto", "packet-up", "stream-up", "stream-one").forEach { m ->
                                    androidx.compose.material3.FilterChip(selected = f.mode.ifEmpty { "auto" } == m, onClick = { f = f.copy(mode = m) }, label = { Text(m) })
                                }
                            }
                        }
                    }
                    ferr?.let { Text(it, color = Pal.danger, fontSize = 12.sp) }
                    if (f != init) Button(onClick = {
                        runCatching { OutboundFields.write(t.config, f) }
                            .onSuccess { c ->
                                val n = t.copy(config = c, name = name.ifBlank { t.name })
                                app.repo.upsertTunnel(n); ferr = null
                                scope.launch { app.tunnels.restartIfActive(n) }
                                ctx.toast("Сохранено")
                            }
                            .onFailure { ferr = it.message }
                    }) { Text("Сохранить параметры") }
                }
            }
            SectionCard {
                if (editing) {
                    Field(conf, { conf = it }, "Конфиг", singleLine = false, minLines = 10)
                } else {
                    Text(maskKeys(t.config), fontFamily = FontFamily.Monospace, fontSize = 12.sp)
                }
                err?.let { Text(it, color = Pal.danger, fontSize = 12.sp) }
                Spacer(Modifier.height(6.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (editing || name != t.name) {
                        Button(onClick = {
                            err = runCatching { if (t.isSingbox) kotlinx.serialization.json.Json.parseToJsonElement(conf) else TunnelManager.parseConfig(conf); null }.getOrElse { "Неверный конфиг: ${it.message}" }
                            if (err == null) {
                                val n = t.copy(name = name.ifBlank { t.name }, config = conf)
                                app.repo.upsertTunnel(n)
                                editing = false
                                scope.launch { app.tunnels.restartIfActive(n) }
                            }
                        }) { Text("Сохранить") }
                    }
                    if (!editing) OutlinedButton(onClick = { editing = true }) { Text("Редактировать") }
                }
            }
            SectionCard {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (!t.isSingbox) OutlinedButton(onClick = { qr = true }) { Text("QR-код") }
                    OutlinedButton(onClick = { ctx.shareText(t.config, "${t.name}.conf") }) { Text("Поделиться") }
                    OutlinedButton(onClick = { ctx.copy(t.config, "Конфиг скопирован") }) { Text("Копировать") }
                    if (!t.isSingbox) OutlinedButton(onClick = { relayPick = true }) { Text("Через ретранслятор…") }
                    Button(onClick = { del = true }, colors = ButtonDefaults.buttonColors(containerColor = Pal.danger)) { Text("Удалить") }
                }
                Spacer(Modifier.height(4.dp))
                Muted(if (t.isSingbox && t.source == "panel") "Соединение из панели VPS Router: настройки приходят с сервера." else if (t.isSingbox) "Ниже «Редактировать» — полный JSON outbound для тонкой настройки." else "QR и файл подходят для официальных AmneziaWG / AmneziaVPN на других устройствах.")
            }
            Spacer(Modifier.height(24.dp))
        }
    }

    if (qr) QrDialog(t.name, t.config) { qr = false }
    if (del) ConfirmDialog("Удалить туннель?", "Профиль «${t.name}» будет удалён с телефона. На сервере клиент останется.", onConfirm = {
        scope.launch { if (app.tunnels.status.value.activeId == t.id) app.tunnels.stop() }
        app.repo.deleteTunnel(t.id); nav.popBackStack()
    }) { del = false }
    if (relayPick) {
        val relays = data.servers.filter { it.role == ServerRole.RELAY }
        AlertDialog(
            onDismissRequest = { relayPick = false },
            title = { Text("Копия через ретранслятор") },
            text = {
                Column {
                    if (relays.isEmpty()) Muted("Нет настроенных ретрансляторов. Добавьте сервер с ролью «Ретранслятор» на вкладке «Серверы» — он будет пересылать порт этого туннеля на исходный сервер.")
                    relays.forEach { r ->
                        TextButton(onClick = {
                            val ep = AwgFactory.endpointOf(t.config)
                            val fwd = r.relay?.ports?.any { it.proto == "udp" && it.port == ep?.second } == true
                            app.local.relayCopyOfTunnel(t.id, r.id)?.let { nav.navigate("tunnel/${it.id}") }
                            if (!fwd) ctx.toast("Внимание: ${r.name} не пересылает UDP ${ep?.second} — добавьте порт в настройках ретранслятора")
                            relayPick = false
                        }) { Text("${r.name} (${r.host}) → ${r.relay?.targetLabel}") }
                    }
                }
            },
            confirmButton = { TextButton(onClick = { relayPick = false }) { Text("Закрыть") } },
        )
    }
}
