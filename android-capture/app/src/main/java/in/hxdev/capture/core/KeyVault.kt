package `in`.hxdev.capture.core

import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyInfo
import android.security.keystore.KeyProperties
import java.security.KeyFactory
import java.security.KeyPairGenerator
import java.security.KeyStore
import java.security.PrivateKey
import java.security.Signature
import java.security.spec.ECGenParameterSpec
import java.util.Base64
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

class KeyVault {
    private fun store() = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
    fun exists(alias: String) = store().containsAlias(alias)
    fun generate(alias: String, token: String) {
        check(!exists(alias)) { "KEY_ALREADY_EXISTS" }
        KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_EC, "AndroidKeyStore").apply {
            initialize(KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_SIGN)
                .setAlgorithmParameterSpec(ECGenParameterSpec("secp256r1"))
                .setDigests(KeyProperties.DIGEST_SHA256)
                .setAttestationChallenge(Protocol.challenge(token)).build())
        }.generateKeyPair()
        val key = store().getKey(alias, null) as PrivateKey
        val info = KeyFactory.getInstance(key.algorithm, "AndroidKeyStore").getKeySpec(key, KeyInfo::class.java)
        @Suppress("DEPRECATION")
        check(info.isInsideSecureHardware) { "HARDWARE_KEY_REQUIRED" }
    }
    fun publicPem(alias: String): String = "-----BEGIN PUBLIC KEY-----\n" + Base64.getMimeEncoder(64, byteArrayOf(10)).encodeToString(store().getCertificate(alias).publicKey.encoded) + "\n-----END PUBLIC KEY-----"
    fun chain(alias: String): List<String> = store().getCertificateChain(alias).map { Base64.getEncoder().encodeToString(it.encoded) }
    fun sign(alias: String, bytes: ByteArray): String = Signature.getInstance("SHA256withECDSA").run {
        initSign(store().getKey(alias, null) as PrivateKey); update(bytes); Base64.getEncoder().encodeToString(sign())
    }
    @Synchronized private fun encryptionKey(create: Boolean = true): SecretKey {
        val alias = "hx-outbox-aes-v1"
        (store().getKey(alias, null) as? SecretKey)?.let { return it }
        check(create) { "ENCRYPTION_KEY_MISSING" }
        return KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore").apply {
            init(KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256).build())
        }.generateKey()
    }
    fun encrypt(plain: String, context: String): String = Cipher.getInstance("AES/GCM/NoPadding").run {
        init(Cipher.ENCRYPT_MODE, encryptionKey()); updateAAD(context.toByteArray(Charsets.UTF_8))
        Base64.getEncoder().encodeToString(iv + doFinal(plain.toByteArray(Charsets.UTF_8)))
    }
    fun decrypt(encoded: String, context: String): String = Cipher.getInstance("AES/GCM/NoPadding").run {
        val bytes = Base64.getDecoder().decode(encoded)
        init(Cipher.DECRYPT_MODE, encryptionKey(false), GCMParameterSpec(128, bytes.copyOfRange(0, 12)))
        updateAAD(context.toByteArray(Charsets.UTF_8))
        String(doFinal(bytes.copyOfRange(12, bytes.size)), Charsets.UTF_8)
    }
}
