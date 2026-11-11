package app.vpsrouter.client.ui

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.background
import androidx.compose.foundation.border
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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AddCircleOutline
import androidx.compose.material.icons.filled.Cable
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material.icons.filled.ContentPaste
import androidx.compose.material.icons.filled.Hub
import androidx.compose.material.icons.filled.QrCodeScanner
import androidx.compose.material.icons.filled.Terminal
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavController
import app.vpsrouter.client.App

private fun markOnboarded() = App.instance.repo.update { it.copy(settings = it.settings.copy(onboarded = true)) }

private fun NavController.goHome() = navigate("home") { popUpTo(0) { inclusive = true } }

/** Первый запуск: «Подключить существующий сервер» или «Создать новый». */
@Composable
fun OnboardingScreen(nav: NavController) {
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(20.dp)) {
        Spacer(Modifier.height(24.dp))
        Logo(56)
        Spacer(Modifier.height(18.dp))
        Text("Добро пожаловать в VPS Router", fontSize = 26.sp, fontWeight = FontWeight.Bold, lineHeight = 32.sp)
        Spacer(Modifier.height(6.dp))
        Muted("Простой VPN-клиент для вашей инфраструктуры. Подключитесь к готовому серверу или настройте новый — приложение сделает всё по SSH.")
        Spacer(Modifier.height(24.dp))
        StartChoices(nav, onChosen = ::markOnboarded)
        Spacer(Modifier.height(12.dp))
        TextButton(onClick = { markOnboarded(); nav.goHome() }, modifier = Modifier.align(Alignment.CenterHorizontally)) {
            Text("Пропустить", color = Pal.muted)
        }
    }
}

/** Две большие карточки старта — общие для онбординга и пустой главной. */
@Composable
fun StartChoices(nav: NavController, onChosen: () -> Unit = {}) {
    var expanded by remember { mutableStateOf(false) }
    val (importer, importDialog) = rememberImporter(onPanelLink = { onChosen(); nav.navigate("panel") }) { onChosen(); nav.goHome() }

    BigChoice(
        Icons.Filled.Cable, "Подключить существующий сервер", "У меня уже есть VPS Router или настроенный VPS", Pal.accent, expanded,
    ) { expanded = !expanded }
    AnimatedVisibility(expanded) {
        Column(Modifier.padding(start = 12.dp, top = 8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            SubChoice(Icons.Filled.Hub, "Панель VPS Router", "Вход по адресу или QR — подтянутся конфиги всех устройств") { onChosen(); nav.navigate("panel") }
            SubChoice(Icons.Filled.QrCodeScanner, "Сканировать QR", "Ссылка, конфиг AmneziaWG / WireGuard или QR панели") { importer.scan() }
            SubChoice(Icons.Filled.ContentPaste, "Ссылка, подписка или JSON", "vless://, vmess://, trojan://, ss://, hysteria2://, tuic:// — как в v2rayTun") { importer.paste() }
            SubChoice(Icons.Filled.ChevronRight, "Файл конфигурации", ".conf или .json из мессенджера или почты") { importer.file() }
            SubChoice(Icons.Filled.Terminal, "VPS по SSH", "Сервер уже настроен — добавить доступ и клиентов") { onChosen(); nav.navigate("wizard") }
        }
    }
    Spacer(Modifier.height(12.dp))
    BigChoice(Icons.Filled.AddCircleOutline, "Создать новый сервер", "Настроить VPS с нуля с помощью мастера", Pal.accent2, false) {
        onChosen(); nav.navigate("wizard")
    }
    importDialog()
}

@Composable
private fun BigChoice(icon: ImageVector, title: String, desc: String, color: Color, active: Boolean, onClick: () -> Unit) = Row(
    Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Pal.surface)
        .border(1.dp, if (active) color else Color.Transparent, RoundedCornerShape(16.dp))
        .clickable(onClick = onClick).padding(18.dp),
    verticalAlignment = Alignment.CenterVertically,
) {
    Box(Modifier.size(52.dp).clip(RoundedCornerShape(14.dp)).background(color.copy(alpha = .15f)), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = color, modifier = Modifier.size(28.dp))
    }
    Spacer(Modifier.width(14.dp))
    Column(Modifier.weight(1f)) {
        Text(title, fontWeight = FontWeight.SemiBold, fontSize = 17.sp)
        Muted(desc)
    }
}

@Composable
private fun SubChoice(icon: ImageVector, title: String, desc: String, onClick: () -> Unit) = Row(
    Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(Pal.surface).clickable(onClick = onClick).padding(12.dp),
    verticalAlignment = Alignment.CenterVertically,
) {
    Icon(icon, null, tint = Pal.accent, modifier = Modifier.size(22.dp))
    Spacer(Modifier.width(12.dp))
    Column(Modifier.weight(1f)) { Text(title, fontWeight = FontWeight.Medium); Muted(desc) }
}

/** «Добавить сервер» из выбора сервера на главной. */
@Composable
fun AddScreen(nav: NavController) {
    Column(Modifier.fillMaxSize()) {
        BackBar("Добавить сервер", nav)
        Column(Modifier.verticalScroll(rememberScrollState()).padding(horizontal = 16.dp)) { StartChoices(nav) }
    }
}
