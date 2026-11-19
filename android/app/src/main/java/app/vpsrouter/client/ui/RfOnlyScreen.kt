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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ExpandLess
import androidx.compose.material.icons.filled.ExpandMore
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavController
import app.vpsrouter.client.App
import app.vpsrouter.client.data.isSingbox
import app.vpsrouter.client.tunnel.AppTrafficLog
import app.vpsrouter.client.tunnel.RfOnly
import kotlinx.coroutines.launch

/** «Только РФ для приложений»: режим, список приложений и журнал их соединений. */
@Composable
fun RfOnlyScreen(nav: NavController) {
    val app = App.instance
    val ctx = LocalContext.current
    val data by app.repo.data.collectAsState()
    val log by AppTrafficLog.entries.collectAsState()
    val scope = rememberCoroutineScope()
    val s = data.settings
    var filter by remember { mutableStateOf("problems") }
    var open by remember { mutableStateOf(setOf<String>()) }
    LaunchedEffect(Unit) { AppTrafficLog.load(ctx) }

    val pm = ctx.packageManager
    val labels = remember(s.rfOnlyApps, log.size) {
        (s.rfOnlyApps + log.map { it.pkg }).distinct().associateWith { p ->
            runCatching { pm.getApplicationInfo(p, 0).loadLabel(pm).toString() }.getOrDefault(p)
        }
    }
    val active = data.tunnels.firstOrNull { it.id == app.tunnels.status.value.activeId }

    Column(Modifier.fillMaxSize()) {
        BackBar("Только РФ для приложений", nav)
        LazyColumn(Modifier.fillMaxSize().padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item {
                Muted("Отмеченные приложения никогда не ходят через VPN-сервер. Российские адреса — напрямую с телефона (с вашим российским IP), зарубежные — по режиму ниже. Журнал собирается сам, пока включён VPN.")
            }
            item {
                SectionCard {
                    Text("Приложения", fontWeight = FontWeight.SemiBold)
                    if (s.rfOnlyApps.isEmpty()) Muted("Пока не выбрано ни одного.")
                    else Muted(s.rfOnlyApps.joinToString(", ") { labels[it] ?: it })
                    val hidden = s.rfOnlyApps.filter { it in s.hiddenApps }
                    if (hidden.isNotEmpty()) Text(
                        "Скрыты от VPN (идут мимо туннеля, правило к ним не применяется): " + hidden.joinToString(", ") { labels[it] ?: it },
                        color = Pal.warn, fontSize = 12.sp,
                    )
                    Spacer(Modifier.height(6.dp))
                    OutlinedButton(onClick = { nav.navigate("apps/rfonly") }) { Text(if (s.rfOnlyApps.isEmpty()) "Выбрать приложения" else "Изменить (${s.rfOnlyApps.size})") }
                }
            }
            item {
                SectionCard {
                    Text("Зарубежные адреса", fontWeight = FontWeight.SemiBold)
                    listOf(
                        Triple(false, "Наблюдение", "Пропускать напрямую (не через VPN) и записывать в журнал. Начните с этого: посмотрите, что нужно приложениям."),
                        Triple(true, "Запрет", "Блокировать. Нужные адреса можно разрешить в журнале."),
                    ).forEach { (block, label, hint) ->
                        Row(Modifier.fillMaxWidth().clickable { scope.launch { applySettings { it.copy(rfOnlyBlock = block) } } }, verticalAlignment = Alignment.CenterVertically) {
                            RadioButton(s.rfOnlyBlock == block, { scope.launch { applySettings { it.copy(rfOnlyBlock = block) } } })
                            Column { Text(label); Muted(hint) }
                        }
                    }
                    if (s.rfOnlyAllow.isNotEmpty()) {
                        Spacer(Modifier.height(6.dp))
                        Text("Разрешено: ${s.rfOnlyAllow.size}", fontWeight = FontWeight.Medium)
                        s.rfOnlyAllow.forEach { a ->
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text(a, fontSize = 13.sp, modifier = Modifier.weight(1f))
                                TextButton(onClick = { scope.launch { applySettings { it.copy(rfOnlyAllow = it.rfOnlyAllow - a) } } }) { Text("Убрать") }
                            }
                        }
                    }
                    if (active != null && !active.isSingbox) Text(
                        "Сейчас подключён AmneziaWG — правила «Только РФ» работают только с соединениями sing-box (VLESS, XHTTP, Hysteria2 и др.).",
                        color = Pal.warn, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp),
                    )
                }
            }
            item {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("Журнал", fontWeight = FontWeight.SemiBold, fontSize = 17.sp, modifier = Modifier.weight(1f))
                    TextButton(onClick = { AppTrafficLog.clear() }, enabled = log.isNotEmpty()) { Text("Очистить") }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    listOf("problems" to "Зарубежные", "all" to "Все").forEach { (k, l) ->
                        OutlinedButton(onClick = { filter = k }, enabled = filter != k) { Text(l) }
                    }
                }
            }
            val shown = log.filter { filter == "all" || it.verdict == RfOnly.Verdict.FOREIGN.name || it.verdict == RfOnly.Verdict.BLOCKED.name }
            if (shown.isEmpty()) item {
                Muted(if (log.isEmpty()) "Записей пока нет. Включите VPN (sing-box) и откройте отмеченные приложения." else "Зарубежных обращений нет.")
            }
            val byApp = shown.groupBy { it.pkg }.toList().sortedByDescending { (_, l) -> l.maxOf { it.last } }
            items(byApp, key = { it.first }) { (pkg, list) ->
                val isOpen = pkg in open || byApp.size == 1
                SectionCard {
                    Row(Modifier.fillMaxWidth().clickable { open = if (pkg in open) open - pkg else open + pkg }, verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(labels[pkg] ?: pkg, fontWeight = FontWeight.Medium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            val f = list.count { it.verdict == RfOnly.Verdict.FOREIGN.name }
                            val b = list.count { it.verdict == RfOnly.Verdict.BLOCKED.name }
                            Muted(listOfNotNull("адресов: ${list.size}", f.takeIf { it > 0 }?.let { "зарубежных пропущено: $it" }, b.takeIf { it > 0 }?.let { "заблокировано: $it" }).joinToString(" · "))
                        }
                        Icon(if (isOpen) Icons.Filled.ExpandLess else Icons.Filled.ExpandMore, null, tint = Pal.muted)
                    }
                    if (isOpen) list.sortedByDescending { it.last }.take(200).forEach { e ->
                        val v = runCatching { RfOnly.Verdict.valueOf(e.verdict) }.getOrNull()
                        val color = when (v) { RfOnly.Verdict.BLOCKED -> Pal.danger; RfOnly.Verdict.FOREIGN -> Pal.warn; else -> Pal.ok }
                        val key = RfOnly.allowKey(e.host)
                        val allowed = key in s.rfOnlyAllow
                        Row(Modifier.fillMaxWidth().padding(top = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(e.host + if (e.port > 0 && e.port != 443) ":${e.port}" else "", fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                Text("${v?.label ?: e.verdict} · ${e.count}× · ${ago(e.last)}" + if (e.ip.isNotBlank() && e.ip != e.host) " · ${e.ip}" else "", fontSize = 11.sp, color = color)
                            }
                            Spacer(Modifier.width(6.dp))
                            if (v == RfOnly.Verdict.FOREIGN || v == RfOnly.Verdict.BLOCKED) {
                                if (allowed) Muted("разрешено")
                                else TextButton(onClick = { scope.launch { applySettings { it.copy(rfOnlyAllow = (it.rfOnlyAllow + key).distinct()) } } }) { Text("Разрешить") }
                            }
                        }
                    }
                }
            }
            item { Spacer(Modifier.height(24.dp)) }
        }
    }
}
