package app.vpsrouter.client.data

import android.util.Base64
import java.security.SecureRandom
import javax.crypto.Cipher
import javax.crypto.SecretKeyFactory
import javax.crypto.spec.GCMParameterSpec
import javax.crypto.spec.PBEKeySpec
import javax.crypto.spec.SecretKeySpec

/** Формат файла: "VPSR-BACKUP-1\n" + base64(salt[16] | iv[12] | AES-256-GCM(ciphertext)). */
object Backup {
    private const val HEADER = "VPSR-BACKUP-1"
    private const val ITER = 120_000

    private fun key(password: String, salt: ByteArray): SecretKeySpec {
        val spec = PBEKeySpec(password.toCharArray(), salt, ITER, 256)
        val bytes = SecretKeyFactory.getInstance("PBKDF2WithHmacSHA256").generateSecret(spec).encoded
        return SecretKeySpec(bytes, "AES")
    }

    fun encrypt(plain: String, password: String): String {
        val rnd = SecureRandom()
        val salt = ByteArray(16).also(rnd::nextBytes)
        val iv = ByteArray(12).also(rnd::nextBytes)
        val c = Cipher.getInstance("AES/GCM/NoPadding")
        c.init(Cipher.ENCRYPT_MODE, key(password, salt), GCMParameterSpec(128, iv))
        val body = salt + iv + c.doFinal(plain.toByteArray())
        return HEADER + "\n" + Base64.encodeToString(body, Base64.NO_WRAP) + "\n"
    }

    fun decrypt(text: String, password: String): String {
        val lines = text.trim().lines()
        require(lines.firstOrNull() == HEADER) { "Это не резервная копия VPS Router" }
        val body = Base64.decode(lines.drop(1).joinToString(""), Base64.DEFAULT)
        require(body.size > 28) { "Файл копии повреждён" }
        val c = Cipher.getInstance("AES/GCM/NoPadding")
        c.init(Cipher.DECRYPT_MODE, key(password, body.copyOfRange(0, 16)), GCMParameterSpec(128, body.copyOfRange(16, 28)))
        return try { String(c.doFinal(body.copyOfRange(28, body.size))) } catch (e: Exception) { throw IllegalArgumentException("Неверный пароль или файл повреждён") }
    }
}
