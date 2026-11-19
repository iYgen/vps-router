package app.vpsrouter.client.tunnel

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import app.vpsrouter.client.MainActivity
import app.vpsrouter.client.R
import app.vpsrouter.client.data.TunnelProfile
import app.vpsrouter.client.ui.connKind
import app.vpsrouter.client.ui.protoOf

/**
 * «Уровни защиты»: если выбранный тип соединения перестал работать (ТСПУ режет протокол,
 * замораживает поток после ~16 КБ, мобильная сеть ввела белые списки), приложение само
 * переходит на следующий тип того же сервера.
 *
 * Порядок: сначала сильные протоколы напрямую, затем они же через ретранслятор
 * (при белых списках доходит только трафик до российских адресов), в конце — простые
 * протоколы, которые ТСПУ распознаёт легче всего.
 */
object AutoSwitch {
    /** После неудачи тип не пробуем столько времени (блокировка у ТСПУ держится минуты). */
    const val COOLDOWN_MS = 10 * 60_000L

    /** Подряд неудачных проверок до переключения (проверка раз в ~10 с при сбоях). */
    const val FAILS_TO_SWITCH = 3

    private fun protoRank(proto: String): Int = when {
        proto.startsWith("VLESS+Reality") -> 0
        proto.startsWith("AmneziaWG") -> 1
        proto.startsWith("VLESS+XHTTP") -> 2
        proto.startsWith("Hysteria2") -> 3
        proto.startsWith("VLESS") -> 4
        proto.startsWith("Trojan") -> 5
        proto.startsWith("Shadowsocks") -> 6
        else -> 7 // WireGuard и прочее: сигнатура видна сразу
    }

    private fun panelFormat(t: TunnelProfile) = if (t.source == "panel") t.origin?.split(':')?.getOrNull(2)?.substringBefore('@') else null

    /** Соединение через фронт (CDN): платный запасной вход, пробуем последним. */
    fun isFront(t: TunnelProfile): Boolean = t.origin?.substringAfterLast(':')?.endsWith("@front") == true

    /** Ключ сортировки: (фронт — в самый конец; слабый протокол; через ретранслятор; ранг протокола). */
    fun level(t: TunnelProfile): Triple<Int, Int, Int> {
        val r = protoRank(protoOf(t, panelFormat(t)))
        if (isFront(t)) return Triple(9, 9, r)
        return Triple(if (r <= 2) 0 else 1, if (t.via != null) 1 else 0, r)
    }

    /**
     * Следующий тип для перехода: из того же сервера, кроме текущего и тех, что
     * недавно не работали. Сначала — те, что выше по лестнице, чем текущий; потом с начала.
     * Вход через CDN — только если он разрешён в настройках ([allowFront]).
     */
    fun next(items: List<TunnelProfile>, current: TunnelProfile, failedAt: Map<String, Long>, now: Long, allowFront: Boolean = false): TunnelProfile? {
        val cmp = compareBy<TunnelProfile>({ level(it).first }, { level(it).second }, { level(it).third })
        val ok = items.filter {
            it.id != current.id && (allowFront || !isFront(it)) && (failedAt[it.id]?.let { t -> now - t >= COOLDOWN_MS } ?: true)
        }.sortedWith(cmp)
        return ok.firstOrNull { cmp.compare(it, current) > 0 } ?: ok.firstOrNull()
    }

    fun label(t: TunnelProfile): String = connKind(t)

    private const val CHANNEL = "autoswitch"
    private const val ID = 4202

    /** Короткое уведомление о переключении (без звука). */
    fun notify(ctx: Context, text: String) {
        if (!SpeedNotifier.canPost(ctx)) return
        val nm = ctx.getSystemService(NotificationManager::class.java)
        if (nm.getNotificationChannel(CHANNEL) == null) {
            nm.createNotificationChannel(NotificationChannel(CHANNEL, "Автопереключение протокола", NotificationManager.IMPORTANCE_LOW).apply {
                description = "Сообщает, что приложение сменило тип соединения из-за блокировки"
                setSound(null, null); enableVibration(false)
            })
        }
        val pi = PendingIntent.getActivity(ctx, 0, Intent(ctx, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE)
        val n = android.app.Notification.Builder(ctx, CHANNEL)
            .setSmallIcon(R.drawable.ic_logo_mark)
            .setContentTitle("Сменил тип соединения")
            .setContentText(text)
            .setStyle(android.app.Notification.BigTextStyle().bigText(text))
            .setContentIntent(pi).setAutoCancel(true)
            .build()
        runCatching { nm.notify(ID, n) }
    }
}
