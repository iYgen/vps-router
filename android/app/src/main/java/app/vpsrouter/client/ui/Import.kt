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
            paste = { text = "" },
        )
    }
    val dialog: @Composable () -> Unit = {
        text?.let { t ->
            ImportDialog(t, onDismiss = { text = null }) { name, conf ->
                val p = TunnelProfile(name = name, config = conf)
                App.instance.repo.upsertTunnel(p)
                text = null
                onImported(p)
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
fun ImportDialog(initial: String, onDismiss: () -> Unit, onSave: (String, String) -> Unit) {
    var text by remember { mutableStateOf(initial) }
    var name by remember { mutableStateOf("Туннель") }
    var err by remember { mutableStateOf<String?>(null) }
    val ctx = LocalContext.current
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Импорт конфигурации") },
        text = {
            Column {
                Field(name, { name = it }, "Название")
                Field(text, { text = it }, "Конфиг AmneziaWG / WireGuard (.conf)", singleLine = false, minLines = 6)
                err?.let { Text(it, color = Pal.danger, fontSize = 12.sp) }
            }
        },
        confirmButton = {
            TextButton(onClick = {
                val t = text.trim()
                if (Regex("""^(vless|vmess|ss|trojan|hy2|hysteria2|tuic)://""").containsMatchIn(t)) {
                    ctx.openLink(t); onDismiss(); return@TextButton
                }
                err = runCatching { TunnelManager.parseConfig(t); null }.getOrElse { "Неверный конфиг: ${it.message}" }
                if (err == null) onSave(name.ifBlank { "Туннель" }, t)
            }) { Text("Сохранить") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Отмена") } },
    )
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
