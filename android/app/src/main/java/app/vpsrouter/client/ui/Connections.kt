package app.vpsrouter.client.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.WindowInsetsSides
import androidx.compose.foundation.layout.displayCutout
import androidx.compose.foundation.layout.only
import androidx.compose.foundation.layout.statusBars
import androidx.compose.foundation.layout.union
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AddCircleOutline
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.PhoneAndroid
import androidx.compose.material.icons.filled.Speed
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavController
import app.vpsrouter.client.App
import app.vpsrouter.client.data.AppData
import app.vpsrouter.client.data.TunnelProfile
import app.vpsrouter.client.panel.PanelClient
import app.vpsrouter.client.panel.PanelState
import kotlinx.coroutines.launch
import org.amnezia.awg.backend.Tunnel

/** Группа соединений одного источника: устройство панели, свой сервер или импорт. */
data class ConnGroup(val key: String, val title: String, val items: List<TunnelProfile>)

/** Тип соединения внутри группы: «Напрямую · AmneziaWG» / «Через <ретранслятор>». */
fun connKind(t: TunnelProfile): String {
    if (t.via != null) return "Через ${t.via}"
    val fmt = if (t.source == "panel") t.origin?.split(':')?.getOrNull(2) else null
    val proto = when {
        fmt != null -> PanelClient.formatLabel(fmt)
        Regex("""(?m)^\s*Jc\s*=""").containsMatchIn(t.config) -> "AmneziaWG"
        else -> "WireGuard"
    }
    return "Напрямую · $proto"
}

private fun groupKey(t: TunnelProfile): String = when (t.source) {
    "panel" -> "panel:" + (t.origin?.split(':')?.getOrNull(1) ?: "?")
    "local" -> "local:" + (t.origin ?: t.id)
    else -> "import"
}

fun connectionGroups(d: AppData): List<ConnGroup> {
    val my = d.panel?.myDeviceId
    // Только соединения этого телефона: устройства панели, кроме выбранного, не показываем.
    return d.tunnels.filter { t -> my == null || t.source != "panel" || t.origin?.split(':')?.getOrNull(1)?.toIntOrNull() == my }.groupBy(::groupKey).map { (k, list) ->
        val sorted = list.sortedWith(compareBy({ it.via != null }, { it.via.orEmpty() }))
        val title = when {
            k.startsWith("panel:") -> {
                val id = k.removePrefix("panel:").toIntOrNull()
                val name = d.panelCache.devices.firstOrNull { it.id == id }?.name ?: sorted.first().name.substringBefore(" ⇢ ")
                "VPS Router · $name" + if (id != null && id == my) " (этот телефон)" else ""
            }
            k.startsWith("local:") -> "Свой сервер · " + sorted.first().name.substringBefore(" ⇢ ")
            else -> "Импортированные"
        }
        ConnGroup(k, title, sorted)
    }.sortedBy { g -> when { g.key == "panel:$my" -> 0; g.key.startsWith("panel:") -> 1; g.key.startsWith("local:") -> 2; else -> 3 } }
}

/** Короткое имя соединения для главной: «Pixel 8 — через Relay-1». */
fun connTitle(t: TunnelProfile): String =
    if (t.source == "import" || t.source == "manual") t.name else t.name.substringBefore(" ⇢ ") + " — " + connKind(t).replaceFirstChar { it.lowercase() }

