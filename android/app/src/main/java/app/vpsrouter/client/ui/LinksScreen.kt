package app.vpsrouter.client.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Cloud
import androidx.compose.material.icons.filled.Dns
import androidx.compose.material.icons.filled.Public
import androidx.compose.material.icons.filled.SettingsInputAntenna
import androidx.compose.material.icons.filled.Smartphone
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavController
import app.vpsrouter.client.App
import app.vpsrouter.client.awg.AwgFactory
import app.vpsrouter.client.data.AppData
import app.vpsrouter.client.data.TunnelProfile
import org.amnezia.awg.backend.Tunnel

private data class Hop(val icon: ImageVector, val title: String, val sub: String?)

/** Цепочка «телефон → [ретранслятор] → сервер → интернет» для туннеля. Только реальные данные. */
private fun chainOf(t: TunnelProfile, d: AppData): List<Hop> {
    val endpoint = AwgFactory.endpointOf(t.config)?.let { "${it.first}:${it.second}" }
    val hops = mutableListOf(Hop(Icons.Filled.Smartphone, "Телефон", null))
    when (t.source) {
        "local" -> {
            val srv = d.servers.firstOrNull { it.id == t.origin?.substringBefore('/') }
            val relay = t.via?.let { v -> d.servers.firstOrNull { it.name == v } }
            if (t.via != null) hops += Hop(Icons.Filled.SettingsInputAntenna, "Ретранслятор", relay?.let { "${it.name} · ${it.host}" } ?: t.via)
            hops += Hop(Icons.Filled.Dns, "Сервер", srv?.let { "${it.name} · ${it.host}" } ?: endpoint)
        }
        "panel" -> {
            if (t.via != null) hops += Hop(Icons.Filled.SettingsInputAntenna, "Ретранслятор", "${t.via}${endpoint?.let { " · $it" }.orEmpty()}")
            val self = d.panelCache.servers.firstOrNull { it.isSelf }
            hops += Hop(Icons.Filled.Cloud, "VPS Router", listOfNotNull(self?.name, if (t.via == null) endpoint else self?.host).joinToString(" · ").ifEmpty { null })
        }
        else -> {
            if (t.via != null) hops += Hop(Icons.Filled.SettingsInputAntenna, "Ретранслятор", t.via)
            hops += Hop(Icons.Filled.Dns, "Сервер", endpoint)
        }
    }
    hops += Hop(Icons.Filled.Public, "Интернет", if (t.source == "panel") "по маршрутам панели" else null)
    return hops
}

@Composable
fun LinksScreen(nav: NavController) {
    val app = App.instance
    val data by app.repo.data.collectAsState()
    val st by app.tunnels.status.collectAsState()
    val toggle = rememberVpnToggle()
    var menu by remember { mutableStateOf(false) }
    val (importer, importDialog) = rememberImporter(onPanelLink = { nav.navigate("panel") })
    val current = data.tunnels.firstOrNull { it.id == (st.activeId ?: data.lastTunnelId) } ?: data.tunnels.firstOrNull()
    val up = st.state == Tunnel.State.UP

    Box(Modifier.fillMaxSize()) {
        LazyColumn(Modifier.fillMaxSize().padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item {
                Spacer(Modifier.height(12.dp))
                Text("Связи", fontSize = 22.sp, fontWeight = FontWeight.Bold)
                Muted("Через что идёт трафик и все сохранённые подключения.")
            }
            if (current != null) item {
                SectionCard {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(current.name, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                        if (up && st.activeId == current.id) StatusPill(if (st.lastHandshake > 0) "трафик идёт" else "нет ответа", if (st.lastHandshake > 0) Pal.ok else Pal.warn)
                        else StatusPill("не подключено", Pal.muted)
                    }
                    Spacer(Modifier.height(10.dp))
                    val live = up && st.activeId == current.id && st.lastHandshake > 0
                    chainOf(current, data).forEachIndexed { i, h ->
                        if (i > 0) Box(Modifier.padding(start = 19.dp).width(2.dp).height(14.dp).background(if (live) Pal.ok else Pal.surface2))
                        HopRow(h, if (live) Pal.ok else if (i == 0) Pal.accent2 else Pal.muted)
                    }
                }
            }
            item { Text("Подключения", fontWeight = FontWeight.SemiBold, fontSize = 17.sp) }
            if (data.tunnels.isEmpty()) item {
                SectionCard {
                    Text("Пока пусто", fontWeight = FontWeight.Medium)
                    Muted("Импортируйте конфиг (QR, файл, текст), добавьте устройства из панели или создайте свой сервер на вкладке «Серверы».")
                }
            }
            items(data.tunnels, key = { it.id }) { t ->
                val active = st.activeId == t.id && up
                SectionCard(Modifier.clickable { nav.navigate("tunnel/${t.id}") }) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Dot(if (active) Pal.ok else Pal.surface2, 10)
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(t.name, fontWeight = FontWeight.Medium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Muted(tunnelSubtitle(t))
                        }
                        Switch(active, { toggle(t) }, enabled = !st.busy, colors = SwitchDefaults.colors(checkedTrackColor = Pal.ok))
                    }
                }
            }
            item { Spacer(Modifier.height(88.dp)) }
        }
        Box(Modifier.align(Alignment.BottomEnd).padding(16.dp)) {
            ExtendedFloatingActionButton(onClick = { menu = true }, containerColor = Pal.accent, icon = { Icon(Icons.Filled.Add, null) }, text = { Text("Импорт") })
            ImportMenu(menu, { menu = false }, importer)
        }
    }
    importDialog()
}

@Composable
private fun HopRow(h: Hop, color: Color) = Row(verticalAlignment = Alignment.CenterVertically) {
    Box(Modifier.size(40.dp).clip(CircleShape).background(color.copy(alpha = .14f)).border(1.dp, color.copy(alpha = .5f), CircleShape), contentAlignment = Alignment.Center) {
        Icon(h.icon, null, tint = color, modifier = Modifier.size(20.dp))
    }
    Spacer(Modifier.width(12.dp))
    Column {
        Text(h.title, fontWeight = FontWeight.Medium)
        h.sub?.let { Text(it, fontSize = 12.sp, color = Pal.muted, maxLines = 1, overflow = TextOverflow.Ellipsis) }
    }
}
