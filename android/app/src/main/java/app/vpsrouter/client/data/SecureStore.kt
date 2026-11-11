package app.vpsrouter.client.data

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.io.File
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Файлы приложения, зашифрованные ключом из Android Keystore (AES-256-GCM).
 * Здесь лежат приватные ключи туннелей, SSH-ключи/пароли серверов и логин панели —
 * ключ шифрования не покидает аппаратное хранилище и не попадает в бэкап.
 */
open class SecureStore(context: Context) {
    private val dir = File(context.filesDir, "secure").apply { mkdirs() }

    private val key: SecretKey by lazy {
        val ks = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (ks.getKey(ALIAS, null) as? SecretKey) ?: KeyGenerator
            .getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
            .apply {
                init(
                    KeyGenParameterSpec.Builder(ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                        .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                        .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                        .setKeySize(256)
                        .build()
                )
            }
            .generateKey()
    }

    @Synchronized
    open fun write(name: String, plain: String) {
        val cipher = Cipher.getInstance(TRANSFORM)
        cipher.init(Cipher.ENCRYPT_MODE, key)
        val enc = cipher.doFinal(plain.toByteArray(Charsets.UTF_8))
        val payload = Base64.encodeToString(cipher.iv, Base64.NO_WRAP) + ":" + Base64.encodeToString(enc, Base64.NO_WRAP)
        val tmp = File(dir, "$name.tmp")
        tmp.writeText(payload)
        tmp.renameTo(File(dir, name))
    }

    @Synchronized
    open fun read(name: String): String? {
        val f = File(dir, name)
        if (!f.exists()) return null
        return try {
            val (ivB64, dataB64) = f.readText().split(":", limit = 2)
            val cipher = Cipher.getInstance(TRANSFORM)
            cipher.init(Cipher.DECRYPT_MODE, key, GCMParameterSpec(128, Base64.decode(ivB64, Base64.NO_WRAP)))
            String(cipher.doFinal(Base64.decode(dataB64, Base64.NO_WRAP)), Charsets.UTF_8)
        } catch (e: Exception) {
            null
        }
    }

    private companion object {
        const val ALIAS = "vpsrouter-store"
        const val TRANSFORM = "AES/GCM/NoPadding"
    }
}
