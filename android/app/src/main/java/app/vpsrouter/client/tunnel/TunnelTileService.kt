package app.vpsrouter.client.tunnel

import android.content.Intent
import android.net.VpnService
import android.service.quicksettings.Tile
import android.service.quicksettings.TileService
import app.vpsrouter.client.App
import app.vpsrouter.client.MainActivity
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.launch
import org.amnezia.awg.backend.Tunnel

/** Плитка в шторке: вкл/выкл последнего использованного туннеля. */
class TunnelTileService : TileService() {
    private val scope = CoroutineScope(Dispatchers.Main)
    private var job: Job? = null

    override fun onStartListening() {
        job = scope.launch { App.instance.tunnels.status.collectLatest { render() } }
    }

    override fun onStopListening() {
        job?.cancel()
    }

    private fun render() {
        val tile = qsTile ?: return
        val app = App.instance
        val st = app.tunnels.status.value
        val up = st.state == Tunnel.State.UP
        val id = st.activeId ?: app.repo.data.value.lastTunnelId
        tile.state = if (up) Tile.STATE_ACTIVE else Tile.STATE_INACTIVE
        tile.label = app.repo.tunnel(id ?: "")?.name ?: "VPS Router"
        tile.updateTile()
    }

    override fun onClick() {
        val app = App.instance
        val st = app.tunnels.status.value
        if (st.state == Tunnel.State.UP) { scope.launch { app.tunnels.stop() }; return }
        val data = app.repo.data.value
        val t = data.tunnels.firstOrNull { it.id == data.lastTunnelId } ?: data.tunnels.firstOrNull()
        if (t == null || VpnService.prepare(this) != null) {
            // Нет туннелей или не выдано разрешение на VPN — открываем приложение.
            val i = Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            if (android.os.Build.VERSION.SDK_INT >= 34) {
                startActivityAndCollapse(android.app.PendingIntent.getActivity(this, 0, i, android.app.PendingIntent.FLAG_IMMUTABLE))
            } else {
                @Suppress("DEPRECATION")
                startActivityAndCollapse(i)
            }
            return
        }
        scope.launch { app.tunnels.start(t) }
    }
}
