package app.vpsrouter.client.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavController
import app.vpsrouter.client.App
import app.vpsrouter.client.data.ManagedServer
import app.vpsrouter.client.data.ServerRole
import app.vpsrouter.client.panel.PanelState
import androidx.compose.material.icons.filled.Hub
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue

fun roleTitle(s: ManagedServer) = when (s.role) {
    ServerRole.AWG -> "AmneziaWG-сервер · UDP ${s.awg?.port} · клиентов: ${s.awg?.peers?.size ?: 0}"
    ServerRole.RELAY -> "Ретранслятор → ${s.relay?.targetLabel} (${s.relay?.ports?.joinToString()})"
    ServerRole.PANEL -> "Панель VPS Router"
    ServerRole.NONE -> "Без роли"
}

@Composable
fun ServersScreen(nav: NavController) {
    val app = App.instance
    val data by app.repo.data.collectAsState()
    val panelState by app.panel.state.collectAsState()
    var fabMenu by remember { mutableStateOf(false) }
    Box(Modifier.fillMaxSize()) {
        LazyColumn(Modifier.fillMaxSize().padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item {
                Spacer(Modifier.height(12.dp))
                Text("Серверы", fontSize = 22.sp, fontWeight = FontWeight.Bold)
                Muted("Панель VPS Router и свои VPS, которыми приложение управляет напрямую по SSH — даже когда панель недоступна.")
            }
            item {
                InfoRow(
                    Icons.Filled.Hub, "Панель VPS Router",
                    data.panel?.url ?: "Не подключена",
                    when (panelState) {
                        PanelState.ONLINE -> "онлайн · устройств ${data.panelCache.devices.size}, серверов ${data.panelCache.servers.size}"
                        PanelState.OFFLINE -> "недоступна — данные из кэша (${ago(data.panelCache.fetchedAt)})"
                        PanelState.CONNECTING -> "подключение…"
                        PanelState.NEED_2FA -> "нужен код 2FA"
                        PanelState.NOT_CONFIGURED -> "вход по адресу или QR"
                    },
                ) { nav.navigate("panel") }
            }
            item { Text("Свои серверы", fontWeight = FontWeight.SemiBold, fontSize = 17.sp) }
            if (data.servers.isEmpty()) item {
                SectionCard {
                    Text("Нет серверов", fontWeight = FontWeight.SemiBold)
                    Muted("Нажмите «Добавить сервер»: укажите IP и пароль root от VPS — мастер проверит доступ и предложит, что на нём поднять.")
                }
            }
            items(data.servers, key = { it.id }) { s ->
                SectionCard(Modifier.clickable { nav.navigate("server/${s.id}") }) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(s.name, fontWeight = FontWeight.Medium)
                            Muted("${s.sshUser}@${s.host}:${s.sshPort}" + (s.osInfo?.let { " · $it" } ?: ""))
                        }
                        when (s.status) {
                            "online" -> StatusPill("онлайн", Pal.ok)
                            "offline" -> StatusPill("недоступен", Pal.danger)
                            else -> StatusPill("не проверен", Pal.muted)
                        }
                    }
                    Spacer(Modifier.height(4.dp))
                    Text(roleTitle(s), fontSize = 13.sp, color = Pal.accent2)
                    s.lastCheckAt?.let { Muted("Проверен ${ago(it)}") }
                }
            }
            if (data.panelCache.servers.isNotEmpty()) {
                item { Text("Серверы в панели", fontWeight = FontWeight.SemiBold, fontSize = 17.sp, modifier = Modifier.padding(top = 6.dp)) }
                items(data.panelCache.servers, key = { "p" + it.id }) { ps ->
                    SectionCard(Modifier.clickable { nav.navigate("panel") }) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(ps.name + if (ps.isSelf) " (панель)" else "", fontWeight = FontWeight.Medium)
                                Muted(listOfNotNull(ps.role, ps.host, ps.country).joinToString(" · "))
                            }
                            ps.status?.let { st -> StatusPill(st, if (st == "online" || st == "ok") Pal.ok else Pal.muted) }
                        }
                    }
                }
            }
            item { Spacer(Modifier.height(88.dp)) }
        }
        Box(Modifier.align(Alignment.BottomEnd).padding(16.dp)) {
            ExtendedFloatingActionButton(
                onClick = { if (panelState == PanelState.ONLINE) fabMenu = true else nav.navigate("wizard") },
                containerColor = Pal.accent,
                icon = { Icon(Icons.Filled.Add, null) },
                text = { Text("Добавить сервер") },
            )
            DropdownMenu(fabMenu, { fabMenu = false }) {
                DropdownMenuItem(text = { Text("Свой VPS по SSH (мастер)") }, onClick = { fabMenu = false; nav.navigate("wizard") })
                DropdownMenuItem(text = { Text("Ретранслятор через панель") }, onClick = { fabMenu = false; nav.navigate("panel-relay") })
            }
        }
    }
}
