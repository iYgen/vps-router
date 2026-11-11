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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavController
import app.vpsrouter.client.App
import app.vpsrouter.client.panel.PanelClient
import kotlinx.coroutines.launch

/**
 * Ретранслятор силами панели: создать узел (role=relay) → панель по паролю ставит свой
 * SSH-ключ (bootstrap-ssh) → связь relay → роутер → provision (relay-forward.sh) →
 * у устройств появляются конфиги «через ретранслятор», импортируем AWG-варианты.
 */
@Composable
fun PanelRelayWizard(nav: NavController) {
    val app = App.instance
    val data by app.repo.data.collectAsState()
    val scope = rememberCoroutineScope()
    val routers = data.panelCache.servers.filter { it.isSelf || it.role == "router" }
    var routerId by remember { mutableStateOf(routers.firstOrNull()?.id) }
    var name by remember { mutableStateOf("relay-1") }
    var host by remember { mutableStateOf("") }
    var port by remember { mutableStateOf("22") }
    var user by remember { mutableStateOf("root") }
    var pass by remember { mutableStateOf("") }
    val log = remember { mutableStateListOf<String>() }
    var running by remember { mutableStateOf(false) }
    var done by remember { mutableStateOf(false) }

    Column(Modifier.fillMaxSize()) {
        BackBar("Ретранслятор через панель", nav)
        Column(Modifier.verticalScroll(rememberScrollState()).padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            SectionCard {
                Text("Куда пересылать (роутер)", fontWeight = FontWeight.SemiBold)
                if (routers.isEmpty()) Muted("В панели нет роутеров — обновите список на вкладке «Серверы».")
                routers.forEach { r ->
                    Row(Modifier.fillMaxWidth().clickable { routerId = r.id }, verticalAlignment = Alignment.CenterVertically) {
                        RadioButton(routerId == r.id, { routerId = r.id }); Text("${r.name} ${r.host?.let { "($it)" } ?: ""}", fontSize = 14.sp)
                    }
                }
            }
            SectionCard {
                Text("Узел-ретранслятор (VPS с «белым» IP)", fontWeight = FontWeight.SemiBold)
                Field(name, { name = it }, "Название")
                Field(host, { host = it }, "IP или домен")
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Field(port, { port = it.filter(Char::isDigit) }, "SSH-порт", Modifier.weight(1f), number = true)
                    Field(user, { user = it }, "Пользователь", Modifier.weight(1f))
                }
                Field(pass, { pass = it }, "Пароль SSH", password = true)
                Muted("Пароль передаётся панели один раз: она создаст свой SSH-ключ и дальше ходит по ключу. Пароль нигде не сохраняется.")
            }
            Button(
                enabled = !running && !done && routerId != null && host.isNotBlank() && pass.isNotEmpty(),
                modifier = Modifier.fillMaxWidth(),
                onClick = {
                    running = true; log.clear()
                    scope.launch {
                        try {
                            val c = app.panel.client ?: throw IllegalStateException("Панель не подключена")
                            log += "→ Создаю узел «$name» в панели"
                            val srv = c.createServer(name.trim(), "relay", host.trim(), port.toIntOrNull() ?: 22, user.trim())
                            log += "→ Панель входит по паролю и ставит свой SSH-ключ"
                            c.bootstrapSsh(srv.id, pass)?.let { err ->
                                log += "ERROR: $err"
                                runCatching { c.deleteServer(srv.id) }
                                throw IllegalStateException(err)
                            }
                            log += "→ Создаю связь ретранслятор → роутер"
                            val conn = c.createRelay(srv.id, routerId!!)
                            log += "→ Настраиваю пересылку (relay-forward.sh), это может занять минуту…"
                            val (ok, out) = c.provisionConnection(conn)
                            out.lines().forEach { log += it }
                            if (!ok) throw IllegalStateException("Пересылка не настроена — см. журнал")
                            app.panel.refresh()
                            // Импортируем AWG-конфиги «через ретранслятор» для устройств, чьи туннели уже есть на телефоне.
                            var imported = 0
                            val have = app.repo.data.value.tunnels.mapNotNull { it.origin?.removePrefix("panel:")?.substringBefore(':')?.toIntOrNull() }.toSet()
                            app.repo.data.value.panelCache.devices.filter { it.id in have }.forEach { d ->
                                d.formats.filter { it.endsWith("@$conn") && PanelClient.isTunnelFormat(it) }.forEach { f ->
                                    runCatching { app.panel.importDeviceTunnel(d.id, d.name, f); imported++ }
                                }
                            }
                            log += "Готово: ретранслятор настроен" + if (imported > 0) ", добавлено туннелей: $imported" else ""
                            done = true
                        } catch (e: Exception) {
                            log += "ERROR: ${e.message}"
                        }
                        running = false
                    }
                },
            ) { Text(if (running) "Выполняется…" else if (done) "Готово" else "Создать и настроить") }
            if (log.isNotEmpty()) LogView(log)
            Muted("Не забудьте открыть пересылаемые порты во внешнем файрволе облака ретранслятора.")
            Spacer(Modifier.height(24.dp))
        }
    }
}
