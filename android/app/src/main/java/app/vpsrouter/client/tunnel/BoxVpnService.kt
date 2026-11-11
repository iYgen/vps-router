package app.vpsrouter.client.tunnel

import android.content.Context
import android.content.Intent
import android.net.ConnectivityManager
import android.net.LinkProperties
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import android.net.VpnService
import android.os.Build
import android.os.Handler
import android.os.HandlerThread
import android.os.ParcelFileDescriptor
import app.vpsrouter.client.data.AppLog
import io.nekohasekai.libbox.BridgeOptions
import io.nekohasekai.libbox.BridgeSession
import io.nekohasekai.libbox.CommandServer
import io.nekohasekai.libbox.CommandServerHandler
import io.nekohasekai.libbox.ConnectionOwner
import io.nekohasekai.libbox.InterfaceUpdateListener
import io.nekohasekai.libbox.Libbox
import io.nekohasekai.libbox.LocalDNSTransport
import io.nekohasekai.libbox.NeighborUpdateListener
import io.nekohasekai.libbox.NetworkInterfaceIterator
import io.nekohasekai.libbox.Notification
import io.nekohasekai.libbox.OverrideOptions
import io.nekohasekai.libbox.PlatformInterface
import io.nekohasekai.libbox.PlatformUser
import io.nekohasekai.libbox.RoutePrefixIterator
import io.nekohasekai.libbox.SetupOptions
import io.nekohasekai.libbox.ShellSession
import io.nekohasekai.libbox.StringIterator
import io.nekohasekai.libbox.SystemProxyStatus
import io.nekohasekai.libbox.TunOptions
import io.nekohasekai.libbox.WIFIState
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.withTimeout
import java.io.File
import io.nekohasekai.libbox.NetworkInterface as BoxInterface

/**
 * VPN-служба ядра sing-box (libbox): VLESS+Reality, Hysteria2, Trojan, Shadowsocks.
 * AmneziaWG/WireGuard по-прежнему работают через GoBackend — одновременно активно одно ядро.
 *
 * Сокеты ядра к серверу защищаются protect() (идут мимо туннеля), остальной трафик
 * телефона попадает в TUN и уходит на РФ-сервер, где действуют маршруты панели.
 */
class BoxVpnService : VpnService(), PlatformInterface, CommandServerHandler {

    private var server: CommandServer? = null
    private var tunFd: ParcelFileDescriptor? = null
    private var monitorThread: HandlerThread? = null
    private var netCallback: ConnectivityManager.NetworkCallback? = null

    /** Вызывается из TunnelManager, когда система отозвала VPN или ядро само остановилось. */
    var onStopped: ((String?) -> Unit)? = null

