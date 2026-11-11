package app.vpsrouter.client.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilterChip
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Tab
import androidx.compose.material3.TabRow
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavController
import app.vpsrouter.client.App
import app.vpsrouter.client.data.PanelDevice
import app.vpsrouter.client.data.PanelProfile
import app.vpsrouter.client.panel.PanelClient
import app.vpsrouter.client.panel.PanelState
import kotlinx.coroutines.launch
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.QrCode
import androidx.compose.material.icons.filled.QrCodeScanner
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.runtime.LaunchedEffect
import app.vpsrouter.client.panel.PanelLink
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions

@Composable
fun PanelScreen(nav: NavController) {
    val app = App.instance
    val state by app.panel.state.collectAsState()
    val error by app.panel.error.collectAsState()
    val data by app.repo.data.collectAsState()
    val scope = rememberCoroutineScope()

    val ctx = LocalContext.current
    val importResult by app.panel.importResult.collectAsState()
    LaunchedEffect(importResult) { importResult?.let { ctx.toast(it); app.panel.clearImportResult() } }

    Column(Modifier.fillMaxSize()) {
    BackBar("VPS Router", nav)
    when (state) {
        PanelState.NOT_CONFIGURED -> PanelLogin(null, error) { p -> scope.launch { app.panel.connect(p) } }
        PanelState.NEED_2FA -> TotpForm(error) { code -> scope.launch { app.panel.submitTotp(code) } }
        else -> Column(Modifier.fillMaxSize()) {
            PanelHeader(state, error, data.panel, onRetry = { scope.launch { app.panel.connect() } }, onForget = { app.panel.forget() })
            PanelTabs(state == PanelState.ONLINE, nav)
        }
    }
    }
}

@Composable
private fun PanelLogin(initial: PanelProfile?, error: String?, onConnect: (PanelProfile) -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var url by remember { mutableStateOf(initial?.url ?: "") }
    var alt by remember { mutableStateOf(initial?.altUrls?.joinToString("\n") ?: "") }
    var user by remember { mutableStateOf(initial?.username ?: "admin") }
    var pass by remember { mutableStateOf("") }
    var insecure by remember { mutableStateOf(initial?.insecureTls ?: false) }
    var busy by remember { mutableStateOf(false) }
    val state by App.instance.panel.state.collectAsState()
    if (state != PanelState.CONNECTING) busy = false

    fun submit() {
        busy = true
        onConnect(PanelProfile(url.trim(), alt.lines().map { it.trim() }.filter { it.isNotEmpty() }, user.trim(), pass, insecure))
    }

    // QR панели: из приложения на другом телефоне (vpsrouter://panel?…) или просто адрес панели.
    fun applyLink(p: PanelLink.Parsed) {
        url = p.url
        p.user?.let { user = it }
        if (p.alt.isNotEmpty()) alt = p.alt.joinToString("\n")
        insecure = p.insecure || (p.url.startsWith("https://") && Regex("""https://[\d.]+""").containsMatchIn(p.url))
        if (p.code != null) { busy = true; scope.launch { App.instance.panel.pairAndConnect(p) } }
        else ctx.toast("Адрес панели получен — введите пароль")
    }
    val prefill by App.instance.panel.prefill.collectAsState()
    LaunchedEffect(prefill) { prefill?.let { applyLink(it); App.instance.panel.prefill.value = null } }
    val scan = rememberLauncherForActivityResult(ScanContract()) { r ->
        val p = PanelLink.parse(r.contents)
        when {
            r.contents == null -> Unit
            p != null -> applyLink(p)
            r.contents.contains("[Interface]") -> ctx.toast("Это QR туннеля, а не панели — импортируйте его на главном экране")
            else -> ctx.toast("QR не похож на адрес панели VPS Router")
        }
    }

    LazyColumn(Modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        item {
            Text("Панель VPS Router", fontSize = 22.sp, fontWeight = FontWeight.Bold)
            Muted("Подключите свою панель: устройства, серверы и ретрансляторы будут доступны с телефона. Если панели нет или она недоступна — вкладка «Серверы» делает то же самое напрямую по SSH.")
        }
        item {
            OutlinedButton(
                onClick = { scan.launch(ScanOptions().setPrompt("QR панели VPS Router").setBeepEnabled(false).setOrientationLocked(false)) },
                modifier = Modifier.fillMaxWidth(),
            ) {
                Icon(Icons.Filled.QrCodeScanner, null); Spacer(Modifier.width(8.dp)); Text("Сканировать QR панели")
            }
            Muted("QR входа — в панели на странице «Приложение» или в этом приложении на другом телефоне (Панель → «QR для другого телефона»). Пароль вводить не нужно, сразу подтянутся соединения этого телефона.", Modifier.padding(top = 4.dp))
        }
        item {
            SectionCard {
                Field(url, { url = it }, "Адрес панели", placeholder = "https://panel.example.com или http://1.2.3.4")
                Field(alt, { alt = it }, "Запасные адреса (по одному в строке)", singleLine = false, minLines = 2,
                    placeholder = "напр. адрес через ретранслятор")
                Field(user, { user = it }, "Логин")
                Field(pass, { pass = it }, "Пароль", password = true)
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Checkbox(insecure, { insecure = it })
                    Muted("Доверять самоподписанному сертификату (панель по IP)")
                }
                error?.let { Text(it, color = Pal.danger, fontSize = 13.sp) }
                Spacer(Modifier.height(8.dp))
                Button(
                    enabled = url.isNotBlank() && user.isNotBlank() && pass.isNotEmpty() && !busy,
                    onClick = ::submit,
                    modifier = Modifier.fillMaxWidth(),
                ) { if (busy) CircularProgressIndicator(Modifier.height(18.dp).width(18.dp), strokeWidth = 2.dp) else Text("Войти") }
            }
        }
    }
}

