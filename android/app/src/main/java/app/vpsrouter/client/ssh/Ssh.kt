package app.vpsrouter.client.ssh

import com.jcraft.jsch.ChannelExec
import com.jcraft.jsch.JSch
import com.jcraft.jsch.KeyPair
import com.jcraft.jsch.Session
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.io.ByteArrayOutputStream
import java.io.InputStream
import java.util.Properties

data class SshTarget(
    val host: String,
    val port: Int = 22,
    val user: String = "root",
    val password: String? = null,
    val privateKey: String? = null,
    /** Отпечаток ключа хоста, запомненный при первом подключении (TOFU). */
    val knownHostKey: String? = null,
)

data class ExecResult(val exitCode: Int, val output: String) {
    val ok get() = exitCode == 0
}

class SshException(msg: String, cause: Throwable? = null) : Exception(msg, cause)

/**
 * SSH-клиент на JSch. Скрипты провижининга передаются через stdin во временные
 * файлы (как Ssh::runProvisionScript в панели) — секреты не попадают в командную строку.
 */
class SshConnection private constructor(private val session: Session, val target: SshTarget, val hostKeyFingerprint: String) : AutoCloseable {

    private val isRoot get() = target.user == "root"

    /** Обёртка для команд с правами root: sudo для не-root пользователя. */
    private fun privileged(cmd: String): Pair<String, String?> {
        if (isRoot) return cmd to null
        val quoted = "'" + cmd.replace("'", "'\\''") + "'"
        return if (target.password != null) "sudo -S -p '' bash -c $quoted" to target.password + "\n"
        else "sudo -n bash -c $quoted" to null
    }

    fun exec(
        command: String,
        stdin: String? = null,
        asRoot: Boolean = false,
        timeoutMs: Long = 30 * 60_000L,
        onLine: ((String) -> Unit)? = null,
    ): ExecResult {
        val (cmd, prefix) = if (asRoot) privileged(command) else command to null
        val ch = session.openChannel("exec") as ChannelExec
        ch.setCommand(cmd)
        ch.setErrStream(null)
        ch.setPty(false)
        val out = ByteArrayOutputStream()
        val input = (prefix ?: "") + (stdin ?: "")
        ch.setInputStream(input.byteInputStream())
        val stdout = ch.inputStream
        val stderr = ch.extInputStream
        ch.connect(15_000)
        val deadline = System.currentTimeMillis() + timeoutMs
        val lineBuf = StringBuilder()
        fun pump(s: InputStream) {
            val buf = ByteArray(8192)
            while (s.available() > 0) {
                val n = s.read(buf)
                if (n <= 0) break
                out.write(buf, 0, n)
                if (onLine != null) {
                    lineBuf.append(String(buf, 0, n, Charsets.UTF_8))
                    var idx = lineBuf.indexOf("\n")
                    while (idx >= 0) {
                        onLine(lineBuf.substring(0, idx).trimEnd('\r'))
                        lineBuf.delete(0, idx + 1)
                        idx = lineBuf.indexOf("\n")
                    }
                }
            }
        }
        try {
            while (true) {
                pump(stdout); pump(stderr)
                if (ch.isClosed) {
                    pump(stdout); pump(stderr)
                    break
                }
                if (System.currentTimeMillis() > deadline) throw SshException("Команда не завершилась за ${timeoutMs / 1000} с")
                Thread.sleep(80)
            }
            if (onLine != null && lineBuf.isNotEmpty()) onLine(lineBuf.toString())
            return ExecResult(ch.exitStatus, out.toString(Charsets.UTF_8.name()))
        } finally {
            ch.disconnect()
        }
    }

    /**
     * Запуск provision-скрипта: _lib.sh + скрипт и params.json кладутся во временный
     * каталог (0700), выполняются от root и удаляются.
     */
    fun runScript(lib: String, script: String, paramsJson: String, onLine: (String) -> Unit): ExecResult {
        val dir = exec("umask 077; mktemp -d /tmp/vpsr.XXXXXX").let {
            if (!it.ok) throw SshException("Не удалось создать временный каталог: ${it.output}")
            it.output.trim()
        }
        try {
            check(exec("umask 077; cat > $dir/run.sh", stdin = lib + "\n" + script).ok) { "Не удалось загрузить скрипт" }
            check(exec("umask 077; cat > $dir/params.json", stdin = paramsJson).ok) { "Не удалось загрузить параметры" }
            return exec("VPSR_LANG=ru bash $dir/run.sh $dir/params.json 2>&1", asRoot = true, onLine = onLine)
        } finally {
            runCatching { exec("rm -rf $dir", asRoot = true) }
        }
    }

