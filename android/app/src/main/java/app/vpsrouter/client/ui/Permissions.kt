package app.vpsrouter.client.ui

import android.Manifest
import android.app.Activity
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.net.VpnService
import android.os.Build
import android.provider.Settings
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Apps
import androidx.compose.material.icons.filled.CameraAlt
import androidx.compose.material.icons.filled.CloudDownload
import androidx.compose.material.icons.filled.InstallMobile
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.Public
import androidx.compose.material.icons.filled.VpnKey
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.navigation.NavController
import app.vpsrouter.client.App
import androidx.compose.runtime.DisposableEffect

/** Текущая версия текста согласия: при существенных изменениях увеличить — пользователь увидит его снова. */
const val CONSENT_VERSION = 1

/**
 * Права приложения. Обязательное — только VPN; остальное по желанию, и без него
 * приложение работает, просто без соответствующей функции.
 */
enum class Perm(
    val icon: ImageVector,
    val title: String,
    val why: String,
    val ifDenied: String,
    val required: Boolean,
) {
    VPN(Icons.Filled.VpnKey, "VPN-подключение",
        "Создать защищённый туннель до вашего сервера. Android покажет ключ в строке состояния, пока VPN включён.",
        "Без него приложение не сможет подключиться — это его основная функция.", true),
    CAMERA(Icons.Filled.CameraAlt, "Камера",
        "Только чтобы отсканировать QR-код панели или туннеля. Снимки не сохраняются и никуда не отправляются.",
        "Можно отказаться: настройки вводятся вручную или из файла.", false),
    NOTIFICATIONS(Icons.Filled.Notifications, "Уведомления",
        "Показывать скорость VPN в строке состояния, а на Android 16+ — в «островке» у камеры, если вы включите эту функцию.",
        "Можно отказаться: скорость будет видна только внутри приложения.", false),
    INSTALL(Icons.Filled.InstallMobile, "Установка обновлений",
        "Установить новую версию VPS Router, скачанную из GitHub или вашей панели. Каждую установку вы подтверждаете сами.",
        "Можно отказаться: обновляйте приложение вручную.", false);

    /** null — система не позволяет узнать (считаем «не выдано»). */
    fun granted(ctx: Context): Boolean = when (this) {
        VPN -> VpnService.prepare(ctx) == null
        CAMERA -> ctx.checkSelfPermission(Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED
        NOTIFICATIONS -> Build.VERSION.SDK_INT < 33 || ctx.checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED
        INSTALL -> Build.VERSION.SDK_INT < 26 || ctx.packageManager.canRequestPackageInstalls()
    }

    /** Системные настройки, где это право можно выдать или забрать. */
    fun settingsIntent(ctx: Context): Intent = when (this) {
        VPN -> Intent(Settings.ACTION_VPN_SETTINGS)
        INSTALL -> Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:${ctx.packageName}"))
        NOTIFICATIONS -> if (Build.VERSION.SDK_INT >= 26) Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, ctx.packageName)
            else appDetails(ctx)
        CAMERA -> appDetails(ctx)
    }.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)

    private fun appDetails(ctx: Context) = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:${ctx.packageName}"))
}

/**
 * «Сначала объясни, потом спроси»: если права ещё нет, показывает, зачем оно и что будет
 * при отказе, и только после «Разрешить» вызывает системный запрос. Право уже есть — сразу действие.
 */
@Composable
fun rememberAskFirst(): (Perm, () -> Unit) -> Unit {
    val ctx = LocalContext.current
    var ask by remember { mutableStateOf<Pair<Perm, () -> Unit>?>(null) }
    ask?.let { (p, go) ->
        AlertDialog(
            onDismissRequest = { ask = null },
            icon = { Icon(p.icon, null, tint = Pal.accent) },
            title = { Text("Нужно разрешение: ${p.title.lowercase()}") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(p.why)
                    Muted(p.ifDenied)
                    Muted("Дальше Android спросит сам — решение всегда можно изменить в «Настройки → Права и данные».")
                }
            },
            confirmButton = { Button(onClick = { ask = null; go() }) { Text("Разрешить") } },
            dismissButton = { TextButton(onClick = { ask = null }) { Text("Не сейчас") } },
        )
    }
    return { p, go -> if (p.granted(ctx)) go() else ask = p to go }
}