@Composable
private fun TotpForm(error: String?, onSubmit: (String) -> Unit) {
    var code by remember { mutableStateOf("") }
    Column(Modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Text("Двухфакторная аутентификация", fontSize = 20.sp, fontWeight = FontWeight.Bold)
        Muted("Введите код из приложения-аутентификатора или код восстановления.")
        SectionCard {
            Field(code, { code = it }, "Код", number = true)
            error?.let { Text(it, color = Pal.danger, fontSize = 13.sp) }
            Button(onClick = { onSubmit(code) }, enabled = code.length >= 6, modifier = Modifier.fillMaxWidth()) { Text("Подтвердить") }
        }
        TextButton(onClick = { App.instance.panel.forget() }) { Text("Отменить вход") }
    }
}

@Composable
private fun PanelHeader(state: PanelState, error: String?, p: PanelProfile?, onRetry: () -> Unit, onForget: () -> Unit) {
    var confirmForget by remember { mutableStateOf(false) }
    var shareAsk by remember { mutableStateOf(false) }
    var shareQr by remember { mutableStateOf<String?>(null) }
    val data by App.instance.repo.data.collectAsState()
    Column(Modifier.padding(16.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("Панель", fontSize = 22.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
            when (state) {
                PanelState.ONLINE -> StatusPill("онлайн", Pal.ok)
                PanelState.CONNECTING -> StatusPill("подключение…", Pal.warn)
                else -> StatusPill("недоступна", Pal.danger)
            }
        }
        Muted(App.instance.panel.activeUrl ?: p?.url.orEmpty())
        if (p != null) TextButton(onClick = { shareAsk = true }, contentPadding = PaddingValues(0.dp)) {
            Icon(Icons.Filled.QrCode, null, Modifier.size(18.dp)); Spacer(Modifier.width(6.dp)); Text("QR для другого телефона")
        }
        if (state == PanelState.OFFLINE) {
            Spacer(Modifier.height(8.dp))
            SectionCard {
                Text("Панель недоступна — автономный режим", fontWeight = FontWeight.SemiBold, color = Pal.accent2)
                error?.let { Muted(it) }
                Muted("Показаны данные из кэша (${ago(data.panelCache.fetchedAt)}). Туннели работают без панели. Чтобы добраться до серверов при белых списках, на вкладке «Серверы» можно поднять ретранслятор на «белом» IP и добавить его адрес как запасной адрес панели.")
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(onClick = onRetry) { Text("Повторить") }
                    TextButton(onClick = { confirmForget = true }) { Text("Выйти", color = Pal.danger) }
                }
            }
        } else if (error != null) {
            Text(error, color = Pal.danger, fontSize = 13.sp)
        }
    }
    val scope = rememberCoroutineScope()
    val ctx = LocalContext.current
    LaunchedEffect(shareAsk) {
        if (!shareAsk || p == null) return@LaunchedEffect
        shareAsk = false
        // Одноразовый код (10 мин) от панели: другой телефон войдёт без пароля.
        try { shareQr = App.instance.panel.client?.pairLink(p.altUrls, p.insecureTls) ?: throw Exception("Панель не подключена") }
        catch (e: Exception) { ctx.toast(e.message ?: "Ошибка") }
    }
    shareQr?.let { QrDialog("Вход в панель · 10 минут, один раз", it) { shareQr = null } }
    if (confirmForget) ConfirmDialog("Выйти из панели?", "Логин, пароль и кэш панели будут удалены с телефона. Туннели останутся.", "Выйти", onForget) { confirmForget = false }
}

