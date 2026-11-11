package app.vpsrouter.client.ui

import android.content.Intent
import android.provider.Settings
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
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
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Error
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedButton
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavController
import app.vpsrouter.client.tunnel.BoxVpnService
import app.vpsrouter.client.App
import app.vpsrouter.client.BuildConfig
import app.vpsrouter.client.data.AppLog
import app.vpsrouter.client.diag.CheckResult
import app.vpsrouter.client.diag.CheckState
import app.vpsrouter.client.diag.Diagnostics
import app.vpsrouter.client.panel.PanelState
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

@Composable
private fun SettingRow(title: String, sub: String?, onClick: (() -> Unit)? = null, trailing: @Composable () -> Unit = {}) = Row(
    Modifier.fillMaxWidth().let { if (onClick != null) it.clickable(onClick = onClick) else it }.padding(vertical = 10.dp),
    verticalAlignment = Alignment.CenterVertically,
) {
    Column(Modifier.weight(1f)) {
        Text(title)
        sub?.let { Muted(it) }
    }
    trailing()
}

@Composable
fun SettingsScreen(nav: NavController) {
    val app = App.instance
    val ctx = LocalContext.current
    val data by app.repo.data.collectAsState()
    val panelState by app.panel.state.collectAsState()
    val scope = rememberCoroutineScope()
    val s = data.settings
    var dnsDialog by remember { mutableStateOf(false) }
    var killInfo by remember { mutableStateOf(false) }
    // Резервная копия: сначала пароль, затем выбор файла.
    var backupMode by remember { mutableStateOf<String?>(null) } // "export" | "import"
    var backupPass by remember { mutableStateOf("") }
    var pendingImport by remember { mutableStateOf<String?>(null) }

    val exportLauncher = rememberLauncherForActivityResult(ActivityResultContracts.CreateDocument("application/octet-stream")) { uri ->
        uri ?: return@rememberLauncherForActivityResult
        val pass = backupPass; backupPass = ""
        scope.launch {
            val r = runCatching {
                val text = withContext(Dispatchers.Default) { app.repo.exportBackup(pass) }
                withContext(Dispatchers.IO) { ctx.contentResolver.openOutputStream(uri)!!.use { it.write(text.toByteArray()) } }
            }
            ctx.toast(r.fold({ "Резервная копия сохранена" }, { "Не удалось сохранить: ${it.message}" }))
        }
    }
    val importLauncher = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
        uri ?: return@rememberLauncherForActivityResult
        pendingImport = runCatching { ctx.contentResolver.openInputStream(uri)?.bufferedReader()?.use { it.readText() } }.getOrNull()
        if (pendingImport == null) ctx.toast("Не удалось прочитать файл") else backupMode = "import"
    }

    LazyColumn(Modifier.fillMaxSize().padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        item {
            Spacer(Modifier.height(12.dp))
            Text("Настройки", fontSize = 22.sp, fontWeight = FontWeight.Bold)
        }
        item {
            SectionCard {
                Text("Подключение", fontWeight = FontWeight.SemiBold, color = Pal.accent2)
                SettingRow("Автоподключение", "При запуске приложения подключать последний туннель") {
                    Switch(s.autoConnect, { v -> app.repo.update { it.copy(settings = it.settings.copy(autoConnect = v)) } }, colors = SwitchDefaults.colors(checkedTrackColor = Pal.accent))
                }
                val toggleSpeed = rememberSpeedToggle()
                SettingRow("Скорость у камеры", "Входящая и исходящая скорость VPN в строке состояния") {
                    Switch(s.speedInStatusBar, { toggleSpeed() }, colors = SwitchDefaults.colors(checkedTrackColor = Pal.accent))
                }
                SettingRow("Kill Switch", "Блокировать интернет, если VPN отключился", onClick = { killInfo = true })
                SettingRow("DNS", s.dnsOverride.ifBlank { "Из конфигурации туннеля" }, onClick = { dnsDialog = true })
                SettingRow("Маршрутизация", "Профиль устройства и маршруты панели", onClick = { nav.navigate("routes") })
            }
        }
        item {
            SectionCard {
                Text("Инфраструктура", fontWeight = FontWeight.SemiBold, color = Pal.accent2)
                SettingRow(
                    "Панель VPS Router",
                    when (panelState) {
                        PanelState.ONLINE -> "Онлайн · ${app.panel.activeUrl}"
                        PanelState.NOT_CONFIGURED -> "Не подключена"
                        PanelState.NEED_2FA -> "Нужен код 2FA"
                        PanelState.CONNECTING -> "Подключение…"
                        PanelState.OFFLINE -> "Недоступна — автономный режим"
                    },
                    onClick = { nav.navigate("panel") },
                )
                SettingRow("Проверить соединение", "DNS, серверы, ретрансляторы, панель", onClick = { nav.navigate("diagnostics") })
                SettingRow("Журнал", "События подключения и операций на серверах", onClick = { nav.navigate("log") })
                SettingRow("Права и данные", "Какие разрешения выданы, что хранится и куда приложение подключается", onClick = { nav.navigate("permissions") })
            }
        }
        item {
            SectionCard {
                Text("Резервная копия", fontWeight = FontWeight.SemiBold, color = Pal.accent2)
                Muted("Туннели, свои серверы (с SSH-ключами), панель и настройки — в одном файле, зашифрованном вашим паролем.")
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.padding(top = 6.dp)) {
                    OutlinedButton(onClick = { backupMode = "export" }) { Text("Сохранить") }
                    OutlinedButton(onClick = { importLauncher.launch(arrayOf("*/*")) }) { Text("Восстановить") }
                }
            }
        }
        item { UpdateSection() }
        item {
            SectionCard {
                Text("О приложении", fontWeight = FontWeight.SemiBold, color = Pal.accent2)
                Row2("Версия", BuildConfig.VERSION_NAME)
                // Версии ядер не спрашиваем у нативных библиотек: их загрузка в главном потоке
                // подвешивала экран и роняла приложение (два Go-ядра в одном процессе).
                Row2("AmneziaWG", app.tunnels.backendVersion())
                Row2("Ядра", BuildConfig.CORE_VERSIONS)
                Row2("Туннелей / серверов", "${data.tunnels.size} / ${data.servers.size}")
            }
            Spacer(Modifier.height(24.dp))
        }
    }

    if (killInfo) AlertDialog(
        onDismissRequest = { killInfo = false },
        title = { Text("Kill Switch") },
        text = { Muted("Android включает его системно: в настройках VPN откройте шестерёнку у «VPS Router» и включите «Постоянная VPN» и «Блокировать подключения без VPN». Тогда при обрыве туннеля трафик не уйдёт мимо него.") },
        confirmButton = { TextButton(onClick = { killInfo = false; runCatching { ctx.startActivity(Intent(Settings.ACTION_VPN_SETTINGS)) }.onFailure { ctx.toast("Откройте Настройки → Сеть → VPN") } }) { Text("Открыть настройки VPN") } },
        dismissButton = { TextButton(onClick = { killInfo = false }) { Text("Закрыть") } },
    )

    if (dnsDialog) {
        var dns by remember { mutableStateOf(s.dnsOverride) }
        val valid = dns.isBlank() || dns.split(',').map { it.trim() }.all { Regex("""^[\d.]+$|^[0-9a-fA-F:]+$""").matches(it) }
        AlertDialog(
            onDismissRequest = { dnsDialog = false },
            title = { Text("DNS-серверы") },
            text = {
                Column {
                    Field(dns, { dns = it }, "Через запятую", placeholder = "1.1.1.1, 8.8.8.8")
                    Muted("Пусто — использовать DNS из конфигурации. Для туннелей панели VPS Router лучше оставить пустым: маршруты по доменам работают через DNS панели.")
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        listOf("1.1.1.1", "8.8.8.8", "9.9.9.9").forEach { d -> TextButton(onClick = { dns = d }) { Text(d, fontSize = 12.sp) } }
                    }
                }
            },
            confirmButton = {
                TextButton(enabled = valid, onClick = { dnsDialog = false; scope.launch { applySettings { it.copy(dnsOverride = dns.trim()) } } }) { Text("Сохранить") }
            },
            dismissButton = { TextButton(onClick = { dnsDialog = false }) { Text("Отмена") } },
        )
    }

    backupMode?.let { mode ->
        var pass by remember { mutableStateOf("") }
        var pass2 by remember { mutableStateOf("") }
        var err by remember { mutableStateOf<String?>(null) }
        val export = mode == "export"
        AlertDialog(
            onDismissRequest = { backupMode = null; pendingImport = null },
            title = { Text(if (export) "Пароль для копии" else "Восстановить из копии") },
            text = {
                Column {
                    if (!export) Muted("Текущие туннели, серверы и настройки будут заменены данными из копии.")
                    Field(pass, { pass = it }, "Пароль", password = true)
                    if (export) {
                        Field(pass2, { pass2 = it }, "Повторите пароль", password = true)
                        Muted("Без этого пароля копию не восстановить.")
                    }
                    err?.let { Text(it, color = Pal.danger, fontSize = 13.sp) }
                }
            },
            confirmButton = {
                TextButton(enabled = pass.length >= 6 && (!export || pass == pass2), onClick = {
                    if (export) {
                        backupPass = pass; backupMode = null
                        exportLauncher.launch("vps-router-" + SimpleDateFormat("yyyyMMdd-HHmm", Locale.ROOT).format(Date()) + ".vpsr")
                    } else {
                        val text = pendingImport ?: return@TextButton
                        scope.launch {
                            val r = runCatching { withContext(Dispatchers.Default) { app.repo.importBackup(text, pass) } }
                            r.onSuccess {
                                backupMode = null; pendingImport = null
                                AppLog.i("Восстановлено из резервной копии")
                                ctx.toast("Данные восстановлены")
                                if (app.repo.data.value.panel != null) app.panel.connect() else app.panel.forget()
                            }.onFailure { err = it.message }
                        }
                    }
                }) { Text(if (export) "Выбрать файл" else "Восстановить") }
            },
            dismissButton = { TextButton(onClick = { backupMode = null; pendingImport = null }) { Text("Отмена") } },
        )
    }
}

