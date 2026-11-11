package app.vpsrouter.client.ui

import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.drawable.Drawable
import androidx.compose.foundation.Image
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
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ExpandLess
import androidx.compose.material.icons.filled.ExpandMore
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
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
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.graphics.drawable.toBitmap
import androidx.navigation.NavController
import app.vpsrouter.client.App
import app.vpsrouter.client.data.AppSettings
import app.vpsrouter.client.data.PanelRoute
import app.vpsrouter.client.data.RouteMode
import app.vpsrouter.client.panel.PanelState
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/** Изменить настройки и, если туннель включён, переподключить его с новыми правилами. */
suspend fun applySettings(fn: (AppSettings) -> AppSettings) {
    val app = App.instance
    app.repo.update { it.copy(settings = fn(it.settings)) }
    val active = app.tunnels.status.value.activeId?.let { app.repo.tunnel(it) } ?: return
    app.tunnels.restartIfActive(active)
}

@Composable
fun RoutesScreen(nav: NavController) {
    val app = App.instance
    val data by app.repo.data.collectAsState()
    val panelState by app.panel.state.collectAsState()
    val scope = rememberCoroutineScope()
    val ctx = LocalContext.current
    val s = data.settings
    var busy by remember { mutableStateOf<String?>(null) }
    var query by remember { mutableStateOf("") }
    var addDomainTo by remember { mutableStateOf<PanelRoute?>(null) }
    var expanded by remember { mutableStateOf(setOf<Int>()) }
    val online = panelState == PanelState.ONLINE

    fun act(label: String, block: suspend () -> Unit) = scope.launch {
        busy = label
        try { block() } catch (e: Exception) { ctx.toast(e.message ?: "Ошибка") }
        busy = null
    }

    LazyColumn(Modifier.fillMaxSize().padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        item {
            Spacer(Modifier.height(12.dp))
            Text("Маршруты", fontSize = 22.sp, fontWeight = FontWeight.Bold)
            Muted("Что идёт через VPN на этом телефоне и какие сайты панель VPS Router отправляет через какой сервер.")
        }
        item {
            SectionCard {
                Text("Профиль устройства", fontWeight = FontWeight.SemiBold)
                Muted("Применяется к любому туннелю. Включённый туннель переподключится автоматически.")
                Spacer(Modifier.height(4.dp))
                listOf(
                    RouteMode.ALL to "Весь трафик через VPN",
                    RouteMode.EXCLUDE_APPS to "Всё, кроме выбранных приложений",
                    RouteMode.ONLY_APPS to "Только выбранные приложения",
                ).forEach { (mode, label) ->
                    Row(Modifier.fillMaxWidth().clickable { scope.launch { applySettings { it.copy(routeMode = mode) } } }, verticalAlignment = Alignment.CenterVertically) {
                        RadioButton(s.routeMode == mode, { scope.launch { applySettings { it.copy(routeMode = mode) } } })
                        Text(label)
                    }
                }
                if (s.routeMode != RouteMode.ALL) {
                    OutlinedButton(onClick = { nav.navigate("apps") }, modifier = Modifier.padding(start = 12.dp)) {
                        Text(if (s.apps.isEmpty()) "Выбрать приложения" else "Приложения: ${s.apps.size}")
                    }
                    if (s.apps.isEmpty()) Muted("Пока приложения не выбраны, через VPN идёт весь трафик.", Modifier.padding(start = 12.dp, top = 4.dp))
                }
                Row(Modifier.fillMaxWidth().padding(top = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text("Локальная сеть мимо VPN")
                        Muted("Принтеры, роутер, NAS (192.168.x.x, 10.x.x.x) доступны напрямую")
                    }
                    Switch(s.bypassLan, { v -> scope.launch { applySettings { it.copy(bypassLan = v) } } }, colors = SwitchDefaults.colors(checkedTrackColor = Pal.accent))
                }
            }
        }

        item {
            SectionCard {
                Text("Скрыть VPN от приложений", fontWeight = FontWeight.SemiBold)
                Muted("Приложения вроде МЭШ, банков и Госуслуг проверяют, включён ли VPN. Отмеченные здесь всегда идут мимо туннеля: для них в телефоне нет признака VPN, и они видят ваш обычный (домашний) адрес. Остальные приложения работают через VPN как обычно.")
                Spacer(Modifier.height(6.dp))
                OutlinedButton(onClick = { nav.navigate("apps/hidden") }) {
                    Text(if (s.hiddenApps.isEmpty()) "Выбрать приложения" else "Скрыто от приложений: ${s.hiddenApps.size}")
                }
                Muted("Полностью спрятать VPN без root нельзя: Android всегда помечает VPN-сеть, и приложение, которое само идёт через VPN, это видит. Поэтому такие приложения выводятся из туннеля.", Modifier.padding(top = 6.dp))
            }
        }

        item {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("Маршруты VPS Router", fontWeight = FontWeight.SemiBold, fontSize = 17.sp, modifier = Modifier.weight(1f))
                when (panelState) {
                    PanelState.ONLINE -> StatusPill("панель онлайн", Pal.ok)
                    PanelState.NOT_CONFIGURED -> {}
                    else -> StatusPill("только просмотр", Pal.warn)
                }
            }
            when {
                data.panel == null -> SectionCard {
                    Muted("Маршруты по сайтам (например, YouTube через сервер в Европе, банки напрямую) задаются в панели VPS Router.")
                    TextButton(onClick = { nav.navigate("panel") }) { Text("Подключить панель") }
                }
                !online -> Muted("Панель недоступна — показаны сохранённые маршруты (${ago(data.panelCache.fetchedAt)}). Изменения станут доступны, когда панель ответит.")
            }
            busy?.let { Row(verticalAlignment = Alignment.CenterVertically) { CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp); Spacer(Modifier.width(8.dp)); Muted(it) } }
        }
        if (data.panel != null) {
            if (data.panelCache.routes.isEmpty()) item { Muted(if (online) "В панели нет групп маршрутов." else "Маршруты ещё не загружались.") }
            else {
                item {
                    Field(query, { query = it }, "Поиск: группа или домен")
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        OutlinedButton(enabled = online && busy == null, onClick = { act("Обновление…") { app.panel.refresh() } }) { Text("Обновить") }
                        OutlinedButton(enabled = online && busy == null, onClick = { act("Применение на сервере…") { ctx.toast(app.panel.client!!.apply()) } }) { Text("Применить") }
                    }
                }
                val q = query.trim().lowercase()
                val list = data.panelCache.routes.filter { r -> q.isEmpty() || r.name.lowercase().contains(q) || r.rules.any { it.lowercase().contains(q) } }
                if (list.isEmpty()) item { Muted("Ничего не найдено") }
                items(list, key = { it.id }) { r ->
                    val open = r.id in expanded || (q.isNotEmpty() && r.rules.any { it.lowercase().contains(q) })
                    SectionCard {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f).clickable { expanded = if (r.id in expanded) expanded - r.id else expanded + r.id }) {
                                Text(r.name, fontWeight = FontWeight.Medium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                Muted(listOfNotNull(r.target?.let { "→ $it" } ?: "напрямую", "правил: ${r.rules.size}").joinToString(" · "))
                            }
                            Icon(if (open) Icons.Filled.ExpandLess else Icons.Filled.ExpandMore, null, tint = Pal.muted)
                            Switch(r.enabled, { v ->
                                act(if (v) "Включение «${r.name}»…" else "Выключение «${r.name}»…") { app.panel.client!!.setRouteEnabled(r.id, v); app.panel.refresh() }
                            }, enabled = online && busy == null, colors = SwitchDefaults.colors(checkedTrackColor = Pal.accent))
                        }
                        if (open) {
                            r.rules.take(60).forEach { Text(it.substringAfter(':'), fontSize = 13.sp, color = Pal.text.copy(alpha = .85f)) }
                            if (r.rules.size > 60) Muted("… ещё ${r.rules.size - 60}")
                            TextButton(enabled = online, onClick = { addDomainTo = r }) { Text("+ Добавить домен") }
                        }
                    }
                }
            }
            item {
                Muted("Маршрутизация по доменам работает через DNS: если приложение ходит по IP напрямую или использует свой DoH, правило может не сработать.")
                Spacer(Modifier.height(24.dp))
            }
        }
    }

    addDomainTo?.let { r ->
        var domain by remember { mutableStateOf("") }
        val valid = Regex("""^([a-z0-9-]+\.)+[a-z]{2,}$""").matches(domain.trim().lowercase())
        AlertDialog(
            onDismissRequest = { addDomainTo = null },
            title = { Text("Домен в «${r.name}»") },
            text = { Column { Field(domain, { domain = it }, "Домен", placeholder = "example.com"); Muted("Поддомены тоже попадут в группу. После добавления нажмите «Применить».") } },
            confirmButton = {
                TextButton(enabled = valid, onClick = {
                    addDomainTo = null
                    act("Добавление ${domain.trim()}…") { app.panel.client!!.addDomain(r.id, domain); app.panel.refresh(); ctx.toast("Добавлено. Нажмите «Применить», чтобы сервер начал использовать правило.") }
                }) { Text("Добавить") }
            },
            dismissButton = { TextButton(onClick = { addDomainTo = null }) { Text("Отмена") } },
        )
    }
}