/** Постоянная (не прокручивается) шапка с логотипом над всеми вкладками. */
@Composable
fun BrandBar(nav: NavController) {
    val st by App.instance.tunnels.status.collectAsState()
    val up = st.state == Tunnel.State.UP && st.activeId != null
    var menu by remember { mutableStateOf(false) }
    Row(
        // Отступ под строку состояния и вырез камеры (edge-to-edge) — иначе шапка уезжает под них.
        Modifier.fillMaxWidth().background(Pal.bg).windowInsetsPadding(WindowInsets.statusBars.union(WindowInsets.displayCutout).only(WindowInsetsSides.Top + WindowInsetsSides.Horizontal))
            .padding(start = 16.dp, end = 4.dp, top = 6.dp, bottom = 6.dp).heightIn(min = 44.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Logo()
        Spacer(Modifier.width(10.dp))
        Text("VPS Router", fontSize = 20.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
        StatusPill(if (up) "VPN вкл" else "VPN выкл", if (up) Pal.ok else Pal.muted)
        val appData by App.instance.repo.data.collectAsState()
        val speedOn = appData.settings.speedInStatusBar
        val toggleSpeed = rememberSpeedToggle()
        IconButton(onClick = toggleSpeed) { Icon(Icons.Filled.Speed, if (speedOn) "Скрыть скорость у камеры" else "Показать скорость у камеры", tint = if (speedOn) Pal.accent else Pal.muted) }
        Box {
            IconButton(onClick = { menu = true }) { Icon(Icons.Filled.MoreVert, "Меню") }
            DropdownMenu(menu, { menu = false }) {
                DropdownMenuItem(text = { Text("Панель VPS Router") }, onClick = { menu = false; nav.navigate("panel") })
                DropdownMenuItem(text = { Text("Проверить соединение") }, onClick = { menu = false; nav.navigate("diagnostics") })
                DropdownMenuItem(text = { Text("Журнал") }, onClick = { menu = false; nav.navigate("log") })
            }
        }
    }
}

/**
 * Панель подключена, но не выбрано, какое из её устройств — этот телефон.
 * В туннели подтягиваются соединения только выбранного устройства.
 */
@Composable
fun DeviceChoiceDialog() {
    val app = App.instance
    val need by app.panel.needsDevice.collectAsState()
    val state by app.panel.state.collectAsState()
    val data by app.repo.data.collectAsState()
    if (!need || state != PanelState.ONLINE) return
    val scope = rememberCoroutineScope()
    val ctx = LocalContext.current
    var busy by remember { mutableStateOf(false) }
    val devices = data.panelCache.devices.filter { !it.revoked }
    fun pick(id: Int?) {
        busy = true
        scope.launch {
            try { app.panel.chooseDevice(id) } catch (e: Exception) { ctx.toast(e.message ?: "Ошибка") }
            busy = false
        }
    }
    AlertDialog(
        onDismissRequest = {},
        title = { Text("Какое устройство — этот телефон?") },
        text = {
            Column {
                Muted("В приложение попадут соединения только этого устройства: напрямую и через каждый ретранслятор.")
                Spacer(Modifier.height(8.dp))
                if (busy) Row(verticalAlignment = Alignment.CenterVertically) {
                    CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp); Spacer(Modifier.width(8.dp)); Muted("Подключаем соединения…")
                } else LazyColumn(Modifier.heightIn(max = 340.dp)) {
                    item {
                        Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable { pick(null) }.padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
                            Icon(Icons.Filled.AddCircleOutline, null, tint = Pal.accent); Spacer(Modifier.width(10.dp))
                            Column { Text("Новое устройство", color = Pal.accent, fontWeight = FontWeight.Medium); Muted("«${app.panel.deviceLabel()}», AmneziaWG") }
                        }
                    }
                    items(devices, key = { it.id }) { d ->
                        val n = app.panel.tunnelFormats(d).size
                        Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable { pick(d.id) }.padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
                            Icon(Icons.Filled.PhoneAndroid, null, tint = Pal.accent2); Spacer(Modifier.width(10.dp))
                            Column(Modifier.weight(1f)) {
                                Text(d.name, fontWeight = FontWeight.Medium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                Muted(if (n == 0) "нет поддерживаемых соединений" else "соединений: $n")
                            }
                        }
                    }
                }
            }
        },
        confirmButton = {},
        dismissButton = { TextButton(enabled = !busy, onClick = { app.panel.skipDevice() }) { Text("Позже") } },
    )
}

/**
 * Включить/выключить скорость в строке состояния. На Android 13+ сначала спрашивает
 * разрешение на уведомления — без него значок показать нельзя.
 */
@Composable
fun rememberSpeedToggle(): () -> Unit {
    val ctx = LocalContext.current
    val perm = androidx.activity.compose.rememberLauncherForActivityResult(androidx.activity.result.contract.ActivityResultContracts.RequestPermission()) { ok ->
        if (ok) App.instance.repo.update { it.copy(settings = it.settings.copy(speedInStatusBar = true)) }
        else ctx.toast("Без разрешения на уведомления скорость в строке состояния не показать")
    }
    return {
        val on = App.instance.repo.data.value.settings.speedInStatusBar
        if (!on && !app.vpsrouter.client.tunnel.SpeedNotifier.canPost(ctx)) perm.launch(android.Manifest.permission.POST_NOTIFICATIONS)
        else {
            App.instance.repo.update { it.copy(settings = it.settings.copy(speedInStatusBar = !on)) }
            if (on) app.vpsrouter.client.tunnel.SpeedNotifier.hide(ctx)
            ctx.toast(if (on) "Скорость в строке состояния скрыта" else "Скорость показывается в строке состояния, пока включён VPN")
        }
    }
}