@Composable
private fun PanelTabs(online: Boolean, nav: NavController) {
    var tab by remember { mutableIntStateOf(0) }
    TabRow(selectedTabIndex = tab, containerColor = Pal.bg) {
        listOf("Устройства", "Серверы", "Релеи").forEachIndexed { i, s ->
            Tab(selected = tab == i, onClick = { tab = i }, text = { Text(s, fontSize = 13.sp, maxLines = 1, softWrap = false) })
        }
    }
    when (tab) {
        0 -> DevicesTab(online)
        1 -> ServersTab(online)
        2 -> RelaysTab(online, nav)
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun DevicesTab(online: Boolean) {
    val app = App.instance
    val data by app.repo.data.collectAsState()
    val scope = rememberCoroutineScope()
    val ctx = LocalContext.current
    var create by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf<String?>(null) }
    var qr by remember { mutableStateOf<Pair<String, String>?>(null) }
    var confirmDelete by remember { mutableStateOf<PanelDevice?>(null) }

    fun act(label: String, block: suspend () -> Unit) = scope.launch {
        busy = label
        try { block() } catch (e: Exception) { ctx.toast(e.message ?: "Ошибка") }
        busy = null
    }

    LazyColumn(Modifier.fillMaxSize().padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        item {
            Spacer(Modifier.height(8.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(enabled = online, onClick = { create = true }) { Text("+ Устройство") }
                OutlinedButton(enabled = online, onClick = { act("Обновление…") { app.panel.refresh() } }) { Text("Обновить") }
                OutlinedButton(enabled = online, onClick = { act("Применение конфигурации…") { ctx.toast(app.panel.client!!.apply()) } }) { Text("Применить") }
            }
            busy?.let { Row(verticalAlignment = Alignment.CenterVertically) { CircularProgressIndicator(Modifier.height(16.dp).width(16.dp), strokeWidth = 2.dp); Spacer(Modifier.width(8.dp)); Muted(it) } }
        }
        if (data.panelCache.devices.isEmpty()) item { Muted("Устройств нет. Создайте устройство с протоколом AmneziaWG — его конфиг сразу импортируется в туннели.") }
        items(data.panelCache.devices, key = { it.id }) { d ->
            SectionCard {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(d.name, fontWeight = FontWeight.Medium, modifier = Modifier.weight(1f))
                    if (d.id == data.panel?.myDeviceId) { StatusPill("этот телефон", Pal.accent); Spacer(Modifier.width(6.dp)) }
                    when {
                        d.revoked -> StatusPill("отозвано", Pal.danger)
                        d.online -> StatusPill("онлайн", Pal.ok)
                        else -> StatusPill("офлайн", Pal.muted)
                    }
                }
                if (d.id != data.panel?.myDeviceId && !d.revoked) TextButton(enabled = online, contentPadding = PaddingValues(0.dp), onClick = {
                    act("Привязка…") { app.panel.chooseDevice(d.id); ctx.toast("«${d.name}» — теперь этот телефон") }
                }) { Text("Это мой телефон", fontSize = 13.sp) }
                Spacer(Modifier.height(6.dp))
                if (d.formats.isEmpty() && !d.revoked) Muted("Нет включённых протоколов")
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    d.formats.forEach { f ->
                        FilterChip(
                            selected = data.tunnels.any { it.origin == "panel:${d.id}:$f" }, enabled = online,
                            leadingIcon = if (data.tunnels.any { it.origin == "panel:${d.id}:$f" }) { { Icon(Icons.Filled.Check, null, Modifier.size(14.dp)) } } else null,
                            onClick = {
                                if (PanelClient.isTunnelFormat(f)) act("Импорт ${PanelClient.formatLabel(f)}…") {
                                    val t = app.panel.importDeviceTunnel(d.id, d.name, f)
                                    ctx.toast("Туннель «${t.name}» добавлен")
                                } else act("Загрузка ссылки…") {
                                    val link = app.panel.client!!.deviceConfig(d.id, f).trim()
                                    qr = PanelClient.formatLabel(f) to link
                                }
                            },
                            label = { Text(PanelClient.formatLabel(f), fontSize = 12.sp) },
                        )
                    }
                }
                val formats = app.panel.tunnelFormats(d)
                val imported = formats.filter { f -> data.tunnels.any { it.origin == "panel:${d.id}:$f" } }
                FlowRow(verticalArrangement = Arrangement.Center) {
                    if (formats.isNotEmpty()) {
                        val all = imported.size == formats.size
                        Button(
                            enabled = online,
                            onClick = {
                                act("Добавление «${d.name}» в туннели…") {
                                    val (ok, failed) = app.panel.importDevice(d)
                                    ctx.toast(if (failed == 0) "«${d.name}»: туннелей добавлено/обновлено $ok" else "Добавлено $ok, ошибок $failed — см. журнал")
                                }
                            },
                            colors = if (all) ButtonDefaults.buttonColors(containerColor = Pal.ok.copy(alpha = .18f), contentColor = Pal.ok) else ButtonDefaults.buttonColors(),
                            contentPadding = PaddingValues(horizontal = 12.dp),
                            modifier = Modifier.height(36.dp),
                        ) {
                            Icon(if (all) Icons.Filled.Check else Icons.Filled.Add, null, Modifier.size(18.dp)); Spacer(Modifier.width(4.dp))
                            Text(if (all) "В туннелях · обновить" else "Добавить в туннели", fontSize = 13.sp)
                        }
                    }
                    TextButton(enabled = online, onClick = { act("…") { app.panel.client!!.setRevoked(d.id, !d.revoked); app.panel.refresh() } }) {
                        Text(if (d.revoked) "Разрешить" else "Отозвать")
                    }
                    TextButton(enabled = online, onClick = { confirmDelete = d }) { Text("Удалить", color = Pal.danger) }
                }
            }
        }
        item { Spacer(Modifier.height(24.dp)) }
    }

    if (create) CreateDeviceDialog(onDismiss = { create = false }) { name, proto, type ->
        create = false
        act("Создание устройства и применение конфигурации…") {
            val c = app.panel.client ?: return@act
            val dev = c.createDevice(name, proto, type)
            app.panel.refresh()
            if (dev == null) { ctx.toast("Устройство не появилось — проверьте журнал панели"); return@act }
            val f = dev.formats.firstOrNull { it == proto }
            if (f != null && PanelClient.isTunnelFormat(f)) {
                app.panel.importDeviceTunnel(dev.id, dev.name, f)
                ctx.toast("Устройство создано, туннель добавлен")
            } else ctx.toast("Устройство «$name» создано")
        }
    }
    qr?.let { (title, link) ->
        AlertDialog(
            onDismissRequest = { qr = null },
            title = { Text(title) },
            text = { Column { Muted("Этот протокол работает в клиентах на ядре Xray/sing-box (v2rayNG, Hiddify, Happ). Откройте ссылку в таком приложении или отсканируйте QR."); Spacer(Modifier.height(8.dp)); Text(link.take(160) + if (link.length > 160) "…" else "", fontSize = 11.sp) } },
            confirmButton = { TextButton(onClick = { ctx.openLink(link); qr = null }) { Text("Открыть в приложении") } },
            dismissButton = { TextButton(onClick = { ctx.copy(link, "Ссылка скопирована"); qr = null }) { Text("Копировать") } },
        )
    }
    confirmDelete?.let { d ->
        ConfirmDialog("Удалить устройство?", "«${d.name}» будет удалено в панели, его конфиги перестанут работать.", onConfirm = {
            act("Удаление…") { app.panel.client!!.deleteDevice(d.id); app.panel.refresh() }
        }) { confirmDelete = null }
    }
}

