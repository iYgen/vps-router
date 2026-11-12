package app.vpsrouter.client.ui

import android.Manifest
import android.content.ClipboardManager
import android.content.Context
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CameraAlt
import androidx.compose.material.icons.filled.ContentPaste
import androidx.compose.material.icons.filled.Image
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.unit.dp
import androidx.core.content.FileProvider
import app.vpsrouter.client.data.SshCreds
import com.google.mlkit.vision.common.InputImage
import com.google.mlkit.vision.text.TextRecognition
import com.google.mlkit.vision.text.latin.TextRecognizerOptions
import java.io.File

/**
 * «Заполнить из письма хостера»: фото экрана, скриншот или текст из буфера обмена.
 * Текст распознаётся на телефоне (ML Kit, без интернета), из него берутся IP, порт, логин и пароль.
 * Перед подстановкой пользователь видит, что найдено, — ничего не сохраняется само.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun CredsCapture(onFound: (SshCreds) -> Unit) {
    val ctx = LocalContext.current
    val askFirst = rememberAskFirst()
    var busy by remember { mutableStateOf(false) }
    var found by remember { mutableStateOf<SshCreds?>(null) }
    var photoUri by remember { mutableStateOf<Uri?>(null) }

    fun recognize(uri: Uri) {
        busy = true
        val img = runCatching { InputImage.fromFilePath(ctx, uri) }.getOrElse { busy = false; ctx.toast("Не удалось открыть изображение"); return }
        val rec = TextRecognition.getClient(TextRecognizerOptions.DEFAULT_OPTIONS)
        rec.process(img)
            .addOnSuccessListener { r -> show(ctx, r.text) { found = it } }
            .addOnFailureListener { ctx.toast("Не удалось распознать текст: ${it.message}") }
            .addOnCompleteListener { busy = false; rec.close(); if (uri == photoUri) runCatching { File(ctx.cacheDir, "ocr/shot.jpg").delete() } }
    }

    val takePhoto = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { ok -> photoUri?.let { if (ok) recognize(it) } }
    val camPerm = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { ok ->
        if (ok) photoUri?.let(takePhoto::launch) else ctx.toast("Без камеры можно выбрать скриншот")
    }
    val pickImage = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri -> uri?.let(::recognize) }

    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Muted("Заполнить из письма хостера — IP и пароль найдутся сами:")
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            OutlinedButton(onClick = {
                val f = File(ctx.cacheDir, "ocr").apply { mkdirs() }.let { File(it, "shot.jpg") }
                photoUri = FileProvider.getUriForFile(ctx, ctx.packageName + ".updates", f)
                askFirst(Perm.CAMERA) { camPerm.launch(Manifest.permission.CAMERA) }
            }, enabled = !busy) { Icon(Icons.Filled.CameraAlt, null, Modifier.size(18.dp)); Text(" Фото") }
            OutlinedButton(onClick = { pickImage.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }, enabled = !busy) {
                Icon(Icons.Filled.Image, null, Modifier.size(18.dp)); Text(" Скриншот")
            }
            OutlinedButton(onClick = {
                val clip = (ctx.getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager)
                    ?.primaryClip?.takeIf { it.itemCount > 0 }?.getItemAt(0)?.coerceToText(ctx)?.toString().orEmpty()
                if (clip.isBlank()) ctx.toast("Буфер обмена пуст") else show(ctx, clip) { found = it }
            }, enabled = !busy) { Icon(Icons.Filled.ContentPaste, null, Modifier.size(18.dp)); Text(" Из буфера") }
            if (busy) CircularProgressIndicator(Modifier.size(24.dp).width(24.dp))
        }
    }

    found?.let { c ->
        AlertDialog(
            onDismissRequest = { found = null },
            title = { Text("Проверьте, что найдено") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Row2("IP / адрес", c.host ?: "—")
                    Row2("Порт", c.port ?: "не найден (будет 22)")
                    Row2("Пользователь", c.user ?: "не найден (будет root)")
                    Text("Пароль", color = Pal.muted)
                    Text(c.password ?: "не найден", fontFamily = FontFamily.Monospace)
                    Muted("Распознавание может ошибиться в похожих символах (0/O, l/1/I) — после подстановки проверьте поля.")
                }
            },
            confirmButton = { Button(onClick = { onFound(c); found = null }) { Text("Подставить") } },
            dismissButton = { TextButton(onClick = { found = null }) { Text("Отмена") } },
        )
    }
}

private fun show(ctx: Context, text: String, set: (SshCreds) -> Unit) {
    val c = SshCreds.parse(text)
    if (c.isEmpty) ctx.toast("Не нашёл IP или пароль. Попробуйте снять крупнее или вставить текст письма.") else set(c)
}
