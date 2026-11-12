package app.vpsrouter.client.tunnel

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.Typeface
import android.graphics.drawable.Icon
import android.os.Build
import app.vpsrouter.client.App
import app.vpsrouter.client.MainActivity
import app.vpsrouter.client.R
import app.vpsrouter.client.data.TunnelProfile

/**
 * Скорость VPN в строке состояния, рядом с камерой: значок уведомления рисуется
 * на лету — сверху входящая скорость, снизу исходящая. Обновляется раз в секунду,
 * без звука и вибрации. Включается кнопкой в шапке приложения или в настройках.
 */
object SpeedNotifier {
    private const val CHANNEL = "speed"
    private const val ID = 4201

    private var shown = false
    private var lastKey = ""

    fun canPost(ctx: Context): Boolean =
        Build.VERSION.SDK_INT < 33 || ctx.checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED

    private fun nm(ctx: Context) = ctx.getSystemService(NotificationManager::class.java)

    private fun ensureChannel(ctx: Context) {
        if (nm(ctx).getNotificationChannel(CHANNEL) != null) return
        nm(ctx).createNotificationChannel(
            NotificationChannel(CHANNEL, "Скорость VPN", NotificationManager.IMPORTANCE_LOW).apply {
                description = "Входящая и исходящая скорость в строке состояния"
                setShowBadge(false)
                enableVibration(false)
                setSound(null, null)
            }
        )
    }

    /** Короткая запись скорости для значка: 950, 12K, 3.4M. */
    fun short(bps: Long): String = when {
        bps < 1000 -> "$bps"
        bps < 1_000_000 -> "${bps / 1000}K"
        bps < 10_000_000 -> String.format(java.util.Locale.US, "%.1fM", bps / 1_000_000.0)
        else -> "${bps / 1_000_000}M"
    }

    private fun human(bps: Long): String = when {
        bps < 1024 -> "$bps Б/с"
        bps < 1024 * 1024 -> String.format(java.util.Locale.US, "%.0f КБ/с", bps / 1024.0)
        else -> String.format(java.util.Locale.US, "%.1f МБ/с", bps / 1024.0 / 1024.0)
    }

    /** Значок 96×96: две строки текста на весь размер (система красит его в цвет строки состояния). */
    fun iconBitmap(down: Long, up: Long): Bitmap {
        val size = 96
        val bmp = Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888)
        val c = Canvas(bmp)
        val p = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            color = Color.WHITE
            typeface = Typeface.create(Typeface.DEFAULT, Typeface.BOLD)
            textAlign = Paint.Align.CENTER
        }
        fun line(text: String, baseline: Float, maxSize: Float) {
            p.textSize = maxSize
            val w = p.measureText(text)
            if (w > size - 2) p.textSize = maxSize * (size - 2) / w
            c.drawText(text, size / 2f, baseline, p)
        }
        line("↓" + short(down), 44f, 46f)
        line("↑" + short(up), 92f, 40f)
        return bmp
    }

    fun update(ctx: Context, down: Long, up: Long, title: String) {
        if (!canPost(ctx)) return
        // Без изменений не перерисовываем — меньше нагрузки на SystemUI.
        val key = short(down) + "/" + short(up) + "/" + title
        if (shown && key == lastKey) return
        lastKey = key
        ensureChannel(ctx)
        val open = openApp(ctx)
        val b = Notification.Builder(ctx, CHANNEL)
            .setSmallIcon(Icon.createWithBitmap(iconBitmap(down, up)))
            .setContentTitle("↓ ${human(down)}   ↑ ${human(up)}")
            .setContentText(title)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setShowWhen(false)
            .setCategory(Notification.CATEGORY_STATUS)
            .setContentIntent(open)
            .addAction(action(ctx, "Отключиться", VpnActionReceiver.DISCONNECT))
        promote(b, "↓" + short(down))
        val n = b.build()
        runCatching { nm(ctx).notify(ID, n) }.onSuccess { shown = true }
    }

    /**
     * Android 16+ (Live Updates): уведомление поднимается в «островок»/чип у камеры
     * со скоростью. Так же работают капсулы Honor MagicOS 10, Pixel и др. на Android 16.
     * Вызов через рефлексию — API 36 появился позже compileSdk; на старых системах ничего не делает.
     */
    private fun promote(b: Notification.Builder, chip: String) {
        if (Build.VERSION.SDK_INT < 36) return
        runCatching { Notification.Builder::class.java.getMethod("setRequestPromotedOngoing", Boolean::class.javaPrimitiveType).invoke(b, true) }
        runCatching { Notification.Builder::class.java.getMethod("setShortCriticalText", String::class.java).invoke(b, chip) }
    }

    private fun openApp(ctx: Context) = PendingIntent.getActivity(
        ctx, 0, Intent(ctx, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP),
        PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
    )

    private fun action(ctx: Context, title: String, what: String): Notification.Action {
        val pi = PendingIntent.getBroadcast(
            ctx, what.hashCode(), Intent(ctx, VpnActionReceiver::class.java).setAction(what),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )
        return Notification.Action.Builder(Icon.createWithResource(ctx, R.drawable.ic_tile), title, pi).build()
    }

    /** Туннель для «Подключиться»: последний использованный (как у плитки в шторке). */
    fun lastTunnel(): TunnelProfile? {
        val d = App.instance.repo.data.value
        return d.tunnels.firstOrNull { it.id == d.lastTunnelId } ?: d.tunnels.firstOrNull()
    }

    private fun plain(ctx: Context, title: String, text: String, button: Pair<String, String>?, ongoing: Boolean) {
        if (!canPost(ctx)) return
        ensureChannel(ctx)
        val b = Notification.Builder(ctx, CHANNEL)
            .setSmallIcon(R.drawable.ic_tile)
            .setContentTitle(title)
            .setContentText(text)
            .setOngoing(ongoing)
            .setOnlyAlertOnce(true)
            .setShowWhen(false)
            .setCategory(Notification.CATEGORY_STATUS)
            .setContentIntent(openApp(ctx))
        button?.let { (t, a) ->
            // Без разрешения на VPN подключиться из шторки нельзя — кнопка откроет приложение.
            if (a == VpnActionReceiver.CONNECT && android.net.VpnService.prepare(ctx) != null)
                b.addAction(Notification.Action.Builder(Icon.createWithResource(ctx, R.drawable.ic_tile), t, openApp(ctx)).build())
            else b.addAction(action(ctx, t, a))
        }
        lastKey = ""
        runCatching { nm(ctx).notify(ID, b.build()) }.onSuccess { shown = true }
    }

    fun connecting(ctx: Context, name: String) = plain(ctx, "Подключение…", name, null, ongoing = true)

    /**
     * VPN выключен: если включено уведомление со скоростью, оставляем его с кнопкой «Подключиться»
     * (его можно смахнуть). Иначе просто убираем.
     */
    fun down(ctx: Context, error: String? = null) {
        if (!App.instance.repo.data.value.settings.speedInStatusBar) { hide(ctx); return }
        val t = lastTunnel() ?: run { hide(ctx); return }
        plain(ctx, "VPN отключён", error ?: t.name, "Подключиться" to VpnActionReceiver.CONNECT, ongoing = false)
    }

    fun hide(ctx: Context) {
        if (!shown) return
        shown = false
        lastKey = ""
        runCatching { nm(ctx).cancel(ID) }
    }
}