private data class AppInfo(val pkg: String, val label: String, val icon: Drawable?)

/** Выбор приложений для профилей «Всё, кроме…» / «Только…». */
@Composable
fun AppPickerScreen(nav: NavController, hidden: Boolean = false) {
    val app = App.instance
    val ctx = LocalContext.current
    val data by app.repo.data.collectAsState()
    val scope = rememberCoroutineScope()
    var apps by remember { mutableStateOf<List<AppInfo>?>(null) }
    var query by remember { mutableStateOf("") }
    var selected by remember { mutableStateOf((if (hidden) data.settings.hiddenApps else data.settings.apps).toSet()) }

    LaunchedEffect(Unit) {
        apps = withContext(Dispatchers.IO) {
            val pm = ctx.packageManager
            pm.queryIntentActivities(Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_LAUNCHER), PackageManager.MATCH_ALL)
                .map { it.activityInfo.applicationInfo }
                .distinctBy { it.packageName }
                .filter { it.packageName != ctx.packageName }
                .map { AppInfo(it.packageName, it.loadLabel(pm).toString(), runCatching { it.loadIcon(pm) }.getOrNull()) }
                .sortedBy { it.label.lowercase() }
        }
    }

    Column(Modifier.fillMaxSize()) {
        BackBar(if (hidden) "Скрыть VPN от" else if (data.settings.routeMode == RouteMode.ONLY_APPS) "Только через VPN" else "Мимо VPN", nav) {
            TextButton(onClick = {
                scope.launch { applySettings { if (hidden) it.copy(hiddenApps = selected.sorted()) else it.copy(apps = selected.sorted()) } }
                nav.popBackStack()
            }) { Text("Готово (${selected.size})") }
        }
        Field(query, { query = it }, "Поиск приложения", modifier = Modifier.padding(horizontal = 16.dp))
        val list = apps
        if (list == null) Box(Modifier.fillMaxWidth().padding(32.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
        else {
            val q = query.trim().lowercase()
            val suggested = if (hidden) list.filter { RuApps.isLikely(it.pkg, it.label) }.map { it.pkg }.toSet() else emptySet()
            val shown = list.filter { q.isEmpty() || it.label.lowercase().contains(q) || it.pkg.contains(q) }
                .sortedWith(compareByDescending<AppInfo> { it.pkg in selected }.thenByDescending { it.pkg in suggested })
            if (hidden && suggested.any { it !in selected }) TextButton(onClick = { selected = selected + suggested }, modifier = Modifier.padding(horizontal = 8.dp)) {
                Text("Отметить российские приложения (${suggested.count { it !in selected }})")
            }
            LazyColumn(Modifier.fillMaxSize().padding(horizontal = 8.dp)) {
                if (shown.isEmpty()) item { Muted("Ничего не найдено", Modifier.padding(16.dp)) }
                items(shown, key = { it.pkg }) { a ->
                    val on = a.pkg in selected
                    Row(
                        Modifier.fillMaxWidth().clickable { selected = if (on) selected - a.pkg else selected + a.pkg }.padding(8.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        val bmp = remember(a.pkg) { a.icon?.let { runCatching { it.toBitmap(96, 96).asImageBitmap() }.getOrNull() } }
                        if (bmp != null) Image(bmp, null, Modifier.size(36.dp)) else Spacer(Modifier.size(36.dp))
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text(a.label, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Text(if (a.pkg in suggested) "${a.pkg} · обычно проверяет VPN" else a.pkg, fontSize = 11.sp, color = if (a.pkg in suggested) Pal.warn else Pal.muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        }
                        Checkbox(on, { selected = if (on) selected - a.pkg else selected + a.pkg })
                    }
                }
            }
        }
    }
}
