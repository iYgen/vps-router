package app.vpsrouter.client.ui

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

/** Палитра как у веб-панели vps_router (login.php): тёмный фон, розовый/фиолетовый акценты. */
object Pal {
    val bg = Color(0xFF151519)
    val surface = Color(0xFF1E1E24)
    val surface2 = Color(0xFF26262E)
    val text = Color(0xFFF5F5F7)
    val muted = Color(0xFF8B90A6)
    val accent = Color(0xFFFF4085)
    val accent2 = Color(0xFFA76BFF)
    val ok = Color(0xFF25D88A)
    val warn = Color(0xFFFFB547)
    val danger = Color(0xFFFF5C5C)
}

@Composable
fun VpsRouterTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = darkColorScheme(
            primary = Pal.accent,
            onPrimary = Color.White,
            secondary = Pal.accent2,
            onSecondary = Color.White,
            background = Pal.bg,
            onBackground = Pal.text,
            surface = Pal.surface,
            onSurface = Pal.text,
            surfaceVariant = Pal.surface2,
            onSurfaceVariant = Pal.muted,
            surfaceContainer = Pal.surface,
            surfaceContainerHigh = Pal.surface2,
            surfaceContainerHighest = Pal.surface2,
            surfaceContainerLow = Pal.surface,
            error = Pal.danger,
        ),
        content = content,
    )
}
