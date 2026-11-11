package app.vpsrouter.client.ui

import android.net.VpnService
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.CallSplit
import androidx.compose.material.icons.filled.Dns
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Link
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.sp
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import app.vpsrouter.client.App
import app.vpsrouter.client.MainActivity
import app.vpsrouter.client.data.TunnelProfile
import app.vpsrouter.client.panel.PanelState
import org.amnezia.awg.backend.Tunnel

private data class Tab(val route: String, val label: String, val icon: ImageVector)

private val tabs = listOf(
    Tab("home", "Главная", Icons.Filled.Home),
    Tab("servers", "Серверы", Icons.Filled.Dns),
    Tab("routes", "Маршруты", Icons.AutoMirrored.Filled.CallSplit),
    Tab("links", "Связи", Icons.Filled.Link),
    Tab("settings", "Настройки", Icons.Filled.Settings),
)

@Composable
fun AppRoot(activity: MainActivity) {
    val app = App.instance
    val ctx = LocalContext.current
    val nav = rememberNavController()
    val entry by nav.currentBackStackEntryAsState()
    val route = entry?.destination?.route
    val appData by app.repo.data.collectAsState()
    // Вкладка «Серверы» — только когда у пользователя есть свои серверы: пустое не показываем.
    val shown = tabs.filter { it.route != "servers" || appData.servers.isNotEmpty() }

    // Первый запуск — онбординг; если уже что-то настроено (обновление со старой версии) — сразу главная.
    val start = remember {
        val d = app.repo.data.value
        if (!d.settings.onboarded && d.tunnels.isEmpty() && d.servers.isEmpty() && d.panel == null) "onboarding" else "home"
    }

    LaunchedEffect(Unit) {
        // Автоподключение — только если разрешение на VPN уже выдано (без всплывающих окон при старте).
        val d = app.repo.data.value
        val st = app.tunnels.status.value
        if (d.settings.autoConnect && st.state != Tunnel.State.UP && VpnService.prepare(ctx) == null) {
            (d.tunnels.firstOrNull { it.id == d.lastTunnelId } ?: d.tunnels.firstOrNull())?.let { app.tunnels.start(it) }
        }
        // Тихо пробуем подключиться к панели (если она настроена).
        if (app.panel.state.value == PanelState.OFFLINE) app.panel.connect()
        // Обновление приложения из GitHub — не зависит от панели (раз в 6 часов).
        if (app.repo.data.value.settings.githubUpdates) app.updater.checkGithubIfStale()
    }

    // Ссылка vpsrouter://panel из QR/браузера — на экран входа в панель.
    val openPanel by activity.openPanel
    LaunchedEffect(openPanel) {
        if (openPanel) {
            activity.openPanel.value = false
            app.repo.update { it.copy(settings = it.settings.copy(onboarded = true)) }
            if (app.panel.state.value != PanelState.NOT_CONFIGURED) {
                app.panel.prefill.value = null
                ctx.toast("Панель уже подключена. Чтобы сменить её, нажмите «Выйти» на экране панели.")
            }
            nav.navigate("panel")
        }
    }

    Scaffold(
        containerColor = Pal.bg,
        topBar = { if (route in tabs.map { it.route }) BrandBar(nav) },
        bottomBar = {
            if (route in tabs.map { it.route }) {
                NavigationBar(containerColor = Pal.surface) {
                    shown.forEach { t ->
                        NavigationBarItem(
                            selected = route == t.route,
                            onClick = {
                                nav.navigate(t.route) {
                                    popUpTo(nav.graph.findStartDestination().id) { saveState = true }
                                    launchSingleTop = true
                                    restoreState = true
                                }
                            },
                            icon = { Icon(t.icon, null) },
                            label = { Text(t.label, fontSize = 11.sp, maxLines = 1, softWrap = false) },
                            colors = NavigationBarItemDefaults.colors(indicatorColor = Pal.accent.copy(alpha = .18f), selectedIconColor = Pal.accent, selectedTextColor = Pal.accent),
                        )
                    }
                }
            }
        },
    ) { pad ->
        NavHost(nav, startDestination = start, modifier = Modifier.padding(pad)) {
            composable("onboarding") { OnboardingScreen(nav) }
            composable("home") { HomeScreen(nav) }
            composable("servers") { ServersScreen(nav) }
            composable("routes") { RoutesScreen(nav) }
            composable("links") { LinksScreen(nav) }
            composable("settings") { SettingsScreen(nav) }
            composable("add") { AddScreen(nav) }
            composable("apps") { AppPickerScreen(nav) }
            composable("apps/hidden") { AppPickerScreen(nav, hidden = true) }
            composable("diagnostics") { DiagnosticsScreen(nav) }
            composable("log") { LogScreen(nav) }
            composable("tunnel/{id}") { TunnelDetailScreen(it.arguments?.getString("id").orEmpty(), nav) }
            composable("panel") { PanelScreen(nav) }
            composable("server/{id}") { ServerDetailScreen(it.arguments?.getString("id").orEmpty(), nav) }
            composable("wizard") { AddServerWizard(nav) }
            composable("panel-relay") { PanelRelayWizard(nav) }
            composable("permissions") { PermissionsScreen(nav) }
        }
    }

    // После входа в панель (QR или пароль) — сразу на главную с нижним меню, без онбординга в истории.
    val loggedIn by app.panel.justLoggedIn.collectAsState()
    LaunchedEffect(loggedIn) {
        if (!loggedIn) return@LaunchedEffect
        app.panel.justLoggedIn.value = false
        app.repo.update { it.copy(settings = it.settings.copy(onboarded = true)) }
        nav.navigate("home") { popUpTo(0) { inclusive = true } }
    }
    DeviceChoiceDialog()
    ConsentGate(nav, route)

    // .conf, открытый из файлового менеджера/мессенджера
    val pending by activity.pendingImport
    pending?.let { text ->
        ImportDialog(text, onDismiss = { activity.pendingImport.value = null }) { list ->
            list.forEach { app.repo.upsertTunnel(it) }
            app.repo.update { it.copy(settings = it.settings.copy(onboarded = true)) }
            activity.pendingImport.value = null
            ctx.toast(if (list.size == 1) "Сервер «${list[0].name}» добавлен" else "Добавлено серверов: ${list.size}")
        }
    }
}
