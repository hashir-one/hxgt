package `in`.hxdev.attestation

import com.android.keyattestation.verifier.*
import com.android.keyattestation.verifier.testing.*
import com.google.gson.GsonBuilder
import com.google.protobuf.ByteString
import java.io.File
import java.math.BigInteger
import java.security.MessageDigest
import java.time.LocalDate
import java.time.YearMonth
import java.util.Base64
import org.bouncycastle.asn1.x500.X500Name
import org.bouncycastle.asn1.x509.Extension
import org.bouncycastle.asn1.DEROctetString

/** Synthetic certificates only. No physical phone evidence or real private keys. */
fun main(args: Array<String>) {
    val directory = File(args.single()).apply { mkdirs() }
    val calendar = FakeCalendar(LocalDate.of(2026, 10, 25))
    val factory = KeyAttestationCertFactory(calendar)
    val pathFactory = KeyAttestationCertPathFactory(calendar, factory.rootKey, factory.root)
    val token = "t".repeat(48)
    val app = AttestationApplicationId(setOf(AttestationPackageInfo("in.hxdev.capture.debug", BigInteger.ONE)),
        setOf(ByteString.copyFrom(java.util.HexFormat.of().parseHex("a03c8ede4bafd2b22af3da3e5677de01ef5ff60411be27cbfb8b996ab8d07588"))))
    val boot = RootOfTrust(ByteString.copyFrom(ByteArray(32) { 1 }), true, VerifiedBootState.VERIFIED, ByteString.copyFrom(ByteArray(32) { 2 }))
    val hw = AuthorizationList(purposes = setOf(2.toBigInteger(), 3.toBigInteger()), algorithms = 3.toBigInteger(), keySize = 256.toBigInteger(),
        digests = setOf(4.toBigInteger()), ecCurve = BigInteger.ONE, origin = Origin.GENERATED, rootOfTrust = boot,
        osVersion = 140000.toBigInteger(), osPatchLevel = PatchLevel(YearMonth.of(2026, 10)),
        vendorPatchLevel = PatchLevel(YearMonth.of(2026, 10), 1), bootPatchLevel = PatchLevel(YearMonth.of(2026, 10), 1))
    val valid = KeyDescription(300.toBigInteger(), SecurityLevel.TRUSTED_ENVIRONMENT, 300.toBigInteger(), SecurityLevel.TRUSTED_ENVIRONMENT,
        ByteString.copyFrom(MessageDigest.getInstance("SHA-256").digest("hx-pair-v1\n$token".toByteArray(Charsets.UTF_8))),
        ByteString.EMPTY, AuthorizationList(attestationApplicationId = app), hw)
    val variants = linkedMapOf(
        "valid" to valid,
        "strongbox" to valid.copy(attestationSecurityLevel = SecurityLevel.STRONG_BOX, keyMintSecurityLevel = SecurityLevel.STRONG_BOX),
        "challenge" to valid.copy(attestationChallenge = ByteString.copyFromUtf8("wrong")),
        "hex_challenge" to valid.copy(attestationChallenge = ByteString.copyFromUtf8(java.util.HexFormat.of().formatHex(valid.attestationChallenge.toByteArray()))),
        "package" to valid.copy(softwareEnforced = AuthorizationList(attestationApplicationId = app.copy(packages = setOf(AttestationPackageInfo("in.attacker.app", BigInteger.ONE))))),
        "signer" to valid.copy(softwareEnforced = AuthorizationList(attestationApplicationId = app.copy(signatures = setOf(ByteString.copyFrom(ByteArray(32)))))),
        "shared_uid" to valid.copy(softwareEnforced = AuthorizationList(attestationApplicationId = app.copy(packages = app.packages + AttestationPackageInfo("in.attacker.app", BigInteger.ONE)))),
        "missing_app" to valid.copy(softwareEnforced = AuthorizationList()),
        "software" to valid.copy(attestationSecurityLevel = SecurityLevel.SOFTWARE, keyMintSecurityLevel = SecurityLevel.SOFTWARE),
        "software_keymint" to valid.copy(keyMintSecurityLevel = SecurityLevel.SOFTWARE),
        "unlocked" to valid.copy(hardwareEnforced = hw.copy(rootOfTrust = boot.copy(deviceLocked = false))),
        "unverified_boot" to valid.copy(hardwareEnforced = hw.copy(rootOfTrust = boot.copy(verifiedBootState = VerifiedBootState.UNVERIFIED))),
        "self_signed_boot" to valid.copy(hardwareEnforced = hw.copy(rootOfTrust = boot.copy(verifiedBootState = VerifiedBootState.SELF_SIGNED))),
        "missing_boot" to valid.copy(hardwareEnforced = hw.copy(rootOfTrust = null)),
        "imported" to valid.copy(hardwareEnforced = hw.copy(origin = Origin.IMPORTED)),
        "old_os" to valid.copy(hardwareEnforced = hw.copy(osVersion = 120000.toBigInteger())),
        "old_patch" to valid.copy(hardwareEnforced = hw.copy(osPatchLevel = PatchLevel(YearMonth.of(2025, 1)))),
        "old_vendor" to valid.copy(hardwareEnforced = hw.copy(osPatchLevel = PatchLevel(YearMonth.of(2026, 8)),
            vendorPatchLevel = PatchLevel(YearMonth.of(2026, 2), 1))),
        "release_old_vendor" to valid.copy(softwareEnforced = AuthorizationList(attestationApplicationId =
            AttestationApplicationId(setOf(AttestationPackageInfo("in.hxdev.capture", BigInteger.ONE)),
                setOf(ByteString.copyFrom(ByteArray(32) { 0xcc.toByte() })))),
            hardwareEnforced = hw.copy(vendorPatchLevel = PatchLevel(YearMonth.of(2026, 2), 1))),
        "stale_os" to valid.copy(hardwareEnforced = hw.copy(osPatchLevel = PatchLevel(YearMonth.of(2026, 3)))),
        "stale_boot" to valid.copy(hardwareEnforced = hw.copy(bootPatchLevel = PatchLevel(YearMonth.of(2026, 3), 1))),
        "bad_vendor_day" to valid.copy(hardwareEnforced = hw.copy(vendorPatchLevel = PatchLevel(YearMonth.of(2026, 2), 30))),
        "bad_vendor_format" to valid.copy(hardwareEnforced = hw.copy(vendorPatchLevel = PatchLevel(YearMonth.of(2026, 2)))),
        "future_vendor" to valid.copy(hardwareEnforced = hw.copy(vendorPatchLevel = PatchLevel(YearMonth.of(2027, 1), 1))),
        "missing_vendor_patch" to valid.copy(hardwareEnforced = hw.copy(vendorPatchLevel = null)),
        "missing_boot_patch" to valid.copy(hardwareEnforced = hw.copy(bootPatchLevel = null)),
        "bad_patch_day" to valid.copy(hardwareEnforced = hw.copy(bootPatchLevel = PatchLevel(YearMonth.of(2026, 10), 99))),
        "future_patch" to valid.copy(hardwareEnforced = hw.copy(osPatchLevel = PatchLevel(YearMonth.of(2027, 1)))),
        "software_patch" to valid.copy(hardwareEnforced = hw.copy(osPatchLevel = null), softwareEnforced = valid.softwareEnforced.copy(osPatchLevel = hw.osPatchLevel)),
        "curve" to valid.copy(hardwareEnforced = hw.copy(ecCurve = 2.toBigInteger())),
        "key_size" to valid.copy(hardwareEnforced = hw.copy(keySize = 384.toBigInteger())),
        "no_sign" to valid.copy(hardwareEnforced = hw.copy(purposes = setOf(3.toBigInteger()))),
        "digest" to valid.copy(hardwareEnforced = hw.copy(digests = setOf(2.toBigInteger())))
    )
    fun b64(bytes: ByteArray) = Base64.getEncoder().encodeToString(bytes)
    fun pem(label: String, bytes: ByteArray) = "-----BEGIN $label-----\n" + b64(bytes).chunked(64).joinToString("\n") + "\n-----END $label-----\n"
    fun save(name: String, certs: List<java.security.cert.X509Certificate>) {
        File(directory, "$name.json").writeText(GsonBuilder().setPrettyPrinting().create().toJson(mapOf(
            "certificates" to certs.map { b64(it.encoded) }, "root" to pem("CERTIFICATE", factory.root.encoded),
            "public_key" to pem("PUBLIC KEY", certs.first().publicKey.encoded), "token" to token,
            "serials" to certs.map { it.serialNumber.toString(16) }
        ) + if (name == "recovery_test") mapOf("synthetic_private_key" to pem("PRIVATE KEY", factory.leafKey.private.encoded)) else emptyMap()) + "\n")
    }
    for ((name, description) in variants) save(name, pathFactory.generateCertPath(description).certificatesWithAnchor)
    save("rkp", pathFactory.generateCertPath(valid, remotelyProvisioned = true).certificatesWithAnchor)
    val original = pathFactory.generateCertPath(valid, leafKey = factory.leafKey.public).certificatesWithAnchor
    // Synthetic test-only key for proof-of-possession + real-verifier concurrency tests.
    save("recovery_test", original)
    val appended = factory.generateLeafCert(signingKey = factory.leafKey.private, issuer = X500Name(original.first().subjectX500Principal.name), extension = valid.asExtension())
    save("appended", listOf(appended) + original)
    val extraAttestation = factory.generateAttestationCert(extraExtension = valid.asExtension())
    save("intermediate_extension", listOf(original.first(), extraAttestation, factory.factoryIntermediate, factory.root))
    val badLeaf = factory.generateLeafCert(extension = Extension(KeyDescription.OID, false, DEROctetString(byteArrayOf(0, 1, 2))))
    save("malformed_extension", listOf(badLeaf, factory.factoryAttestation, factory.factoryIntermediate, factory.root))
    val expired = factory.generateLeafCert(notAfter = calendar.lastWeek(), extension = valid.asExtension())
    save("expired", listOf(expired) + original.drop(1))
    // Valid certificate format and extension, incorrect issuer signature.
    val badSignature = factory.generateLeafCert(signingKey = factory.rootKey.private, extension = valid.asExtension())
    save("bad_signature", listOf(badSignature) + original.drop(1))
    println("Generated ${variants.size + 7} synthetic fixtures; never trust these roots outside isolated tests.")
}
