package app.vpsrouter.client.data

/**
 * Данные SSH, найденные в тексте: письмо хостера, скриншот панели, фото экрана.
 * Текст после распознавания (OCR) бывает «грязным»: кириллица читается латиницей,
 * подпись и значение разъезжаются по строкам — разбор это учитывает.
 */
data class SshCreds(
    val host: String? = null,
    val port: String? = null,
    val user: String? = null,
    val password: String? = null,
) {
    val isEmpty get() = host == null && port == null && user == null && password == null

    companion object {
        private val ipv4 = Regex("""(?<![\d.])((?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(?:\.(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3})(?![\d.])""")

        // Похожие буквы → кириллица, чтобы «Napoль», «Пapoль», «Логин» из OCR узнавались.
        private fun norm(s: String): String = s.lowercase()
            .replace('a', 'а').replace('o', 'о').replace('p', 'р').replace('e', 'е').replace('c', 'с')
            .replace('n', 'п').replace('b', 'ь').replace('6', 'ь').replace('x', 'х').replace('y', 'у')
            .replace('k', 'к').replace('m', 'м').replace('t', 'т').replace('h', 'н').replace('l', 'л')

        private fun label(vararg w: String) = Regex("""(?<!\p{L})(""" + w.map(::norm).sortedByDescending { it.length }.joinToString("|") { Regex.escape(it) } + """)(?!\p{L})""")
        private val passLabel = label("password", "passwd", "pass", "pwd", "пароль", "root password", "пароль root")
        private val userLabel = label("login", "username", "user", "логин", "пользователь", "имя пользователя")
        private val hostLabel = label("ip-адрес", "ip адрес", "ip address", "ip", "host", "hostname", "server", "адрес", "хост", "сервер", "адрес сервера")
        private val portLabel = label("ssh port", "port", "порт", "порт ssh")

        /** Значение после подписи: «Пароль: abc», «Пароль root — abc» или на следующей строке. */
        private fun labeled(lines: List<String>, label: Regex): String? {
            for ((i, line) in lines.withIndex()) {
                val n = norm(line)
                val m = label.find(n) ?: continue
                // Текст после подписи в оригинальной строке (позиции совпадают — norm не меняет длину).
                val rest = line.substring(m.range.last + 1)
                val afterSep = Regex("""^[^:=\-–—]{0,24}[:=\-–—]\s*(\S+)""").find(rest)?.groupValues?.get(1)
                    ?: Regex("""^\s+(\S+)\s*$""").find(rest)?.groupValues?.get(1)?.takeIf { rest.trim().split(Regex("\\s+")).size == 1 }
                if (!afterSep.isNullOrBlank()) return afterSep.trim().trimEnd('.', ',', ';')
                if (rest.isBlank() || Regex("""^[^\p{L}\d]*$""").matches(rest)) {
                    lines.drop(i + 1).firstOrNull { it.isNotBlank() }?.trim()?.takeIf { !it.contains(' ') }?.let { return it }
                }
            }
            return null
        }

        private fun looksLikePassword(s: String) = s.length in 8..64 && !s.contains(' ') &&
            s.any(Char::isDigit) && s.any(Char::isLetter) && !ipv4.containsMatchIn(s) && !s.contains("://") && !s.contains('@')

        fun parse(text: String): SshCreds {
            val lines = text.lines().map { it.trim() }.filter { it.isNotEmpty() }
            // «ssh root@1.2.3.4 -p 2222» — самый точный вариант.
            val ssh = Regex("""(?i)ssh\s+(?:-p\s*(\d{1,5})\s+)?([\w.\-]+)@([\w.\-]+)(?:\s+-p\s*(\d{1,5}))?""").find(text)
            val ips = ipv4.findAll(text).map { it.groupValues[1] }
                .filterNot { it.startsWith("0.") || it.startsWith("127.") || it.startsWith("255.") || it.endsWith(".0") || it.endsWith(".255") }
                .toList()
            val hostByLabel = labeled(lines, hostLabel)?.let { v -> ipv4.find(v)?.groupValues?.get(1) ?: v.takeIf { it.contains('.') && !it.contains('@') } }
            val host = ssh?.groupValues?.get(3) ?: hostByLabel?.takeIf { it in ips || ips.isEmpty() } ?: ips.firstOrNull() ?: hostByLabel
            val port = ssh?.let { it.groupValues[1].ifEmpty { it.groupValues[4] }.ifEmpty { null } }
                ?: host?.let { h -> Regex(Regex.escape(h) + """:(\d{2,5})\b""").find(text)?.groupValues?.get(1) }
                ?: labeled(lines, portLabel)?.filter(Char::isDigit)?.takeIf { it.isNotEmpty() && it.toInt() in 1..65535 }
            val user = ssh?.groupValues?.get(2) ?: labeled(lines, userLabel)?.takeIf { Regex("""^[a-z_][a-z0-9_.\-]{0,31}$""").matches(it) }
            val password = labeled(lines, passLabel)
                ?: lines.flatMap { it.split(Regex("\\s+")) }.firstOrNull(::looksLikePassword)
            return SshCreds(host, port, user, password)
        }
    }
}
