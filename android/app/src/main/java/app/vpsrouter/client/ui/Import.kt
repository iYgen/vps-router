package app.vpsrouter.client.ui

import android.app.Activity
import android.net.VpnService
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Column
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ContentPaste
import androidx.compose.material.icons.filled.FileOpen
import androidx.compose.material.icons.filled.QrCodeScanner
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.sp
import app.vpsrouter.client.App
import app.vpsrouter.client.data.TunnelProfile
import app.vpsrouter.client.tunnel.BoxConfig
import app.vpsrouter.client.tunnel.ShareLinks
import app.vpsrouter.client.tunnel.TunnelManager
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import kotlinx.coroutines.launch
import org.amnezia.awg.backend.Tunnel

/** Импорт туннеля: QR-код, файл .conf или вставленный текст. */
class Importer internal constructor(
    val scan: () -> Unit,
    val file: () -> Unit,
    val paste: () -> Unit,
)

@Composable
fun rememberImporter(onPanelLink: () -> Unit = {}, onImported: (TunnelProfile) -> Unit = {}): Pair<Importer, @Composable () -> Unit> {
    val ctx = LocalContext.current
    val askFirst = rememberAskFirst()
    var text by remember { mutableStateOf<String?>(null) }
    val scan = rememberLauncherForActivityResult(ScanContract()) { r ->
        val c = r.contents ?: return@rememberLauncherForActivityResult
        val panel = app.vpsrouter.client.panel.PanelLink.parse(c)
        if (panel != null) { App.instance.panel.prefill.value = panel; onPanelLink() } else text = c
    }
    val pick = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
        uri ?: return@rememberLauncherForActivityResult
        text = runCatching { ctx.contentResolver.openInputStream(uri)?.bufferedReader()?.use { it.readText() } }.getOrNull()
            ?: run { ctx.toast("Не удалось прочитать файл"); null }
    }
    val importer = remember {
        Importer(
            scan = { askFirst(Perm.CAMERA) { scan.launch(ScanOptions().setPrompt("Наведите на QR-код конфигурации").setBeepEnabled(false).setOrientationLocked(false)) } },
            file = { pick.launch(arrayOf("*/*")) },
            // Вставить: если в буфере ссылка или JSON — сразу подставляем.
            paste = {
                val clip = (ctx.getSystemService(android.content.Context.CLIPBOARD_SERVICE) as? android.content.ClipboardManager)
                    ?.primaryClip?.takeIf { it.itemCount > 0 }?.getItemAt(0)?.coerceToText(ctx)?.toString().orEmpty().trim()
                val panel = app.vpsrouter.client.panel.PanelLink.parse(clip)
                if (panel != null) { App.instance.panel.prefill.value = panel; onPanelLink() }
                else {
                    if (clip.isEmpty()) ctx.toast("В буфере обмена нет ссылки — вставьте её вручную")
                    text = if (ShareLinks.isLink(clip) || clip.startsWith("{") || clip.contains("[Interface]")) clip else ""
                }
            },
        )
    }
    val dialog: @Composable () -> Unit = {
        text?.let { t ->
            ImportDialog(t, onDismiss = { text = null }) { list ->
                list.forEach { App.instance.repo.upsertTunnel(it) }
                text = null
                if (list.size > 1) ctx.toast("Добавлено серверов: ${list.size}")
                onImported(list.first())
            }
        }
    }
    return importer to dialog
}

@Composable
fun ImportMenu(expanded: Boolean, onDismiss: () -> Unit, importer: Importer) {
    DropdownMenu(expanded = expanded, onDismissRequest = onDismiss) {
        DropdownMenuItem(text = { Text("Сканировать QR") }, leadingIcon = { Icon(Icons.Filled.QrCodeScanner, null) }, onClick = { onDismiss(); importer.scan() })
        DropdownMenuItem(text = { Text("Импорт из файла") }, leadingIcon = { Icon(Icons.Filled.FileOpen, null) }, onClick = { onDismiss(); importer.file() })
        DropdownMenuItem(text = { Text("Вставить текст") }, leadingIcon = { Icon(Icons.Filled.ContentPaste, null) }, onClick = { onDismiss(); importer.paste() })
    }
}

