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
import androidx.compose.material3.Icon
import androidx.compose.material3.TextButton
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.runtime.LaunchedEffect
import kotlinx.coroutines.delay
import app.vpsrouter.client.panel.DeviceTraffic
import app.vpsrouter.client.panel.PanelException
import app.vpsrouter.client.panel.PanelState
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
import app.vpsrouter.client.data.isSingbox
import app.vpsrouter.client.data.TunnelProfile
import org.amnezia.awg.backend.Tunnel

private data class Hop(val icon: ImageVector, val title: String, val sub: String?)

/** Цепочка «телефон → [ретранслятор] → сервер → интернет» для туннеля. Только реальные данные. */
private fun chainOf(t: TunnelProfile, d: AppData): List<Hop> {
    val endpoint = (if (t.isSingbox) app.vpsrouter.client.tunnel.BoxConfig.endpointOf(t.config) else AwgFactory.endpointOf(t.config))?.let { "${it.first}:${it.second}" }
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
    val panelState by app.panel.state.collectAsState()
    val current = data.tunnels.firstOrNull { it.id == (st.activeId ?: data.lastTunnelId) } ?: data.tunnels.firstOrNull()
    val up = st.state == Tunnel.State.UP

    // «Куда уходит трафик» этого телефона — из учёта трафика панели (по выходам и сайтам).
    val myId = data.panel?.myDeviceId
    var traffic by remember { mutableStateOf<DeviceTraffic?>(null) }
    var trafficErr by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(panelState, myId) {
        if (panelState != PanelState.ONLINE || myId == null) return@LaunchedEffect
        while (true) {
            try { traffic = app.panel.client?.deviceTraffic(myId); trafficErr = null }
            catch (e: Exception) { trafficErr = if ((e as? PanelException)?.code == 404) "Обновите панель до 1.2 — она пока не отдаёт статистику" else e.message }
            delay(30_000)
        }
    }

    LazyColumn(Modifier.fillMaxSize().padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        item {
            Spacer(Modifier.height(4.dp))
            Text("Связи", fontSize = 22.sp, fontWeight = FontWeight.Bold)
            Muted("Через что идёт трафик этого телефона и сколько.")
        }
        if (current == null) item {
            SectionCard {
                Text("Соединений пока нет", fontWeight = FontWeight.Medium)
                Muted("Подключите панель или свой сервер — здесь появится цепочка и статистика.")
            }
        }
        if (current != null) item {
            val live = up && st.activeId == current.id && st.lastHandshake > 0
            SectionCard {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Muted("Сейчас")
                        Text(connTitle(current), fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                    when {
                        live -> StatusPill("трафик идёт", Pal.ok)
                        up && st.activeId == current.id -> StatusPill("нет ответа", Pal.warn)
                        else -> StatusPill("отключено", Pal.muted)
                    }
                }
                if (up && st.activeId == current.id) Row(Modifier.padding(top = 6.dp)) {
                    Text("↓ ${formatBytes(st.rxRate)}/с", color = Pal.ok, fontSize = 13.sp); Spacer(Modifier.width(14.dp))
                    Text("↑ ${formatBytes(st.txRate)}/с", color = Pal.accent2, fontSize = 13.sp); Spacer(Modifier.width(14.dp))
                    Muted("за сеанс ${formatBytes(st.rx + st.tx)}")
                }
                Spacer(Modifier.height(10.dp))
                chainOf(current, data).forEachIndexed { i, h ->
                    if (i > 0) Box(Modifier.padding(start = 19.dp).width(2.dp).height(14.dp).background(if (live) Pal.ok else Pal.surface2))
                    HopRow(h, if (live) Pal.ok else if (i == 0) Pal.accent2 else Pal.muted)
                }
            }
        }

        // --- Куда уходит трафик (по данным панели)
        if (data.panel != null) item {
            SectionCard {
                Text("Куда уходит трафик · сутки", fontWeight = FontWeight.SemiBold)
                val t = traffic
                when {
                    myId == null -> {
                        Muted("Не выбрано, какое устройство панели — этот телефон.")
                        TextButton(onClick = { app.panel.askDevice() }, contentPadding = PaddingValues(0.dp)) { Text("Выбрать устройство") }
                    }
                    panelState != PanelState.ONLINE && t == null -> Muted("Статистика появится, когда панель будет онлайн.")
                    trafficErr != null && t == null -> Muted(trafficErr.orEmpty())
                    t == null -> Muted("Загрузка…")
                    !t.found || (t.up + t.down == 0L && t.byExit.isEmpty()) -> Muted("За сутки трафика через панель не было. Статистика копится, пока включён VPN.")
                    else -> {
                        Row(Modifier.padding(vertical = 4.dp)) {
                            Muted("Сегодня ${formatBytes(t.todayUp + t.todayDown)}"); Spacer(Modifier.width(14.dp))
                            Muted("30 дней ${formatBytes(t.d30Up + t.d30Down)}")
                        }
                        val max = t.byExit.maxOfOrNull { it.up + it.down }?.coerceAtLeast(1) ?: 1
                        t.byExit.forEach { e -> UsageBar(e.name, e.up, e.down, max, if (e.direct) Pal.warn else Pal.ok) }
                        if (t.hosts.isNotEmpty()) {
                            Spacer(Modifier.height(8.dp))
                            Muted("Сайты")
                            t.hosts.take(10).forEach { h ->
                                Row(Modifier.fillMaxWidth().padding(vertical = 3.dp), verticalAlignment = Alignment.CenterVertically) {
                                    Column(Modifier.weight(1f)) {
                                        Text(h.name, fontSize = 14.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                        h.via?.let { Text("через $it", fontSize = 12.sp, color = if (h.direct) Pal.warn else Pal.muted) }
                                    }
                                    Text(formatBytes(h.up + h.down), fontSize = 13.sp, color = Pal.muted)
                                }
                            }
                        }
                    }
                }
            }
        }

        // --- Соединения телефона: сколько прошло через каждое
        if (data.tunnels.isNotEmpty()) item { Text("Соединения телефона", fontWeight = FontWeight.SemiBold, fontSize = 17.sp, modifier = Modifier.padding(top = 4.dp)) }
        val totals = data.tunnels.associate { t ->
            val saved = data.traffic[t.id]
            t.id to Pair((saved?.rx ?: 0L) + (saved?.tx ?: 0L), saved?.lastUsed ?: 0L)
        }
        val maxConn = totals.values.maxOfOrNull { it.first }?.coerceAtLeast(1L) ?: 1L
        connectionGroups(data).forEach { g ->
            item(key = "g:" + g.key) {
                SectionCard {
                    Text(g.title, color = Pal.accent2, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    g.items.forEach { t ->
                        val active = up && st.activeId == t.id
                        val (sum, last) = totals[t.id] ?: (0L to 0L)
                        Column(Modifier.fillMaxWidth().clickable { nav.navigate("tunnel/${t.id}") }.padding(vertical = 6.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Dot(if (active) Pal.ok else Pal.surface2, 9); Spacer(Modifier.width(8.dp))
                                Text(connKind(t), fontWeight = FontWeight.Medium, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                                Text(formatBytes(sum), fontSize = 13.sp, color = if (sum > 0) Pal.text else Pal.muted)
                            }
                            Box(Modifier.padding(start = 17.dp, top = 4.dp).fillMaxWidth().height(4.dp).clip(RoundedCornerShape(2.dp)).background(Pal.surface2)) {
                                Box(Modifier.fillMaxWidth((sum.toFloat() / maxConn).coerceIn(0f, 1f)).height(4.dp).background(if (active) Pal.ok else Pal.accent2))
                            }
                            Text(
                                if (active) "сейчас используется" else if (last > 0) "использовалось ${ago(last)}" else "ещё не использовалось",
                                fontSize = 11.sp, color = Pal.muted, modifier = Modifier.padding(start = 17.dp, top = 2.dp),
                            )
                        }
                    }
                }
            }
        }
        item { Spacer(Modifier.height(16.dp)) }
    }
}

@Composable
private fun UsageBar(name: String, up: Long, down: Long, max: Long, color: Color) = Column(Modifier.padding(vertical = 4.dp)) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Text(name, fontSize = 14.sp, fontWeight = FontWeight.Medium, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
        Text("↓${formatBytes(down)} ↑${formatBytes(up)}", fontSize = 12.sp, color = Pal.muted)
    }
    Box(Modifier.padding(top = 3.dp).fillMaxWidth().height(6.dp).clip(RoundedCornerShape(3.dp)).background(Pal.surface2)) {
        Box(Modifier.fillMaxWidth(((up + down).toFloat() / max).coerceIn(0.02f, 1f)).height(6.dp).background(color))
    }
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
