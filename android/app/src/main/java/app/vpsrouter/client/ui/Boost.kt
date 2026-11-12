package app.vpsrouter.client.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Bolt
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.ErrorOutline
import androidx.compose.material.icons.filled.RemoveCircleOutline
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import app.vpsrouter.client.App
import app.vpsrouter.client.data.BoostResult
import app.vpsrouter.client.data.TunnelProfile
import app.vpsrouter.client.data.isSingbox
import app.vpsrouter.client.panel.PanelState
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.launch
import org.amnezia.awg.backend.Tunnel

/** UDP-протоколы (AmneziaWG/WireGuard, Hysteria2, TUIC) меньше теряют скорость при потерях пакетов. */
fun isUdpProto(t: TunnelProfile): Boolean {
    if (!t.isSingbox) return true
    val p = protoOf(t).lowercase()
    return "hysteria" in p || "tuic" in p
}

/** Применить ускорение (on/off) или узнать состояние (null) на всех серверах: панели и своих по SSH. */
suspend fun runNetBoost(action: String?): List<BoostResult> = coroutineScope {
    val app = App.instance
    val panel = async {
        val c = app.panel.client
        when {
            app.repo.data.value.panel == null -> emptyList()
            c == null || app.panel.state.value != PanelState.ONLINE -> listOf(BoostResult("Панель VPS Router", false, error = "панель сейчас недоступна"))
            else -> runCatching { c.netBoost(action) }.getOrElse {
                listOf(BoostResult("Панель VPS Router", false, error = if ((it as? app.vpsrouter.client.panel.PanelException)?.code == 404) "панель старой версии — обновите её" else it.message))
            }
        }
    }
    val own = app.repo.data.value.servers.map { s -> async { app.local.netBoost(s.id, action ?: "status") } }
    panel.await() + own.awaitAll()
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun BoostSheet(onDismiss: () -> Unit) {
    val app = App.instance
    val data by app.repo.data.collectAsState()
    val st by app.tunnels.status.collectAsState()
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    var results by remember { mutableStateOf<List<BoostResult>?>(null) }
    val hasServers = data.panel != null || data.servers.isNotEmpty()

    fun apply(on: Boolean) {
        app.repo.update { it.copy(settings = it.settings.copy(netBoost = on)) }
        busy = true
        scope.launch { results = runNetBoost(if (on) "on" else "off"); busy = false }
    }
    LaunchedEffect(Unit) {
        if (hasServers) { busy = true; results = runNetBoost(null); busy = false }
    }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Pal.surface) {
        Column(
            Modifier.verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).navigationBarsPadding(),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.Bolt, null, tint = Pal.accent, modifier = Modifier.size(28.dp))
                Spacer(Modifier.width(8.dp))
                Text("Ускорение", fontSize = 22.sp, fontWeight = FontWeight.Bold)
            }
            Muted("Сделать своё соединение «главнее» в чужой сети нельзя: пометки приоритета провайдеры стирают. Зато можно убрать то, что тормозит на вашей стороне.")

            SectionCard {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text("Ускорение на серверах", fontWeight = FontWeight.SemiBold)
                        Muted("BBR + fq: другой алгоритм скорости TCP вместо стандартного. Заметнее всего на мобильной сети и дальних серверах. Плюс автоподбор размера пакета — меньше «недогруженных» сайтов.")
                    }
                    Switch(data.settings.netBoost, { apply(it) }, enabled = hasServers && !busy, colors = SwitchDefaults.colors(checkedTrackColor = Pal.accent))
                }
                if (!hasServers) Muted("Нужна панель VPS Router или свой сервер с доступом по SSH. На чужих серверах (добавленных ссылкой) настройки менять нельзя.")
                if (busy) Row(verticalAlignment = Alignment.CenterVertically) {
                    CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp); Spacer(Modifier.width(8.dp)); Muted("Связываемся с серверами…")
                }
                results?.forEach { r -> BoostRow(r) }
            }

            // Тип соединения: на мобильной сети с потерями UDP-протоколы обычно быстрее.
            val current = data.tunnels.firstOrNull { it.id == (st.activeId ?: data.lastTunnelId) }
            val group = current?.let { c -> connectionGroups(data).firstOrNull { g -> g.items.any { it.id == c.id } } }
            val udp = group?.items?.firstOrNull { isUdpProto(it) && it.id != current.id }
            SectionCard {
                Text("Тип соединения", fontWeight = FontWeight.SemiBold)
                when {
                    current == null -> Muted("Выберите сервер на главной.")
                    isUdpProto(current) -> Muted("Сейчас «${connKind(current)}» — UDP-протокол, он хорошо держит скорость на мобильной сети.")
                    udp != null -> {
                        Muted("Сейчас «${connKind(current)}» (TCP). На мобильной сети с потерями обычно быстрее «${connKind(udp)}» — UDP почти не теряет скорость при потерях пакетов. Если UDP у оператора режут — вернитесь обратно.")
                        OutlinedButton(onClick = {
                            app.repo.update { it.copy(lastTunnelId = udp.id, groupDefaults = it.groupDefaults + (group.key to udp.id)) }
                            if (st.state == Tunnel.State.UP) scope.launch { app.tunnels.start(udp) }
                        }) { Text("Переключить на «${connKind(udp)}»") }
                    }
                    else -> Muted("Сейчас «${connKind(current)}». У этого сервера нет UDP-типа (AmneziaWG, Hysteria2) — его можно добавить в панели.")
                }
            }
            Muted("Ещё помогает: уменьшить MTU в настройках, если сайты открываются не до конца, и выбрать сервер поближе.")
            Spacer(Modifier.height(12.dp))
        }
    }
}

@Composable
private fun BoostRow(r: BoostResult) = Row(Modifier.fillMaxWidth().padding(vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
    val (icon, tint) = when {
        r.error != null || !r.ok -> Icons.Filled.ErrorOutline to Pal.danger
        r.active -> Icons.Filled.CheckCircle to Pal.ok
        else -> Icons.Filled.RemoveCircleOutline to Pal.muted
    }
    Icon(icon, null, tint = tint, modifier = Modifier.size(20.dp))
    Spacer(Modifier.width(8.dp))
    Column(Modifier.weight(1f)) {
        Text(r.name, fontWeight = FontWeight.Medium)
        Muted(r.error ?: if (r.active) "работает: ${r.cc} + ${r.qdisc}" else "выключено (${r.cc ?: "?"})")
    }
}
