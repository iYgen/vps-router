package app.vpsrouter.client.ui

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import app.vpsrouter.client.App
import app.vpsrouter.client.panel.PanelState
import app.vpsrouter.client.update.ScriptStore
import kotlinx.coroutines.launch
import java.io.File

/** Скачать обновление и запустить установку (с запросом разрешения «Установка из этого источника»). */
@Composable
private fun rememberInstaller(): () -> Unit {
    val app = App.instance
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var ready by remember { mutableStateOf<File?>(null) }
    val permLauncher = rememberLauncherForActivityResult(ActivityResultContracts.StartActivityForResult()) {
        val f = ready
        if (f != null && !app.updater.needsInstallPermission()) ctx.startActivity(app.updater.installIntent(f))
        else if (f != null) ctx.toast("Без разрешения на установку обновить приложение нельзя")
    }
    return {
        scope.launch {
            val client = app.panel.client?.takeIf { app.panel.state.value == PanelState.ONLINE }
            val f = runCatching { app.updater.download(client) }.getOrElse { ctx.toast(it.message ?: "Ошибка загрузки"); return@launch }
            ready = f
            if (app.updater.needsInstallPermission()) {
                ctx.toast("Разрешите установку обновлений для VPS Router")
                permLauncher.launch(app.updater.installPermissionIntent())
            } else ctx.startActivity(app.updater.installIntent(f))
        }
    }
}

/** Плашка на главной — только когда есть новая версия. */
@Composable
fun UpdateBanner() {
    val st by App.instance.updater.state.collectAsState()
    val r = st.available ?: return
    val install = rememberInstaller()
    SectionCard {
        Text("Доступно обновление ${r.versionName}", fontWeight = FontWeight.SemiBold, color = Pal.accent2)
        Muted("Источник: ${st.source?.label ?: "—"}. Установленная версия — ${App.instance.updater.currentVersion}.")
        UpdateProgress(st.progress, st.error)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.padding(top = 6.dp)) {
            Button(enabled = st.progress == null, onClick = install) { Text("Обновить") }
        }
    }
}

@Composable
private fun UpdateProgress(progress: Float?, error: String?) {
    progress?.let {
        Spacer(Modifier.height(6.dp))
        LinearProgressIndicator(progress = { it }, modifier = Modifier.fillMaxWidth(), color = Pal.accent)
        Muted("Загрузка ${(it * 100).toInt()}%")
    }
    error?.let { Text(it, color = Pal.danger, fontSize = 13.sp) }
}

/** Блок в настройках: версия, проверка, установка, источник скриптов. */
@Composable
fun UpdateSection() {
    val app = App.instance
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    val st by app.updater.state.collectAsState()
    var checking by remember { mutableStateOf(false) }
    var scripts by remember { mutableStateOf(ScriptStore.source(ctx)) }
    val install = rememberInstaller()

    SectionCard {
        Text("Обновление", fontWeight = FontWeight.SemiBold, color = Pal.accent2)
        Row2("Установлена", app.updater.currentVersion)
        st.panelVersion?.let { Row2("Версия панели", it) }
        Row2("Скрипты установки", scripts)
        when {
            st.available != null -> Text("Доступна версия ${st.available!!.versionName} (${st.source?.label})", color = Pal.ok, fontWeight = FontWeight.Medium)
            st.checkedAt > 0 -> Muted("Установлена последняя версия (проверено ${ago(st.checkedAt)})")
            else -> Muted("Обновления приходят из GitHub и из панели VPS Router.")
        }
        // Итог последней проверки по каждому источнику — видно, почему обновления нет.
        st.notes.forEach { Muted(it) }
        UpdateProgress(st.progress, st.error)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.padding(top = 6.dp)) {
            if (st.available != null) Button(enabled = st.progress == null, onClick = install) { Text("Обновить") }
            OutlinedButton(enabled = !checking, onClick = {
                scope.launch {
                    checking = true
                    app.updater.checkAll(app.panel.client, app.panel.state.value == PanelState.ONLINE) { app.panel.checkApp(it) }
                    scripts = ScriptStore.source(ctx)
                    checking = false
                    val a = app.updater.state.value.available
                    ctx.toast(if (a != null) "Найдена версия ${a.versionName}" else "Обновлений нет — подробности под кнопкой")
                }
            }) { Text(if (checking) "Проверка…" else "Проверить") }
            if (scripts != "встроенные в приложение") TextButton(onClick = { ScriptStore.reset(ctx); scripts = ScriptStore.source(ctx) }) { Text("Встроенные") }
        }
    }
}