@Composable
private fun CreateDeviceDialog(onDismiss: () -> Unit, onCreate: (String, String, String) -> Unit) {
    var name by remember { mutableStateOf(android.os.Build.MODEL ?: "Телефон") }
    var proto by remember { mutableStateOf("amneziawg") }
    var type by remember { mutableStateOf("phone") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Новое устройство") },
        text = {
            Column {
                Field(name, { name = it }, "Имя устройства")
                Muted("Протокол")
                listOf("amneziawg" to "AmneziaWG (туннель в этом приложении)", "wireguard" to "WireGuard", "vless" to "VLESS+Reality (ссылка)", "hysteria2" to "Hysteria2 (ссылка)").forEach { (k, l) ->
                    Row(Modifier.fillMaxWidth().clickable { proto = k }.padding(vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                        androidx.compose.material3.RadioButton(proto == k, { proto = k }); Text(l, fontSize = 14.sp)
                    }
                }
                Muted("Тип")
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    listOf("phone" to "Телефон", "tablet" to "Планшет", "computer" to "ПК", "router" to "Роутер").forEach { (k, l) ->
                        FilterChip(type == k, { type = k }, label = { Text(l, fontSize = 12.sp) })
                    }
                }
                Muted("Если протокол выключен в панели, он будет включён автоматически.")
            }
        },
        confirmButton = { TextButton(enabled = name.isNotBlank(), onClick = { onCreate(name.trim(), proto, type) }) { Text("Создать") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Отмена") } },
    )
}

@Composable
private fun ServersTab(online: Boolean) {
    val data by App.instance.repo.data.collectAsState()
    LazyColumn(Modifier.fillMaxSize().padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        item { Spacer(Modifier.height(8.dp)) }
        items(data.panelCache.servers, key = { it.id }) { s ->
            SectionCard {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(s.name + if (s.isSelf) " (панель)" else "", fontWeight = FontWeight.Medium)
                        Muted(listOfNotNull(roleLabel(s.role), s.host, s.country).joinToString(" · "))
                    }
                    val col = when (s.status) { "online" -> Pal.ok; "warning" -> Pal.warn; "offline" -> Pal.danger; else -> Pal.muted }
                    StatusPill(s.status ?: "?", col)
                }
            }
        }
        if (data.panelCache.servers.isEmpty()) item { Muted(if (online) "Серверов нет" else "Нет данных в кэше") }
    }
}

