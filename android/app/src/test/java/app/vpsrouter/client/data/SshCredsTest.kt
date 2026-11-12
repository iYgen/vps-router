package app.vpsrouter.client.data

import org.junit.Assert.assertEquals
import org.junit.Test

class SshCredsTest {
    @Test fun hosterEmailRu() {
        val c = SshCreds.parse(
            """
            Ваш сервер готов!
            IP-адрес: 203.0.113.45
            Логин: root
            Пароль: Xk9#mP2vLq
            Маска 255.255.255.0
            """.trimIndent()
        )
        assertEquals("203.0.113.45", c.host); assertEquals("root", c.user); assertEquals("Xk9#mP2vLq", c.password)
    }

    @Test fun ocrLatinizedLabelsOnNextLine() {
        // Латинская модель OCR читает «Пароль» как «Napoль», а значение — отдельной строкой.
        val c = SshCreds.parse("IP\n198.51.100.7\nNapoль\nAb3dEf9hKk\nPort: 2222")
        assertEquals("198.51.100.7", c.host); assertEquals("Ab3dEf9hKk", c.password); assertEquals("2222", c.port)
    }

    @Test fun sshCommand() {
        val c = SshCreds.parse("Подключение: ssh admin@192.0.2.10 -p 2200\nRoot password: s3cretPass1")
        assertEquals("192.0.2.10", c.host); assertEquals("admin", c.user); assertEquals("2200", c.port); assertEquals("s3cretPass1", c.password)
    }

    @Test fun englishPanel() {
        val c = SshCreds.parse("Server details\nMain IP 45.67.89.10\nUsername root\nPassword: q7Wv2NcR8zTy")
        assertEquals("45.67.89.10", c.host); assertEquals("root", c.user); assertEquals("q7Wv2NcR8zTy", c.password)
    }

    @Test fun ipWithPort() {
        val c = SshCreds.parse("Адрес 203.0.113.9:2022 пароль — Ty7uI8oP0a")
        assertEquals("203.0.113.9", c.host); assertEquals("2022", c.port); assertEquals("Ty7uI8oP0a", c.password)
    }
}
