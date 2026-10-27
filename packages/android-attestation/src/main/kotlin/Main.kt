package `in`.hxdev.attestation

import com.android.keyattestation.verifier.*
import com.android.keyattestation.verifier.challengecheckers.ChallengeMatcher
import com.google.gson.Gson
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.google.common.collect.ImmutableList
import org.bouncycastle.asn1.ASN1OctetString
import java.io.ByteArrayInputStream
import java.math.BigInteger
import java.security.AlgorithmParameters
import java.security.KeyFactory
import java.security.MessageDigest
import java.security.cert.CertificateFactory
import java.security.cert.TrustAnchor
import java.security.cert.X509Certificate
import java.security.interfaces.ECPublicKey
import java.security.spec.ECGenParameterSpec
import java.security.spec.ECParameterSpec
import java.security.spec.X509EncodedKeySpec
import java.time.Instant
import java.time.LocalDate
import java.time.YearMonth
import java.time.ZoneOffset
import java.util.Base64
import java.util.Date

private class Rejected(val code: String, val details: Map<String, Any> = emptyMap()) : RuntimeException()
private fun requirePolicy(value: Boolean, code: String) { if (!value) throw Rejected(code) }
private fun JsonObject.string(name: String) = get(name).asString
private fun certificate(value: String): X509Certificate {
    val bytes = if (value.startsWith("-----BEGIN CERTIFICATE-----")) value.toByteArray()
        else Base64.getDecoder().decode(value)
    require(bytes.size <= 16384)
    val stream = ByteArrayInputStream(bytes)
    val cert = CertificateFactory.getInstance("X.509").generateCertificate(stream) as X509Certificate
    require(stream.available() == 0)
    return cert
}
private fun patch(field: String, value: PatchLevel?, minimum: Int, dayRequired: Boolean, today: LocalDate, maxMonths: Long): Int {
    fun reject(reason: String): Nothing {
        val details = mutableMapOf<String, Any>("field" to field, "reason" to reason,
            "minimum" to minimum, "max_age_months" to maxMonths, "server_date" to today.toString())
        value?.toString()?.takeIf { it.matches(Regex("[0-9]{6}|[0-9]{8}")) }?.let { details["actual"] = it }
        throw Rejected("PATCH_POLICY", details)
    }
    if (value == null) reject("MISSING")
    value!!
    val raw = value.toString().toInt()
    if ((value.version != null) != dayRequired) reject("INVALID_FORMAT")
    if (dayRequired) {
        val date = try { value.yearMonth.atDay(value.version!!) } catch (_: Exception) { reject("INVALID_DATE") }
        if (date.isAfter(today)) reject("FUTURE")
    }
    if (value.yearMonth > YearMonth.from(today)) reject("FUTURE")
    if (raw < minimum) reject("BELOW_MINIMUM")
    if (value.yearMonth < YearMonth.from(today).minusMonths(maxMonths)) reject("TOO_OLD")
    return raw
}

