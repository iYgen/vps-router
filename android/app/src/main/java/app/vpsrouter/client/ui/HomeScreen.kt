package app.vpsrouter.client.ui

import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Image
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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.CallSplit
import androidx.compose.material.icons.filled.AddCircleOutline
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material.icons.filled.Dns
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.Link
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.PowerSettingsNew
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavController
import app.vpsrouter.client.App
import app.vpsrouter.client.R
import app.vpsrouter.client.data.TunnelProfile
import app.vpsrouter.client.panel.PanelClient
import app.vpsrouter.client.panel.PanelState
import app.vpsrouter.client.tunnel.ConfigTransform
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import org.amnezia.awg.backend.Tunnel

@Composable
fun Logo(size: Int = 30) = Image(painterResource(R.drawable.ic_logo_mark), null, Modifier.size(size.dp))

fun tunnelSubtitle(t: TunnelProfile): String = listOfNotNull(
    when (t.source) { "panel" -> "VPS Router"; "local" -> "свой сервер"; else -> "импорт" },
    t.via?.let { "через $it" },
).joinToString(" · ")

private fun duration(ms: Long): String {
    val s = ms / 1000
    return "%02d:%02d:%02d".format(s / 3600, (s / 60) % 60, s % 60)
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HomeScreen(nav: NavController) {
    val app = App.instance
    val data by app.repo.data.collectAsState()
    val st by app.tunnels.status.collectAsState()
    val toggle = rememberVpnToggle()
    val scope = rememberCoroutineScope()
    // Сервер, у которого открыт выбор типа соединения (ключ группы).
    var sheet by remember { mutableStateOf<String?>(null) }
    val issues by app.panel.formatIssues.collectAsState()
    // Открыли сервер панели — заново подтянуть соединения телефона (вдруг включили новые протоколы).
    LaunchedEffect(sheet) { if (sheet?.startsWith("panel:") == true && app.panel.state.value == PanelState.ONLINE) app.panel.refresh() }
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(st.connectedAt) { while (st.connectedAt > 0) { now = System.currentTimeMillis(); delay(1000) } }

    val up = st.state == Tunnel.State.UP && st.activeId != null
    val connecting = st.busy && !up
    val current = data.tunnels.firstOrNull { it.id == (st.activeId ?: data.lastTunnelId) } ?: data.tunnels.firstOrNull()
    val groups = connectionGroups(data)

    // Выбранный тип становится основным для сервера и текущим соединением; если VPN включён — переключаемся.
    fun choose(g: ConnGroup, t: TunnelProfile) {
        app.repo.update { it.copy(lastTunnelId = t.id, groupDefaults = it.groupDefaults + (g.key to t.id)) }
        if (up && st.activeId != t.id) scope.launch { app.tunnels.start(t) }
    }

    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 16.dp)) {
        if (current == null) {
            Spacer(Modifier.height(24.dp))
            Text("Нет подключений", fontSize = 22.sp, fontWeight = FontWeight.Bold)
            Muted("Добавьте сервер — после этого здесь появится кнопка подключения.")
            Spacer(Modifier.height(16.dp))
            StartChoices(nav)
            return@Column
        }

        Spacer(Modifier.height(12.dp))
        UpdateBanner()

        // --- Большая кнопка подключения
        Spacer(Modifier.height(24.dp))
        val color = when { up -> Pal.ok; connecting -> Pal.accent2; else -> Pal.accent }
        val pulse by rememberInfiniteTransition(label = "p").animateFloat(1f, if (connecting) 1.06f else 1f, infiniteRepeatable(tween(700), RepeatMode.Reverse), label = "s")
        Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
            Box(
                Modifier.size(180.dp).scale(pulse).clip(CircleShape).background(color.copy(alpha = .08f))
                    .border(2.dp, color.copy(alpha = .35f), CircleShape).padding(16.dp)
                    .clip(CircleShape).background(color.copy(alpha = .14f)).border(3.dp, color, CircleShape)
                    .clickable(enabled = !st.busy) { toggle(current) },
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Filled.PowerSettingsNew, if (up) "Отключить" else "Подключить", tint = color, modifier = Modifier.size(60.dp)) }
        }
        Spacer(Modifier.height(16.dp))
        Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
            Text(
                when { up -> "Подключено"; connecting -> "Подключение…"; else -> "Отключено" },
                fontSize = 22.sp, fontWeight = FontWeight.SemiBold, color = if (up) Pal.ok else Pal.text,
            )
            if (up) {
                Text(duration(now - st.connectedAt), fontSize = 15.sp, color = Pal.muted)
                if (st.lastHandshake == 0L) Muted("Ждём ответа сервера…", Modifier.padding(top = 2.dp))
            }
            val curGroup = groups.firstOrNull { g -> g.items.any { it.id == current.id } }
            Text(
                listOfNotNull(curGroup?.title, connKind(current)).joinToString(" · "),
                fontSize = 13.sp, color = Pal.muted, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 2.dp),
            )
            st.error?.let { Text(it, color = Pal.danger, fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp)) }
        }

        if (up) {
            Spacer(Modifier.height(16.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                SpeedTile("Приём", "${formatBytes(st.rxRate)}/с", "всего ${formatBytes(st.rx)}", Pal.ok, Modifier.weight(1f))
                SpeedTile("Передача", "${formatBytes(st.txRate)}/с", "всего ${formatBytes(st.tx)}", Pal.accent2, Modifier.weight(1f))
            }
        }

        // --- Список серверов: нажали на сервер — выбираете тип соединения.
        Spacer(Modifier.height(20.dp))
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("Серверы", fontSize = 17.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
            IconButton(onClick = { nav.navigate("add") }) { Icon(Icons.Filled.AddCircleOutline, "Добавить сервер", tint = Pal.accent) }
        }
        groups.forEach { g ->
            val def = groupDefault(g, data)
            val isCur = g.items.any { it.id == current.id }
            val many = g.items.size > 1 || g.key.startsWith("panel:")
            ServerCard(
                title = g.title,
                sub = connKind(def) + if (g.items.size > 1) " · типов: ${g.items.size}" else "",
                selected = isCur, active = up && isCur,
                onClick = { if (many) sheet = g.key else choose(g, def) },
                trailing = {
                    if (many) Icon(Icons.Filled.ChevronRight, "Типы соединения", tint = Pal.muted, modifier = Modifier.padding(end = 8.dp))
                    else IconButton(onClick = { nav.navigate("tunnel/${def.id}") }) { Icon(Icons.Filled.Info, "Подробнее", tint = Pal.muted) }
                },
            )
            Spacer(Modifier.height(8.dp))
        }
        Spacer(Modifier.height(16.dp))
    }

    val g = groups.firstOrNull { it.key == sheet }
    if (g != null) ModalBottomSheet(onDismissRequest = { sheet = null }, containerColor = Pal.surface) {
        Text(g.title, fontWeight = FontWeight.SemiBold, fontSize = 18.sp, modifier = Modifier.padding(horizontal = 20.dp), maxLines = 1, overflow = TextOverflow.Ellipsis)
        Muted("Выберите тип соединения — он станет основным для этого сервера", Modifier.padding(horizontal = 20.dp))
        LazyColumn(Modifier.padding(horizontal = 12.dp, vertical = 8.dp)) {
            val selId = groupDefault(g, data).id
            items(g.items, key = { it.id }) { t ->
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { sheet = null; choose(g, t) }.padding(start = 4.dp, top = 2.dp, bottom = 2.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    RadioButton(selected = t.id == selId, onClick = { sheet = null; choose(g, t) })
                    Column(Modifier.weight(1f)) {
                        val fmt = if (t.source == "panel") t.origin?.split(':')?.getOrNull(2)?.substringBefore('@') else null
                        Text(protoOf(t, fmt), fontWeight = FontWeight.Medium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        if (t.source == "panel" || t.source == "local") Muted(if (t.via != null) "через ретранслятор ${t.via}" else "без ретранслятора")
                    }
                    IconButton(onClick = { sheet = null; nav.navigate("tunnel/${t.id}") }) { Icon(Icons.Filled.Info, "Подробнее", tint = Pal.muted) }
                }
            }
            // Протоколы этого устройства из панели, которые подключить не удалось, — с причиной.
            if (g.key == "panel:${data.panel?.myDeviceId}") {
                val have = g.items.mapNotNull { it.origin?.split(':')?.getOrNull(2) }.toSet()
                issues.filterKeys { it !in have }.forEach { (f, why) ->
                    item(key = "x:$f") {
                        Column(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp)) {
                            val via = f.substringAfter('@', "").ifEmpty { null }?.let { app.panel.relayName(it) }
                            Text(PanelClient.formatLabel(f.substringBefore('@')) + if (via != null) " · через $via" else "",
                                color = Pal.muted, fontWeight = FontWeight.Medium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Text(why, color = Pal.warn, fontSize = 12.sp)
                        }
                    }
                }
            }
            item {
                val more = when {
                    g.key.startsWith("panel:") -> "Панель VPS Router" to "panel"
                    g.key.startsWith("local:") -> "Мои серверы" to "servers"
                    else -> null
                }
                more?.let { (label, route) ->
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { sheet = null; nav.navigate(route) }.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Filled.Dns, null, tint = Pal.accent2); Spacer(Modifier.width(10.dp)); Text(label, modifier = Modifier.weight(1f)); Icon(Icons.Filled.ChevronRight, null, tint = Pal.muted)
                    }
                }
                Spacer(Modifier.height(24.dp))
            }
        }
    }
}