@Composable
fun ImportDialog(initial: String, onDismiss: () -> Unit, onSave: (List<TunnelProfile>) -> Unit) {
    var text by remember { mutableStateOf(initial) }
    var name by remember { mutableStateOf("") }
    var err by remember { mutableStateOf<String?>(null) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Добавить соединение") },
        text = {
            Column {
                Field(name, { name = it }, "Название (необязательно)")
                Field(text, { text = it }, "Ссылка, JSON или .conf", singleLine = false, minLines = 6)
                Muted("Подходят: vless://, vmess://, trojan://, ss://, hysteria2://, tuic://, подписка (несколько ссылок или base64), JSON sing-box/Xray, конфиг AmneziaWG/WireGuard.")
                err?.let { Text(it, color = Pal.danger, fontSize = 12.sp) }
            }
        },
        confirmButton = {
            TextButton(onClick = {
                runCatching { parseImport(text, name) }
                    .onSuccess { onSave(it) }
                    .onFailure { err = it.message ?: "Не удалось разобрать" }
            }) { Text("Сохранить") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Отмена") } },
    )
}

/**
 * Разобрать то, что вставил пользователь: ссылки (одна или подписка), JSON outbound / полный
 * конфиг sing-box или Xray, либо .conf AmneziaWG/WireGuard. Ошибка — с понятным текстом.
 */
fun parseImport(input: String, name: String = ""): List<TunnelProfile> {
    val t = input.trim()
    if (t.isEmpty()) throw IllegalArgumentException("Вставьте ссылку, JSON или конфиг")
    val links = ShareLinks.findAll(t)
    if (links.isNotEmpty()) {
        val errors = mutableListOf<String>()
        val list = links.mapNotNull { l ->
            runCatching { ShareLinks.parse(l) }.onFailure { errors += it.message ?: "?" }.getOrNull()
        }.map { p -> TunnelProfile(name = if (links.size == 1 && name.isNotBlank()) name.trim() else p.name, config = p.outbound, kind = "singbox") }
        if (list.isEmpty()) throw IllegalArgumentException(errors.firstOrNull() ?: "Ссылка не распознана")
        return list
    }
    if (t.startsWith("{")) {
        val out = ShareLinks.outboundFromJson(t)
        return listOf(TunnelProfile(name = name.trim().ifBlank { ShareLinks.protocolOf(out) + (BoxConfig.endpointOf(out)?.let { " " + it.first } ?: "") }, config = out, kind = "singbox"))
    }
    runCatching { TunnelManager.parseConfig(t) }.getOrElse { throw IllegalArgumentException("Не похоже ни на ссылку, ни на JSON, ни на конфиг WireGuard: ${it.message}") }
    return listOf(TunnelProfile(name = name.trim().ifBlank { "WireGuard" }, config = t))
}

/** Подключение/отключение с запросом системного разрешения на VPN. */
@Composable
fun rememberVpnToggle(): (TunnelProfile) -> Unit {
    val app = App.instance
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var pending by remember { mutableStateOf<TunnelProfile?>(null) }
    val askFirst = rememberAskFirst()
    val perm = rememberLauncherForActivityResult(ActivityResultContracts.StartActivityForResult()) { res ->
        val p = pending
        pending = null
        if (res.resultCode == Activity.RESULT_OK && p != null) scope.launch { app.tunnels.start(p) }
        else ctx.toast("Без разрешения на VPN подключение невозможно")
    }
    return { t ->
        val st = app.tunnels.status.value
        if (st.activeId == t.id && st.state == Tunnel.State.UP) scope.launch { app.tunnels.stop() }
        else {
            val intent = VpnService.prepare(ctx)
            if (intent != null) askFirst(Perm.VPN) { VpnService.prepare(ctx)?.let { pending = t; perm.launch(it) } ?: scope.launch { app.tunnels.start(t) } }
            else scope.launch { app.tunnels.start(t) }
        }
    }
}