private fun verify(input: JsonObject): Map<String, Any> {
    val chain = input.getAsJsonArray("certificates").map { certificate(it.asString) }
    requirePolicy(chain.size in 3..8, "CHAIN_INVALID")
    val roots = input.getAsJsonArray("roots").map { certificate(it.asString) }
    requirePolicy(roots.isNotEmpty() && roots.any { it.encoded.contentEquals(chain.last().encoded) }, "ROOT_UNTRUSTED")
    val revoked = input.getAsJsonArray("revoked_serials").map { BigInteger(it.asString, 16).toString(16) }.toSet()
    requirePolicy(chain.none { it.serialNumber.toString(16) in revoked }, "CERTIFICATE_REVOKED")
    // Only a directly attested target is supported. ATTEST_KEY delegation fails closed.
    // This also prevents accepting an attacker-appended leaf or a second extension.
    requirePolicy(chain.mapIndexedNotNull { i, c -> if (c.getExtensionValue(KeyDescription.OID.id) != null) i else null } == listOf(0), "EXTENSION_POSITION")
    val provisioning = "1.3.6.1.4.1.11129.2.1.30"
    requirePolicy(chain.mapIndexedNotNull { i, c -> if (c.getExtensionValue(provisioning) != null) i else null }.all { it == 1 }, "EXTENSION_POSITION")
    val now = Instant.ofEpochSecond(input.get("now").asLong)
    try { chain.forEach { it.checkValidity(Date.from(now)) } }
    catch (_: Exception) { throw Rejected("CERTIFICATE_TIME") }
    val challenge = Base64.getDecoder().decode(input.string("challenge"))
    require(challenge.size == 32)
    // Chain/extension placement and signatures are verified by Android's maintained verifier
    // BEFORE we use KeyDescription below. No library logging hook receives private identifiers.
    val result = Verifier(
        trustAnchorsSource = { roots.map { TrustAnchor(it, null) }.toSet() },
        revokedSerialsSource = { revoked },
        instantSource = InstantSource { now },
        constraintConfig = ConstraintConfig(attestationApplicationId = AttestationApplicationIdConstraint.STRICT,
            additionalConstraints = ImmutableList.of(TagOrderConstraint.STRICT)),
    ).verify(chain, ChallengeMatcher(challenge))
    when (result) {
        is VerificationResult.Success -> Unit
        is VerificationResult.ChallengeMismatch -> throw Rejected("CHALLENGE_MISMATCH")
        is VerificationResult.PathValidationFailure -> throw Rejected("CHAIN_INVALID")
        is VerificationResult.ExtensionParsingFailure -> throw Rejected("EXTENSION_INVALID")
        is VerificationResult.ChainParsingFailure -> throw Rejected("CHAIN_INVALID")
        else -> throw Rejected("HARDWARE_POLICY")
    }
    val description = KeyDescription.parseFrom(chain.first()) ?: throw Rejected("EXTENSION_INVALID")
    // Reject ambiguous encodings (including duplicate tags) that a permissive parser
    // could normalize. ASN.1 decoding/encoding remains entirely in the maintained library.
    requirePolicy(description.encodeToAsn1().contentEquals(ASN1OctetString.getInstance(
        chain.first().getExtensionValue(KeyDescription.OID.id)).octets), "EXTENSION_INVALID")
    val hw = description.hardwareEnforced
    val sw = description.softwareEnforced
    val hardwareLevels = setOf(SecurityLevel.TRUSTED_ENVIRONMENT, SecurityLevel.STRONG_BOX)
    requirePolicy(description.attestationSecurityLevel in hardwareLevels && description.keyMintSecurityLevel in hardwareLevels &&
        hw.origin == Origin.GENERATED, "HARDWARE_POLICY")
    val app = sw.attestationApplicationId
    requirePolicy(app != null && app.packages.size == 1 && app.packages.single().name == input.string("package") &&
        app.signatures.size == 1 && MessageDigest.isEqual(app.signatures.single().toByteArray(),
            java.util.HexFormat.of().parseHex(input.string("signer_sha256"))) && hw.attestationApplicationId == null, "APP_IDENTITY")
    val publicKey = KeyFactory.getInstance("EC").generatePublic(X509EncodedKeySpec(Base64.getDecoder().decode(input.string("public_key"))))
    requirePolicy(MessageDigest.isEqual(chain.first().publicKey.encoded, publicKey.encoded), "PUBLIC_KEY_MISMATCH")
    val ec = publicKey as? ECPublicKey ?: throw Rejected("KEY_POLICY")
    val p256 = AlgorithmParameters.getInstance("EC").apply { init(ECGenParameterSpec("secp256r1")) }.getParameterSpec(ECParameterSpec::class.java)
    requirePolicy(ec.params.curve == p256.curve && ec.params.generator == p256.generator && ec.params.order == p256.order && ec.params.cofactor == p256.cofactor &&
        hw.algorithms == BigInteger.valueOf(3) && hw.keySize == BigInteger.valueOf(256) && hw.ecCurve == BigInteger.ONE &&
        hw.purposes?.contains(BigInteger.valueOf(2)) == true && hw.purposes!!.all { it in setOf(2.toBigInteger(), 3.toBigInteger()) } &&
        hw.digests == setOf(BigInteger.valueOf(4)), "KEY_POLICY")
    requirePolicy(sw.algorithms == null && sw.keySize == null && sw.ecCurve == null && sw.purposes == null &&
        sw.digests == null && sw.origin == null && sw.rootOfTrust == null && sw.osVersion == null &&
        sw.osPatchLevel == null && sw.vendorPatchLevel == null && sw.bootPatchLevel == null, "HARDWARE_POLICY")
    val boot = hw.rootOfTrust
    requirePolicy(boot != null && boot.deviceLocked && boot.verifiedBootState == VerifiedBootState.VERIFIED &&
        !boot.verifiedBootKey.isEmpty && boot.verifiedBootHash?.isEmpty == false, "BOOT_POLICY")
    requirePolicy(hw.osVersion != null && hw.osVersion!! >= input.get("min_os_version").asBigInteger, "OS_POLICY")
    val today = now.atZone(ZoneOffset.UTC).toLocalDate()
    val maxMonths = input.get("max_patch_age_months").asLong
    require(maxMonths in 0..12)
    // Supplied only by the server after its staging environment and SPKI pin checks.
    val vendorMaxMonths = input.get("vendor_max_patch_age_months")?.asLong ?: maxMonths
    require(vendorMaxMonths in 0..12)
    val osPatch = patch("os_patch", hw.osPatchLevel, input.get("min_os_patch").asInt, false, today, maxMonths)
    val vendorPatch = patch("vendor_patch", hw.vendorPatchLevel, input.get("min_vendor_patch").asInt, true, today, vendorMaxMonths)
    val bootPatch = patch("boot_patch", hw.bootPatchLevel, input.get("min_boot_patch").asInt, true, today, maxMonths)
    return mapOf("verified" to true, "code" to "VERIFIED", "security_level" to description.keyMintSecurityLevel.name,
        "os_patch" to osPatch, "vendor_patch" to vendorPatch, "boot_patch" to bootPatch)
}

fun main() {
    val result = try {
        val bytes = System.`in`.readNBytes(2_000_001)
        require(bytes.size <= 2_000_000)
        verify(JsonParser.parseString(bytes.toString(Charsets.UTF_8)).asJsonObject)
    } catch (e: Rejected) {
        mapOf("verified" to false, "code" to e.code) + if (e.details.isEmpty()) emptyMap() else mapOf("details" to e.details)
    } catch (_: Exception) {
        // Never expose exception messages, certificate subjects, identifiers or input JSON.
        mapOf("verified" to false, "code" to "ATTESTATION_MALFORMED")
    }
    println(Gson().toJson(result))
}