@Composable
private fun ServerCard(title: String, sub: String, selected: Boolean, active: Boolean, onClick: () -> Unit, trailing: @Composable () -> Unit) = Row(
    Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Pal.surface)
        .then(if (selected) Modifier.border(1.5.dp, if (active) Pal.ok else Pal.accent, RoundedCornerShape(14.dp)) else Modifier)
        .clickable(onClick = onClick).padding(start = 14.dp, top = 12.dp, bottom = 12.dp, end = 4.dp),
    verticalAlignment = Alignment.CenterVertically,
) {
    Box(Modifier.size(38.dp).clip(RoundedCornerShape(10.dp)).background(Pal.surface2), contentAlignment = Alignment.Center) {
        Icon(Icons.Filled.Dns, null, tint = if (active) Pal.ok else Pal.accent2, modifier = Modifier.size(20.dp))
    }
    Spacer(Modifier.width(12.dp))
    Column(Modifier.weight(1f)) {
        Text(title, fontWeight = FontWeight.Medium, maxLines = 1, overflow = TextOverflow.Ellipsis)
        Text(sub, fontSize = 12.sp, color = Pal.muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
    if (selected) Icon(Icons.Filled.Check, "Выбран", tint = if (active) Pal.ok else Pal.accent, modifier = Modifier.padding(horizontal = 4.dp))
    trailing()
}


@Composable
private fun SpeedTile(label: String, value: String, sub: String, color: Color, modifier: Modifier) = Column(
    modifier.clip(RoundedCornerShape(14.dp)).background(Pal.surface).padding(12.dp)
) {
    Row(verticalAlignment = Alignment.CenterVertically) { Dot(color); Spacer(Modifier.width(6.dp)); Muted(label) }
    Text(value, fontSize = 18.sp, fontWeight = FontWeight.SemiBold)
    Muted(sub)
}

@Composable
fun InfoRow(icon: ImageVector, label: String, value: String, sub: String?, onClick: () -> Unit) = Row(
    Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Pal.surface).clickable(onClick = onClick).padding(14.dp),
    verticalAlignment = Alignment.CenterVertically,
) {
    Box(Modifier.size(38.dp).clip(RoundedCornerShape(10.dp)).background(Pal.surface2), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = Pal.accent2, modifier = Modifier.size(20.dp))
    }
    Spacer(Modifier.width(12.dp))
    Column(Modifier.weight(1f)) {
        Muted(label)
        Text(value, fontWeight = FontWeight.Medium, maxLines = 1, overflow = TextOverflow.Ellipsis)
        sub?.let { Text(it, fontSize = 12.sp, color = Pal.muted, maxLines = 1, overflow = TextOverflow.Ellipsis) }
    }
    Icon(Icons.Filled.ChevronRight, null, tint = Pal.muted)
}