    override fun onCreate() {
        super.onCreate()
        instance = this
        pending?.complete(this)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int = START_NOT_STICKY

    override fun onDestroy() {
        closeAll()
        if (instance === this) instance = null
        super.onDestroy()
    }

    /** Система отключила VPN (другой VPN, «Отключить» в настройках). */
    override fun onRevoke() {
        AppLog.i("Система отключила VPN (sing-box)")
        closeAll()
        onStopped?.invoke(null)
        stopSelf()
    }

    fun startBox(config: String) {
        setup(this)
        closeAll()
        val s = CommandServer(this, this)
        server = s
        s.start()
        s.startOrReloadService(config, OverrideOptions())
    }

    fun stopBox() {
        closeAll()
        stopSelf()
    }

    private fun closeAll() {
        server?.let { s ->
            runCatching { s.closeService() }
            runCatching { s.close() }
        }
        server = null
        runCatching { tunFd?.close() }
        tunFd = null
    }

    // ---- PlatformInterface -------------------------------------------------------------

    override fun usePlatformAutoDetectInterfaceControl() = true

    override fun autoDetectInterfaceControl(fd: Int) {
        if (!protect(fd)) throw Exception("protect($fd) не удался")
    }

    override fun openTun(o: TunOptions): Int {
        if (prepare(this) != null) throw Exception("Нет разрешения на VPN — подтвердите запрос системы")
        val b = Builder().setSession("VPS Router").setMtu(o.mtu)
        if (Build.VERSION.SDK_INT >= 29) b.setMetered(false)
        var v4 = false; var v6 = false
        o.inet4Address.forEach { b.addAddress(it.address(), it.prefix()); v4 = true }
        o.inet6Address.forEach { b.addAddress(it.address(), it.prefix()); v6 = true }
        if (o.autoRoute) {
            runCatching { o.dnsServerAddress.forEach { b.addDnsServer(it) } }
            var r4 = false; var r6 = false
            if (v4) o.inet4RouteRange.forEach { b.addRoute(it.address(), it.prefix()); r4 = true }
            if (v6) o.inet6RouteRange.forEach { b.addRoute(it.address(), it.prefix()); r6 = true }
            if (v4 && !r4) b.addRoute("0.0.0.0", 0)
            if (v6 && !r6) b.addRoute("::", 0)
            val inc = o.includePackage.toList()
            val exc = o.excludePackage.toList()
            if (inc.isNotEmpty()) inc.forEach { runCatching { b.addAllowedApplication(it) } }
            else exc.forEach { runCatching { b.addDisallowedApplication(it) } }
        }
        val pfd = b.establish() ?: throw Exception("Не удалось создать TUN-интерфейс (возможно, активен другой VPN)")
        tunFd = pfd
        return pfd.fd
    }

    override fun getInterfaces(): NetworkInterfaceIterator {
        val cm = getSystemService(ConnectivityManager::class.java)
        val list = mutableListOf<BoxInterface>()
        @Suppress("DEPRECATION")
        for (n in cm.allNetworks) {
            val lp = cm.getLinkProperties(n) ?: continue
            val caps = cm.getNetworkCapabilities(n) ?: continue
            if (caps.hasTransport(NetworkCapabilities.TRANSPORT_VPN)) continue
            val name = lp.interfaceName ?: continue
            val ni = runCatching { java.net.NetworkInterface.getByName(name) }.getOrNull() ?: continue
            list += BoxInterface().apply {
                setName(name)
                setIndex(ni.index)
                setMTU(runCatching { ni.mtu }.getOrDefault(1500))
                setAddresses(strings(lp.linkAddresses.map { "${it.address.hostAddress?.substringBefore('%')}/${it.prefixLength}" }))
                setDNSServer(strings(lp.dnsServers.mapNotNull { it.hostAddress }))
                setGateway(strings(lp.routes.filter { it.isDefaultRoute && it.gateway != null }.mapNotNull { it.gateway?.hostAddress?.substringBefore('%') }))
                setType(when {
                    caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> Libbox.InterfaceTypeWIFI
                    caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> Libbox.InterfaceTypeCellular
                    caps.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET) -> Libbox.InterfaceTypeEthernet
                    else -> Libbox.InterfaceTypeOther
                })
                setFlags(flagsOf(ni))
                setMetered(!caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_NOT_METERED))
            }
        }
        val it = list.iterator()
        return object : NetworkInterfaceIterator {
            override fun hasNext() = it.hasNext()
            override fun next() = it.next()
        }
    }

    override fun startDefaultInterfaceMonitor(listener: InterfaceUpdateListener) {
        val cm = getSystemService(ConnectivityManager::class.java)
        val thread = HandlerThread("box-netmon").also { it.start() }
        monitorThread = thread
        val handler = Handler(thread.looper)
        fun report(n: Network?) {
            val lp: LinkProperties? = n?.let { cm.getLinkProperties(it) }
            val name = lp?.interfaceName
            val idx = name?.let { runCatching { java.net.NetworkInterface.getByName(it)?.index }.getOrNull() }
            if (name == null || idx == null) { listener.updateDefaultInterface("", -1, false, false); return }
            val caps = cm.getNetworkCapabilities(n)
            val metered = caps?.hasCapability(NetworkCapabilities.NET_CAPABILITY_NOT_METERED) == false
            if (Build.VERSION.SDK_INT >= 22) runCatching { setUnderlyingNetworks(arrayOf(n)) }
            listener.updateDefaultInterface(name, idx, metered, false)
        }
        val cb = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) = report(network)
            override fun onLinkPropertiesChanged(network: Network, lp: LinkProperties) = report(network)
            override fun onLost(network: Network) = report(null)
        }
        netCallback = cb
        // Запрос по умолчанию исключает VPN-сети — получаем настоящую сеть телефона (Wi-Fi / мобильная).
        val req = NetworkRequest.Builder()
            .addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
            .addCapability(NetworkCapabilities.NET_CAPABILITY_NOT_RESTRICTED)
            .build()
        // Начальное состояние — сразу, пока туннель ещё не поднят и activeNetwork — настоящая сеть.
        cm.activeNetwork?.let { n ->
            if (cm.getNetworkCapabilities(n)?.hasTransport(NetworkCapabilities.TRANSPORT_VPN) == false) report(n)
        }
        if (Build.VERSION.SDK_INT >= 31) cm.registerBestMatchingNetworkCallback(req, cb, handler)
        else cm.requestNetwork(req, cb, handler)
    }

    override fun closeDefaultInterfaceMonitor(listener: InterfaceUpdateListener) {
        val cm = getSystemService(ConnectivityManager::class.java)
        netCallback?.let { runCatching { cm.unregisterNetworkCallback(it) } }
        netCallback = null
        monitorThread?.quitSafely()
        monitorThread = null
    }

    override fun includeAllNetworks() = false
    override fun underNetworkExtension() = false
    override fun clearDNSCache() {}
    override fun registerMyInterface(name: String?) {}
    override fun readWIFIState(): WIFIState = WIFIState("", "")
    override fun localDNSTransport(): LocalDNSTransport? = null
    override fun findConnectionOwner(p0: Int, p1: String?, p2: Int, p3: String?, p4: Int): ConnectionOwner =
        throw Exception("не поддерживается")
    override fun sendNotification(n: Notification?) {}
    override fun cancelNotification(id: String?, type: Int) {}
    override fun startNeighborMonitor(l: NeighborUpdateListener?) {}
    override fun closeNeighborMonitor(l: NeighborUpdateListener?) {}
    override fun usePlatformShell() = false
    override fun checkPlatformShell() = throw Exception("не поддерживается")
    override fun openShellSession(u: PlatformUser?, c: String?, e: StringIterator?, t: String?, r: Int, cols: Int): ShellSession =
        throw Exception("не поддерживается")
    override fun lookupUser(name: String?): PlatformUser = throw Exception("не поддерживается")
    override fun lookupSFTPServer(): String = throw Exception("не поддерживается")
    override fun readSystemSSHHostKey(): String = throw Exception("не поддерживается")
    override fun tailscaleHostname() = ""
    override fun usePlatformBridge() = false
    override fun createBridge(o: BridgeOptions?): BridgeSession = throw Exception("не поддерживается")

    // ---- CommandServerHandler ----------------------------------------------------------

    override fun serviceStop() {
        AppLog.i("Ядро sing-box остановилось")
        onStopped?.invoke(null)
    }
    override fun serviceReload() {}
    override fun getSystemProxyStatus() = SystemProxyStatus()
    override fun setSystemProxyEnabled(enabled: Boolean) {}
    override fun connectSSHAgent(): Int = throw Exception("не поддерживается")
    override fun triggerNativeCrash() {}
    override fun writeDebugMessage(msg: String?) { msg?.let { AppLog.i("sing-box: $it") } }

    companion object {
        @Volatile var instance: BoxVpnService? = null
            private set
        private var pending: CompletableDeferred<BoxVpnService>? = null
        private var setupDone = false

        @Synchronized
        fun setup(ctx: Context) {
            if (setupDone) return
            val base = File(ctx.filesDir, "box").apply { mkdirs() }
            val tmp = File(ctx.cacheDir, "box").apply { mkdirs() }
            Libbox.setup(SetupOptions().apply {
                basePath = base.path
                workingPath = base.path
                tempPath = tmp.path
                // Обход ошибки стека Go на части версий Android (как в официальном клиенте).
                fixAndroidStack = Build.VERSION.SDK_INT in 24..25 || Build.VERSION.SDK_INT >= 28
                logMaxLines = 300
            })
            setupDone = true
        }

        /** Проверка конфига без запуска (ошибки выводятся пользователю). */
        fun check(ctx: Context, config: String) { setup(ctx); Libbox.checkConfig(config) }

        /** Запустить службу и дождаться её создания. */
        suspend fun obtain(ctx: Context): BoxVpnService {
            instance?.let { return it }
            val d = CompletableDeferred<BoxVpnService>()
            pending = d
            ctx.startService(Intent(ctx, BoxVpnService::class.java))
            return try { withTimeout(5000) { d.await() } } finally { pending = null }
        }

        fun version(): String = runCatching { Libbox.version() }.getOrDefault("?")

        private fun flagsOf(ni: java.net.NetworkInterface): Int {
            var f = 0
            runCatching {
                if (ni.isUp) f = f or 0x1 or 0x40 // IFF_UP | IFF_RUNNING
                if (ni.isLoopback) f = f or 0x8
                if (ni.isPointToPoint) f = f or 0x10
                if (ni.supportsMulticast()) f = f or 0x1000
                if (!ni.isLoopback && !ni.isPointToPoint) f = f or 0x2 // IFF_BROADCAST
            }
            return f
        }

        private fun strings(l: List<String>): StringIterator {
            val it = l.iterator()
            return object : StringIterator {
                override fun hasNext() = it.hasNext()
                override fun len() = l.size
                override fun next() = it.next()
            }
        }

        private inline fun RoutePrefixIterator.forEach(f: (io.nekohasekai.libbox.RoutePrefix) -> Unit) { while (hasNext()) f(next()) }
        private inline fun StringIterator.forEach(f: (String) -> Unit) { while (hasNext()) f(next()) }
        private fun StringIterator?.toList(): List<String> { val r = mutableListOf<String>(); if (this != null) while (hasNext()) r += next(); return r }
    }
}
