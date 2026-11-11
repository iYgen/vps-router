package app.vpsrouter.client.ui

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.widget.Toast
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.google.zxing.BarcodeFormat
import com.google.zxing.EncodeHintType
import com.google.zxing.qrcode.QRCodeWriter

@Composable
fun SectionCard(modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    Card(
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(14.dp),
        colors = CardDefaults.cardColors(containerColor = Pal.surface),
    ) { Column(Modifier.padding(14.dp), content = content) }
}

@Composable
fun Dot(color: Color, size: Int = 8) = Box(Modifier.size(size.dp).clip(CircleShape).background(color))

@Composable
fun StatusPill(text: String, color: Color) {
    Row(
        Modifier.clip(RoundedCornerShape(50)).background(color.copy(alpha = .14f)).padding(horizontal = 8.dp, vertical = 3.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Dot(color, 6); Spacer(Modifier.width(5.dp))
        Text(text, color = color, fontSize = 11.sp, fontWeight = FontWeight.Medium)
    }
}

@Composable
fun Muted(text: String, modifier: Modifier = Modifier) =
    Text(text, color = Pal.muted, fontSize = 13.sp, modifier = modifier)

@Composable
fun Field(
    value: String, onChange: (String) -> Unit, label: String,
    modifier: Modifier = Modifier, password: Boolean = false, number: Boolean = false,
    singleLine: Boolean = true, placeholder: String? = null, minLines: Int = 1,
) = OutlinedTextField(
    value = value, onValueChange = onChange, label = { Text(label) },
    placeholder = placeholder?.let { { Text(it, color = Pal.muted) } },
    singleLine = singleLine, minLines = minLines,
    visualTransformation = if (password) PasswordVisualTransformation() else VisualTransformation.None,
    keyboardOptions = KeyboardOptions(keyboardType = when { password -> KeyboardType.Password; number -> KeyboardType.Number; else -> KeyboardType.Text }),
    modifier = modifier.fillMaxWidth().padding(vertical = 4.dp),
)

/** Журнал выполнения скрипта на сервере (как живой лог мастера в панели). */
@Composable
fun LogView(lines: List<String>, modifier: Modifier = Modifier) {
    val scroll = rememberScrollState()
    LaunchedEffect(lines.size) { scroll.animateScrollTo(scroll.maxValue) }
    Box(
        modifier.fillMaxWidth().heightIn(min = 120.dp, max = 320.dp).clip(RoundedCornerShape(10.dp))
            .background(Color(0xFF0E0E11)).padding(10.dp)
    ) {
        Column(Modifier.verticalScroll(scroll).horizontalScroll(rememberScrollState())) {
            lines.takeLast(400).forEach { l ->
                Text(
                    l, fontFamily = FontFamily.Monospace, fontSize = 11.sp, softWrap = false,
                    color = when {
                        l.contains("ERROR") -> Pal.danger
                        l.contains("WARN") -> Pal.warn
                        l.contains("Готово") || l.contains("Пересылка:") -> Pal.ok
                        else -> Color(0xFFCFCFD6)
                    },
                )
            }
        }
    }
}

fun qrBitmap(text: String, size: Int = 720): Bitmap {
    val m = QRCodeWriter().encode(text, BarcodeFormat.QR_CODE, size, size, mapOf(EncodeHintType.MARGIN to 1))
    val bmp = Bitmap.createBitmap(size, size, Bitmap.Config.RGB_565)
    for (x in 0 until size) for (y in 0 until size) bmp.setPixel(x, y, if (m[x, y]) android.graphics.Color.BLACK else android.graphics.Color.WHITE)
    return bmp
}

@Composable
fun QrDialog(title: String, text: String, onDismiss: () -> Unit) {
    val bmp = remember(text) { runCatching { qrBitmap(text) }.getOrNull() }
    AlertDialog(
        onDismissRequest = onDismiss,
        confirmButton = { TextButton(onClick = onDismiss) { Text("Закрыть") } },
        title = { Text(title) },
        text = {
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                if (bmp != null) Image(bmp.asImageBitmap(), null, Modifier.fillMaxWidth().clip(RoundedCornerShape(8.dp)))
                else Muted("Конфиг слишком большой для QR — используйте «Поделиться»")
                Spacer(Modifier.size(8.dp))
                Muted("Сканируется приложениями AmneziaWG / AmneziaVPN / v2rayNG")
            }
        },
    )
}

@Composable
fun ConfirmDialog(title: String, text: String, confirm: String = "Удалить", onConfirm: () -> Unit, onDismiss: () -> Unit) = AlertDialog(
    onDismissRequest = onDismiss,
    confirmButton = { TextButton(onClick = { onConfirm(); onDismiss() }) { Text(confirm, color = Pal.danger) } },
    dismissButton = { TextButton(onClick = onDismiss) { Text("Отмена") } },
    title = { Text(title) },
    text = { Text(text) },
)

@Composable
fun Row2(label: String, value: String) = Row(Modifier.fillMaxWidth().padding(vertical = 2.dp), horizontalArrangement = Arrangement.SpaceBetween) {
    Muted(label); Text(value, fontSize = 13.sp, fontFamily = FontFamily.Monospace)
}

fun Context.copy(text: String, label: String = "Скопировано") {
    (getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager).setPrimaryClip(ClipData.newPlainText(label, text))
    Toast.makeText(this, label, Toast.LENGTH_SHORT).show()
}

fun Context.toast(text: String) = Toast.makeText(this, text, Toast.LENGTH_LONG).show()

fun Context.shareText(text: String, subject: String) {
    val i = Intent(Intent.ACTION_SEND).apply {
        type = "text/plain"
        putExtra(Intent.EXTRA_SUBJECT, subject)
        putExtra(Intent.EXTRA_TEXT, text)
    }
    startActivity(Intent.createChooser(i, subject))
}

/** Открыть ссылку vless:// и т.п. в установленном клиенте (v2rayNG, Hiddify…). */
fun Context.openLink(uri: String) {
    try {
        startActivity(Intent(Intent.ACTION_VIEW, android.net.Uri.parse(uri)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    } catch (e: Exception) {
        toast("Нет приложения для этой ссылки — скопируйте её в v2rayNG / Hiddify / Happ")
    }
}

fun formatBytes(b: Long): String = when {
    b < 1024 -> "$b Б"
    b < 1024 * 1024 -> "%.1f КБ".format(b / 1024.0)
    b < 1024L * 1024 * 1024 -> "%.1f МБ".format(b / 1048576.0)
    else -> "%.2f ГБ".format(b / 1073741824.0)
}

fun ago(ts: Long?): String {
    if (ts == null || ts <= 0) return "никогда"
    val s = (System.currentTimeMillis() - ts) / 1000
    return when {
        s < 5 -> "только что"
        s < 60 -> "$s с назад"
        s < 3600 -> "${s / 60} мин назад"
        s < 86400 -> "${s / 3600} ч назад"
        else -> "${s / 86400} д назад"
    }
}