    /** Краткая информация о сервере для карточки: ОС, ядро, аптайм, наличие awg/nft. */
    fun info(): String = exec(
        ". /etc/os-release 2>/dev/null; echo \"\${PRETTY_NAME:-$(uname -s)}\"; uname -r; uptime -p 2>/dev/null || uptime; " +
            "command -v awg >/dev/null && echo 'awg: есть' || echo 'awg: нет'; " +
            "command -v nft >/dev/null && echo 'nftables: есть' || echo 'nftables: нет'"
    ).output.trim()

    /**
     * Перевод узла на вход по ключу: создаём RSA-ключ, кладём публичный в
     * authorized_keys. Возвращает приватный ключ (PEM). Аналог Ssh::bootstrapKeyWithPassword.
     */
    fun installNewKey(comment: String = "vps-router-android"): String {
        val jsch = JSch()
        val kp = KeyPair.genKeyPair(jsch, KeyPair.RSA, 3072)
        val priv = ByteArrayOutputStream().also { kp.writePrivateKey(it) }.toString()
        val pub = ByteArrayOutputStream().also { kp.writePublicKey(it, comment) }.toString().trim()
        kp.dispose()
        val r = exec(
            "read -r k; umask 077; mkdir -p ~/.ssh && touch ~/.ssh/authorized_keys && chmod 700 ~/.ssh && chmod 600 ~/.ssh/authorized_keys && " +
                "(grep -qxF \"\$k\" ~/.ssh/authorized_keys || echo \"\$k\" >> ~/.ssh/authorized_keys)",
            stdin = pub + "\n",
        )
        if (!r.ok) throw SshException("Не удалось записать ключ в authorized_keys: ${r.output}")
        return priv
    }

    override fun close() = session.disconnect()

    companion object {
        suspend fun connect(t: SshTarget): SshConnection = withContext(Dispatchers.IO) {
            val jsch = JSch()
            if (!t.privateKey.isNullOrBlank()) {
                try {
                    jsch.addIdentity("key", t.privateKey.toByteArray(), null, null)
                } catch (e: Exception) {
                    throw SshException("Не удалось прочитать SSH-ключ: ${e.message}", e)
                }
            }
            val s = jsch.getSession(t.user, t.host, t.port)
            if (!t.password.isNullOrEmpty()) s.setPassword(t.password)
            s.setConfig(Properties().apply {
                put("StrictHostKeyChecking", "no")
                put("PreferredAuthentications", "publickey,keyboard-interactive,password")
            })
            s.setServerAliveInterval(15_000)
            try {
                s.connect(20_000)
            } catch (e: Exception) {
                throw SshException(explain(e, t), e)
            }
            val fp = s.hostKey.getFingerPrint(jsch)
            if (t.knownHostKey != null && t.knownHostKey != fp) {
                s.disconnect()
                throw SshException("Ключ хоста ${t.host} изменился (был ${t.knownHostKey}, стал $fp). Возможна подмена сервера — подключение прервано. Если сервер переустановлен, сбросьте ключ в карточке узла.")
            }
            SshConnection(s, t, fp)
        }

        private fun explain(e: Exception, t: SshTarget): String {
            val m = e.message ?: e.javaClass.simpleName
            return when {
                m.contains("Auth fail", true) || m.contains("Auth cancel", true) -> "Неверный логин, пароль или ключ для ${t.user}@${t.host}"
                m.contains("timeout", true) -> "Таймаут подключения к ${t.host}:${t.port} — сервер недоступен, SSH на другом порту или адрес блокируется"
                m.contains("refused", true) -> "Порт ${t.port} на ${t.host} закрыт (Connection refused)"
                m.contains("UnknownHost", true) || e is java.net.UnknownHostException -> "Не удаётся найти хост ${t.host}"
                else -> "SSH: $m"
            }
        }
    }
}
