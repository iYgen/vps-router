package app.vpsrouter.client

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.mutableStateOf
import app.vpsrouter.client.ui.AppRoot
import app.vpsrouter.client.ui.VpsRouterTheme

class MainActivity : ComponentActivity() {
    /** Текст .conf, открытого из другого приложения — показываем диалог импорта. */
    val pendingImport = mutableStateOf<String?>(null)

    /** Открыта ссылка vpsrouter://panel — перейти на экран входа в панель. */
    val openPanel = mutableStateOf(false)

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        handleIntent(intent)
        setContent { VpsRouterTheme { AppRoot(this) } }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        handleIntent(intent)
    }

    private fun handleIntent(intent: Intent?) {
        if (intent?.action != Intent.ACTION_VIEW) return
        val uri = intent.data ?: return
        if (uri.scheme == "vpsrouter") {
            app.vpsrouter.client.panel.PanelLink.parse(uri.toString())?.let { App.instance.panel.prefill.value = it; openPanel.value = true }
            return
        }
        runCatching {
            contentResolver.openInputStream(uri)?.bufferedReader()?.use { it.readText() }
        }.getOrNull()?.takeIf { it.contains("[Interface]") }?.let { pendingImport.value = it }
    }
}