/** «Проверить соединение»: понятные причины проблем и что делать. */
@Composable
fun DiagnosticsScreen(nav: NavController) {
    var results by remember { mutableStateOf<List<CheckResult>?>(null) }
    var running by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    fun run() = scope.launch { running = true; results = runCatching { Diagnostics.run() }.getOrElse { listOf(CheckResult("Проверка", CheckState.FAIL, it.message ?: "Ошибка")) }; running = false }
    LaunchedEffect(Unit) { run() }

    Column(Modifier.fillMaxSize()) {
        BackBar("Проверка соединения", nav) { TextButton(enabled = !running, onClick = { run() }) { Text("Повторить") } }
        LazyColumn(Modifier.fillMaxSize().padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            if (running) item {
                Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(vertical = 8.dp)) {
                    CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp); Spacer(Modifier.width(10.dp)); Muted("Проверяем DNS, серверы и панель…")
                }
            }
            results?.let { list ->
                val fails = list.count { it.state == CheckState.FAIL }
                item {
                    Text(
                        if (fails == 0) "Проблем не найдено" else "Найдено проблем: $fails",
                        fontWeight = FontWeight.SemiBold, fontSize = 17.sp, color = if (fails == 0) Pal.ok else Pal.danger,
                    )
                }
                items(list) { r ->
                    SectionCard {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            val (icon, color) = when (r.state) {
                                CheckState.OK -> Icons.Filled.CheckCircle to Pal.ok
                                CheckState.WARN -> Icons.Filled.Warning to Pal.warn
                                CheckState.FAIL -> Icons.Filled.Error to Pal.danger
                            }
                            Icon(icon, null, tint = color, modifier = Modifier.size(20.dp)); Spacer(Modifier.width(10.dp))
                            Text(r.title, fontWeight = FontWeight.Medium)
                        }
                        Muted(r.detail, Modifier.padding(top = 4.dp))
                        r.fix?.let { Text("Что сделать: $it", fontSize = 13.sp, color = Pal.text, modifier = Modifier.padding(top = 4.dp)) }
                    }
                }
            }
            item { Spacer(Modifier.height(24.dp)) }
        }
    }
}

@Composable
fun LogScreen(nav: NavController) {
    val entries by AppLog.entries.collectAsState()
    val ctx = LocalContext.current
    Column(Modifier.fillMaxSize()) {
        BackBar("Журнал", nav) {
            TextButton(enabled = entries.isNotEmpty(), onClick = { ctx.shareText(entries.joinToString("\n"), "Журнал VPS Router") }) { Text("Поделиться") }
            TextButton(enabled = entries.isNotEmpty(), onClick = { AppLog.clear() }) { Text("Очистить") }
        }
        if (entries.isEmpty()) Muted("Журнал пуст. Здесь появятся подключения, вход в панель и операции на серверах.", Modifier.padding(16.dp))
        else LogView(entries.map { e -> if (e.level == 'E') "ERROR " + e.toString() else e.toString() }, Modifier.padding(horizontal = 16.dp))
    }
}