fun roleLabel(r: String) = when (r) {
    "router" -> "роутер"; "exit" -> "exit"; "relay" -> "ретранслятор"; "proxy" -> "прокси"; "vpn" -> "VPN"; else -> r
}

@Composable
private fun RelaysTab(online: Boolean, nav: NavController) {
    val data by App.instance.repo.data.collectAsState()
    val cache = data.panelCache
    LazyColumn(Modifier.fillMaxSize().padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        item {
            Spacer(Modifier.height(8.dp))
            Muted("Ретранслятор — «лёгкий» узел с белым IP, который пересылает порты входов роутера (nftables DNAT). Устройства получают дополнительные конфиги «через ретранслятор».")
            Spacer(Modifier.height(6.dp))
            Button(enabled = online, onClick = { nav.navigate("panel-relay") }) { Text("+ Ретранслятор через панель") }
        }
        items(cache.relays, key = { it.id }) { r ->
            val src = cache.servers.firstOrNull { it.id == r.sourceId }
            val dst = cache.servers.firstOrNull { it.id == r.targetId }
            SectionCard {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text("${src?.name ?: "#${r.sourceId}"} → ${dst?.name ?: "#${r.targetId}"}", fontWeight = FontWeight.Medium)
                        Muted(listOfNotNull(src?.host, r.ports.takeIf { it.isNotEmpty() }?.joinToString()).joinToString(" · "))
                    }
                    val (t, c) = when (r.provisionStatus) {
                        "provisioned" -> "настроен" to Pal.ok; "provisioning" -> "настройка" to Pal.warn
                        "failed" -> "ошибка" to Pal.danger; else -> "не настроен" to Pal.muted
                    }
                    StatusPill(t, c)
                }
            }
        }
    }
}
