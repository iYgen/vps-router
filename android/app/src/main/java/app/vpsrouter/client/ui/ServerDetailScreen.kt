package app.vpsrouter.client.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
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
import app.vpsrouter.client.data.AwgPeer
import app.vpsrouter.client.data.PortSpec
import app.vpsrouter.client.data.ServerRole
import kotlinx.coroutines.launch

@OptIn(ExperimentalLayoutApi::class)
@Composable
fun ServerDetailScreen(id: String, nav: NavController) {
    val app = App.instance
    val data by app.repo.data.collectAsState()
    val s = data.servers.firstOrNull { it.id == id }
    val scope = rememberCoroutineScope()
    val ctx = LocalContext.current
    if (s == null) { nav.popBackStack(); return }

    var busy by remember { mutableStateOf<String?>(null) }
    val log = remember { mutableStateListOf<String>() }
    var live by remember { mutableStateOf<String?>(null) }
    var addClient by remember { mutableStateOf(false) }
    var qrPeer by remember { mutableStateOf<AwgPeer?>(null) }
    var delPeer by remember { mutableStateOf<AwgPeer?>(null) }
    var delServer by remember { mutableStateOf(false) }
    var removeRole by remember { mutableStateOf(false) }
    var editPorts by remember { mutableStateOf<String?>(null) }

    fun act(label: String, block: suspend () -> Unit) = scope.launch {
        busy = label; log.clear()
        try { block() } catch (e: Exception) { log += "ERROR: ${e.message}"; ctx.toast(e.message ?: "Ошибка") }
        busy = null
    }

    Column(Modifier.fillMaxSize()) {
        BackBar(s.name, nav)
        Column(Modifier.verticalScroll(rememberScrollState()).padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            SectionCard {
                Row2("Адрес", "${s.sshUser}@${s.host}:${s.sshPort}")
                Row2("Вход", if (s.privateKey != null) "по SSH-ключу" else "по паролю")
                Row2("Проверен", ago(s.lastCheckAt))
                s.hostKeyFp?.let { Row2("Ключ хоста", it.takeLast(23)) }
                Spacer(Modifier.height(4.dp))
                Text(roleTitle(s), color = Pal.accent2, fontSize = 13.sp)
                s.osInfo?.let { Text(it, fontFamily = FontFamily.Monospace, fontSize = 11.sp, color = Pal.muted, modifier = Modifier.padding(top = 4.dp)) }
                Spacer(Modifier.height(6.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(onClick = { act("Проверка…") { app.local.check(s); live = app.local.liveStatus(s.id) } }) { Text("Статус") }
                    if (s.privateKey == null) OutlinedButton(onClick = { act("Перевод на ключ…") { app.local.switchToKey(s); ctx.toast("Теперь вход по ключу") } }) { Text("Перейти на ключ") }
                    if (s.hostKeyFp != null) TextButton(onClick = { app.repo.updateServer(s.id) { it.copy(hostKeyFp = null) }; ctx.toast("Ключ хоста сброшен — запомнится при следующем подключении") }) { Text("Сбросить ключ хоста") }
                }
            }

            busy?.let {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    CircularProgressIndicator(Modifier.height(16.dp).width(16.dp), strokeWidth = 2.dp); Spacer(Modifier.width(8.dp)); Muted(it)
                }
            }
            live?.let { SectionCard { Text("Состояние на сервере", fontWeight = FontWeight.SemiBold); Text(it, fontFamily = FontFamily.Monospace, fontSize = 11.sp) } }

            when (s.role) {
                ServerRole.AWG -> s.awg?.let { awg ->
                    SectionCard {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text("Клиенты AmneziaWG", fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                            Button(enabled = busy == null, onClick = { addClient = true }) { Text("+ Клиент") }
                        }
                        Muted("UDP ${awg.port} · сеть ${awg.subnetPrefix}.0/24 · интерфейс ${awg.iface}")
                        awg.peers.forEach { p ->
                            HorizontalDivider(Modifier.padding(vertical = 6.dp), color = Pal.surface2)
                            Text("${p.name} · ${p.ip}", fontWeight = FontWeight.Medium)
                            FlowRow(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                                TextButton(onClick = { app.local.importPeerTunnel(s.id, p.id); ctx.toast("Туннель добавлен/обновлён") }) { Text("В туннели") }
                                TextButton(onClick = { qrPeer = p }) { Text("QR") }
                                TextButton(onClick = { app.local.clientConfig(s.id, p.id)?.let { ctx.shareText(it, "${s.name}-${p.name}.conf") } }) { Text("Поделиться") }
                                TextButton(enabled = busy == null, onClick = { delPeer = p }) { Text("Удалить", color = Pal.danger) }
                            }
                        }
                    }
                    SectionCard {
                        Muted("Обфускация: Jc=${awg.obfs.jc} Jmin=${awg.obfs.jmin} Jmax=${awg.obfs.jmax} S1=${awg.obfs.s1} S2=${awg.obfs.s2}")
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            OutlinedButton(enabled = busy == null, onClick = { act("Переустановка AmneziaWG…") { app.local.deployAwg(s.id, awg.port, awg.obfs, null) { log += it } } }) { Text("Применить заново") }
                            TextButton(onClick = { removeRole = true }) { Text("Удалить AWG", color = Pal.danger) }
                        }
                    }
                }

                ServerRole.RELAY -> s.relay?.let { r ->
                    SectionCard {
                        Text("Ретранслятор", fontWeight = FontWeight.SemiBold)
                        Row2("Цель", "${r.targetLabel} (${r.targetIp})")
                        Row2("Порты", r.ports.joinToString())
                        Spacer(Modifier.height(6.dp))
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            OutlinedButton(enabled = busy == null, onClick = { editPorts = r.ports.joinToString(", ") }) { Text("Изменить порты") }
                            OutlinedButton(enabled = busy == null, onClick = { act("Применение пересылки…") { app.local.deployRelay(s.id, r) { log += it } } }) { Text("Применить заново") }
                            TextButton(onClick = { removeRole = true }) { Text("Снять пересылку", color = Pal.danger) }
                        }
                        Muted("Журнал новых подключений на узле: journalctl -k -g vpsr-relay (кнопка «Статус»).")
                    }
                }

                ServerRole.PANEL -> SectionCard {
                    Text("Панель VPS Router установлена", fontWeight = FontWeight.SemiBold)
                    s.panelInstallUrl?.let { u ->
                        Text(u, fontFamily = FontFamily.Monospace, fontSize = 12.sp)
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(onClick = { ctx.openLink(u) }) { Text("Мастер установки") }
                            OutlinedButton(onClick = { nav.navigate("panel") }) { Text("Подключить") }
                        }
                    }
                }

                ServerRole.NONE -> SectionCard {
                    Muted("У сервера нет роли. Добавьте его заново через мастер, чтобы выбрать роль, или удалите.")
                }
            }

            if (log.isNotEmpty()) LogView(log)
            else s.lastLog?.let { last ->
                SectionCard { Text("Последний журнал", fontWeight = FontWeight.SemiBold); LogView(last.lines()) }
            }

            Button(onClick = { delServer = true }, colors = ButtonDefaults.buttonColors(containerColor = Pal.surface2)) {
                Text("Забыть сервер", color = Pal.danger)
            }
            Spacer(Modifier.height(24.dp))
        }
    }

    if (addClient) {
        var n by remember { mutableStateOf("client-${(s.awg?.peers?.size ?: 0) + 1}") }
        AlertDialog(
            onDismissRequest = { addClient = false },
            title = { Text("Новый клиент") },
            text = { Column { Field(n, { n = it }, "Имя"); Muted("Конфиг сразу появится в туннелях; его можно отдать другому устройству QR-кодом.") } },
            confirmButton = { TextButton(onClick = { addClient = false; act("Добавление клиента…") { app.local.addPeer(s.id, n.ifBlank { "client" }) { log += it } } }) { Text("Создать") } },
            dismissButton = { TextButton(onClick = { addClient = false }) { Text("Отмена") } },
        )
    }
    qrPeer?.let { p -> app.local.clientConfig(s.id, p.id)?.let { QrDialog("${s.name} · ${p.name}", it) { qrPeer = null } } }
    delPeer?.let { p ->
        ConfirmDialog("Удалить клиента?", "«${p.name}» будет удалён с сервера, его конфиг перестанет работать.", onConfirm = {
            act("Удаление клиента…") { app.local.removePeer(s.id, p.id) { log += it } }
        }) { delPeer = null }
    }
    if (removeRole) ConfirmDialog(
        if (s.role == ServerRole.RELAY) "Снять пересылку?" else "Удалить AmneziaWG?",
        if (s.role == ServerRole.RELAY) "Правила nftables ретранслятора будут удалены." else "Интерфейс будет остановлен и удалён, все клиенты перестанут работать.",
        onConfirm = {
            act("Удаление…") {
                if (s.role == ServerRole.RELAY) app.local.removeRelay(s.id) { log += it } else app.local.removeAwg(s.id) { log += it }
            }
        },
    ) { removeRole = false }
    if (delServer) ConfirmDialog("Забыть сервер?", "Доступ (ключ/пароль) и настройки будут удалены с телефона. На самом сервере ничего не изменится.", "Забыть", onConfirm = {
        app.repo.deleteServer(s.id); nav.popBackStack()
    }) { delServer = false }
    editPorts?.let { cur ->
        var v by remember { mutableStateOf(cur) }
        var e by remember { mutableStateOf<String?>(null) }
        AlertDialog(
            onDismissRequest = { editPorts = null },
            title = { Text("Порты пересылки") },
            text = { Column { Field(v, { v = it }, "Порты", placeholder = "443/tcp, 51820/udp"); e?.let { Text(it, color = Pal.danger, fontSize = 12.sp) } } },
            confirmButton = {
                TextButton(onClick = {
                    val list = runCatching { PortSpec.parseList(v) }.getOrElse { e = it.message; return@TextButton }
                    if (list.isEmpty()) { e = "Пустой список — используйте «Снять пересылку»"; return@TextButton }
                    editPorts = null
                    act("Применение пересылки…") { app.local.deployRelay(s.id, s.relay!!.copy(ports = list)) { log += it } }
                }) { Text("Применить") }
            },
            dismissButton = { TextButton(onClick = { editPorts = null }) { Text("Отмена") } },
        )
    }
}