/** Права, данные и сетевые обращения приложения — всё в одном месте. */
@Composable
fun PermissionsScreen(nav: NavController) {
    val ctx = LocalContext.current
    val app = App.instance
    val data by app.repo.data.collectAsState()
    // Перечитывать статусы, когда пользователь вернулся из системных настроек.
    var tick by remember { mutableIntStateOf(0) }
    val owner = LocalLifecycleOwner.current
    DisposableEffect(owner) {
        val obs = LifecycleEventObserver { _, e -> if (e == Lifecycle.Event.ON_RESUME) tick++ }
        owner.lifecycle.addObserver(obs)
        onDispose { owner.lifecycle.removeObserver(obs) }
    }
    Column(Modifier.fillMaxSize()) {
        BackBar("Права и данные", nav)
        LazyColumn(Modifier.padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item { Muted("Приложение просит только то, без чего не работает нужная вам функция. Обязательное — одно: VPN.") }
            Perm.entries.forEach { p ->
                item(key = p.name + tick) { PermCard(p, p.granted(ctx)) { ctx.startActivity(p.settingsIntent(ctx)) } }
            }
            item {
                InfoCard(Icons.Filled.Apps, "Список приложений", "Выдаётся автоматически",
                    "Видны только приложения с иконкой на рабочем столе — чтобы вы могли выбрать, какие пускать через VPN или скрыть от VPN. Список не покидает телефон.")
            }
            item {
                InfoCard(Icons.Filled.Public, "Интернет и состояние сети", "Выдаётся автоматически",
                    "Подключение к вашим серверам и панели, переподключение при смене Wi-Fi/мобильной сети.")
            }
            item {
                SectionCard {
                    Text("Куда приложение подключается", fontWeight = FontWeight.SemiBold, color = Pal.accent2)
                    Muted("• Ваши серверы и ваша панель VPS Router — по адресам, которые вы добавили.")
                    Muted("• GitHub (raw.githubusercontent.com) — проверка и загрузка обновлений, если включено ниже.")
                    Muted("• Больше никуда: нет рекламы, аналитики, сбора статистики и сторонних SDK.")
                    Spacer(Modifier.height(4.dp))
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Filled.CloudDownload, null, tint = Pal.muted, modifier = Modifier.size(20.dp))
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text("Проверять обновления в GitHub")
                            Muted("Раз в 6 часов при запуске. GitHub видит ваш IP-адрес, как при открытии любого сайта.")
                        }
                        Switch(data.settings.githubUpdates, { on -> app.repo.update { it.copy(settings = it.settings.copy(githubUpdates = on)) } })
                    }
                }
            }
            item {
                SectionCard {
                    Text("Что хранится на телефоне", fontWeight = FontWeight.SemiBold, color = Pal.accent2)
                    Muted("Ключи туннелей, SSH-доступы к серверам и вход в панель хранятся зашифрованными (AES-256, ключ в защищённом хранилище Android). В облачный бэкап Android они не попадают.")
                    Muted("Резервную копию вы делаете сами — файл шифруется вашим паролем.")
                    Muted("При входе в панель по QR-коду пароль не хранится: приложение получает отдельный ключ, его можно отозвать в панели.")
                }
            }
            item {
                OutlinedButton(onClick = { app.repo.update { it.copy(settings = it.settings.copy(consentVersion = 0)) } }, modifier = Modifier.fillMaxWidth()) {
                    Text("Показать условия ещё раз")
                }
                Spacer(Modifier.height(24.dp))
            }
        }
    }
}

@Composable
private fun PermCard(p: Perm, granted: Boolean, open: () -> Unit) = SectionCard {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(p.icon, null, tint = if (granted) Pal.ok else Pal.muted)
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(p.title, fontWeight = FontWeight.Medium)
            Text(if (p.required) "обязательно" else "по желанию", color = if (p.required) Pal.accent else Pal.muted, fontSize = 12.sp)
        }
        StatusPill(if (granted) "разрешено" else "не выдано", if (granted) Pal.ok else Pal.muted)
    }
    Spacer(Modifier.height(6.dp))
    Text(p.why, fontSize = 14.sp)
    Muted(p.ifDenied)
    TextButton(onClick = open, modifier = Modifier.padding(top = 2.dp)) { Text(if (granted) "Отозвать в настройках Android" else "Открыть настройки Android") }
}

@Composable
private fun InfoCard(icon: ImageVector, title: String, status: String, text: String) = SectionCard {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, tint = Pal.muted)
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(title, fontWeight = FontWeight.Medium)
            Text(status, color = Pal.muted, fontSize = 12.sp)
        }
    }
    Spacer(Modifier.height(6.dp))
    Muted(text)
}

/**
 * Согласие при первом запуске (и после существенных изменений): что приложение делает,
 * какие права обязательны, какие нет, и можно ли проверять обновления в GitHub.
 * Без согласия приложение не продолжает — «Не согласен» закрывает его.
 */
@Composable
fun ConsentGate(nav: NavController, route: String?) {
    val app = App.instance
    val ctx = LocalContext.current
    val data by app.repo.data.collectAsState()
    // Пока человек читает подробности — не перекрываем их; вернётся назад — окно снова.
    if (data.settings.consentVersion >= CONSENT_VERSION || route == "permissions") return
    var github by remember { mutableStateOf(data.settings.githubUpdates) }
    LaunchedEffect(Unit) { github = data.settings.githubUpdates }
    AlertDialog(
        onDismissRequest = {},
        title = { Text("Прежде чем начать") },
        text = {
            Column(Modifier.heightIn(max = 460.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("VPS Router подключает телефон к вашему собственному серверу через VPN.")
                Text("Обязательно", fontWeight = FontWeight.SemiBold, color = Pal.accent)
                Muted("• VPN-подключение — Android спросит при первом включении.")
                Text("По желанию — спросим, когда понадобится", fontWeight = FontWeight.SemiBold, color = Pal.accent2)
                Muted("• Камера — только для сканирования QR.")
                Muted("• Уведомления — скорость в строке состояния.")
                Muted("• Установка обновлений — каждую установку подтверждаете вы.")
                Text("Чего приложение не делает", fontWeight = FontWeight.SemiBold, color = Pal.accent2)
                Muted("Нет рекламы, аналитики и сбора данных. Не читает контакты, SMS и местоположение; из файлов — только тот, что вы сами выберете для импорта. Ключи и доступы хранятся на телефоне в зашифрованном виде.")
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text("Проверять обновления в GitHub")
                        Muted("Обращение к github.com раз в 6 часов")
                    }
                    Switch(github, { github = it })
                }
            }
        },
        confirmButton = {
            Button(onClick = {
                app.repo.update { it.copy(settings = it.settings.copy(consentVersion = CONSENT_VERSION, githubUpdates = github)) }
            }) { Text("Согласен") }
        },
        dismissButton = {
            Row {
                TextButton(onClick = { nav.navigate("permissions") }) { Text("Подробнее") }
                TextButton(onClick = { (ctx as? Activity)?.finish() }) { Text("Не согласен") }
            }
        },
    )
}
