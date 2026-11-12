package app.vpsrouter.client.tunnel

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import app.vpsrouter.client.App
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import org.amnezia.awg.backend.Tunnel

/** Кнопки «Подключиться» / «Отключиться» в уведомлении. */
class VpnActionReceiver : BroadcastReceiver() {
    override fun onReceive(ctx: Context, intent: Intent) {
        val app = App.instance
        val pending = goAsync()
        CoroutineScope(Dispatchers.IO).launch {
            try {
                when (intent.action) {
                    DISCONNECT -> app.tunnels.stop()
                    CONNECT -> {
                        if (app.tunnels.status.value.state == Tunnel.State.UP) return@launch
                        val t = SpeedNotifier.lastTunnel()
                        if (t == null) { SpeedNotifier.hide(ctx); return@launch }
                        SpeedNotifier.connecting(ctx, t.name)
                        app.tunnels.start(t)
                        val st = app.tunnels.status.value
                        if (st.state != Tunnel.State.UP) SpeedNotifier.down(ctx, st.error ?: "Не удалось подключиться")
                    }
                }
            } finally {
                pending.finish()
            }
        }
    }

    companion object {
        const val CONNECT = "app.vpsrouter.client.action.CONNECT"
        const val DISCONNECT = "app.vpsrouter.client.action.DISCONNECT"
    }
}
