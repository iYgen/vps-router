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
    val panelState by app.panel.state.collectAsState()
    val toggle = rememberVpnToggle()
    val scope = rememberCoroutineScope()
    var menu by remember { mutableStateOf(false) }
    var picker by remember { mutableStateOf(false) }
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(st.connectedAt) { while (st.connectedAt > 0) { now = System.currentTimeMillis(); delay(1000) } }

    val up = st.state == Tunnel.State.UP && st.activeId != null
    val connecting = st.busy && !up
    val current = data.tunnels.firstOrNull { it.id == (st.activeId ?: data.lastTunnelId) } ?: data.tunnels.firstOrNull()

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
        Spacer(Modifier.height(28.dp))
        val color = when { up -> Pal.ok; connecting -> Pal.accent2; else -> Pal.accent }
        val pulse by rememberInfiniteTransition(label = "p").animateFloat(1f, if (connecting) 1.06f else 1f, infiniteRepeatable(tween(700), RepeatMode.Reverse), label = "s")
        Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
            Box(
                Modifier.size(196.dp).scale(pulse).clip(CircleShape).background(color.copy(alpha = .08f))
                    .border(2.dp, color.copy(alpha = .35f), CircleShape).padding(18.dp)
                    .clip(CircleShape).background(color.copy(alpha = .14f)).border(3.dp, color, CircleShape)
                    .clickable(enabled = !st.busy) { toggle(current) },
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Filled.PowerSettingsNew, if (up) "Отключить" else "Подключить", tint = color, modifier = Modifier.size(64.dp)) }
        }
        Spacer(Modifier.height(18.dp))
        Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
            Text(
                when { up -> "Подключено"; connecting -> "Подключение…"; else -> "Отключено" },
                fontSize = 22.sp, fontWeight = FontWeight.SemiBold, color = if (up) Pal.ok else Pal.text,
            )
            if (up) {
                Text(duration(now - st.connectedAt), fontSize = 15.sp, color = Pal.muted)
                if (st.lastHandshake == 0L) Muted("Ждём ответа сервера…", Modifier.padding(top = 2.dp))
            } else Muted("Нажмите, чтобы подключиться")
            st.error?.let { Text(it, color = Pal.danger, fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp)) }
        }

        if (up) {
            Spacer(Modifier.height(16.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                SpeedTile("Приём", "${formatBytes(st.rxRate)}/с", "всего ${formatBytes(st.rx)}", Pal.ok, Modifier.weight(1f))
                SpeedTile("Передача", "${formatBytes(st.txRate)}/с", "всего ${formatBytes(st.tx)}", Pal.accent2, Modifier.weight(1f))
            }
        }

        Spacer(Modifier.height(16.dp))
        InfoRow(Icons.Filled.Dns, "Соединение", connTitle(current), tunnelSubtitle(current)) { picker = true }
        Spacer(Modifier.height(8.dp))
        InfoRow(Icons.AutoMirrored.Filled.CallSplit, "Маршрутизация", ConfigTransform.describe(data.settings), "Профиль и маршруты") { nav.navigate("routes") }
        Spacer(Modifier.height(8.dp))
        val online = data.servers.count { it.status == "online" }
        val panelTxt = when (panelState) {
            PanelState.ONLINE -> "панель онлайн"; PanelState.CONNECTING -> "панель: подключение"; PanelState.NEED_2FA -> "панель: нужен код 2FA"
            PanelState.OFFLINE -> "панель недоступна — автономный режим"; PanelState.NOT_CONFIGURED -> "без панели"
        }
        InfoRow(Icons.Filled.Link, "Инфраструктура", "Свои серверы: ${data.servers.size}" + if (data.servers.isNotEmpty()) " (онлайн $online)" else "", panelTxt) { nav.navigate("servers") }
        Spacer(Modifier.height(24.dp))
    }

    if (picker) ModalBottomSheet(onDismissRequest = { picker = false }, containerColor = Pal.surface) {
        Text("Тип соединения", fontWeight = FontWeight.SemiBold, fontSize = 18.sp, modifier = Modifier.padding(horizontal = 20.dp))
        LazyColumn(Modifier.padding(horizontal = 12.dp, vertical = 8.dp)) {
          connectionGroups(data).forEach { g ->
            item(key = "h:" + g.key) {
                Text(g.title, color = Pal.accent2, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.padding(start = 12.dp, top = 12.dp, bottom = 2.dp))
            }
            items(g.items, key = { it.id }) { t ->
                val sel = t.id == current?.id
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable {
                        picker = false
                        app.repo.update { it.copy(lastTunnelId = t.id) }
                        if (up && st.activeId != t.id) scope.launch { app.tunnels.start(t) }
                    }.padding(12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Column(Modifier.weight(1f)) {
                        Text(connKind(t), fontWeight = FontWeight.Medium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        if (g.key == "import") Muted(t.name)
                    }
                    if (sel) Icon(Icons.Filled.Check, null, tint = Pal.accent)
                    IconButton(onClick = { picker = false; nav.navigate("tunnel/${t.id}") }) { Icon(Icons.Filled.Info, "Подробнее", tint = Pal.muted) }
                }
            }
          }
            item {
                Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { picker = false; nav.navigate("add") }.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Filled.AddCircleOutline, null, tint = Pal.accent); Spacer(Modifier.width(10.dp)); Text("Добавить сервер", color = Pal.accent)
                }
                Spacer(Modifier.height(24.dp))
            }
        }
    }
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
