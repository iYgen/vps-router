package app.vpsrouter.client

import android.app.Application
import app.vpsrouter.client.data.Repository
import app.vpsrouter.client.data.SecureStore
import app.vpsrouter.client.local.LocalOps
import app.vpsrouter.client.panel.PanelSession
import app.vpsrouter.client.tunnel.TunnelManager

open class App : Application() {
    lateinit var repo: Repository
        private set
    lateinit var tunnels: TunnelManager
        private set
    lateinit var local: LocalOps
        private set
    lateinit var panel: PanelSession
        private set
    lateinit var updater: app.vpsrouter.client.update.AppUpdater
        private set

    override fun onCreate() {
        super.onCreate()
        instance = this
        repo = Repository(createStore())
        tunnels = TunnelManager(this, repo)
        local = LocalOps(this, repo)
        panel = PanelSession(repo)
        updater = app.vpsrouter.client.update.AppUpdater(this)
    }

    /** Хранилище секретов; в скриншот-тестах (Robolectric нет AndroidKeyStore) подменяется. */
    protected open fun createStore(): SecureStore = SecureStore(this)

    companion object {
        lateinit var instance: App
            private set
    }
}
