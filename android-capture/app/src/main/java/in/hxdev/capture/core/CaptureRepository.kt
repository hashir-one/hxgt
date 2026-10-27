package `in`.hxdev.capture.core

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.os.SystemClock
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.room.withTransaction
import `in`.hxdev.capture.BuildConfig
import `in`.hxdev.capture.parser.CreditParser
import `in`.hxdev.capture.parser.Rules
import `in`.hxdev.capture.sync.DrainWorker
import `in`.hxdev.capture.sync.Notices
import java.time.Instant
import java.util.UUID
import kotlinx.coroutines.*
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import org.json.JSONArray
import org.json.JSONObject

data class Health(
    val device: String? = null, val trust: String = "Not paired", val session: Boolean = false,
    val sms: Boolean = false, val notifications: Boolean = false, val parserVersion: Int? = null,
    val pending: Int = 0, val blocked: Int = 0, val lastHeartbeat: Long = 0, val gatewayOpen: Boolean = false,
    val error: String = "", val configured: Boolean = false, val alerts: Int = 0,
    val pairingRecovery: Boolean = false,
    val stagedPairing: StagingPairingView? = null
)

class CaptureRepository(private val context: Context, private val database: CaptureDatabase, transport: DeviceTransport? = null, preferencesName: String = "capture") {
    val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    val prefs = context.getSharedPreferences(preferencesName, Context.MODE_PRIVATE)
    val vault = KeyVault()
    val api: DeviceTransport = transport ?: DeviceApi(vault)
    val staging by lazy { StagingPairing(context.getSharedPreferences("$preferencesName-staging", Context.MODE_PRIVATE), prefs, vault, api) }
    private val dao = database.outbox()
    val activity = dao.observe()
    private val mutableHealth = MutableStateFlow(Health())
    val health = mutableHealth.asStateFlow()
    private val uploadMutex = Mutex()
    private val pairMutex = Mutex()
    private val backfillMutex = Mutex()
    @Volatile var listenerConnected = false
    @Volatile var sessionRunning = false
    @Volatile private var rules: Rules? = null
    private var lastHeartbeatElapsed = 0L
    private var gatewayOpen = false
    private var trust = "Not paired"
    init {
        rules = runCatching { Rules.decode(JSONObject(prefs.getString("rules", "")!!)) }.getOrNull()
        scope.launch { try { refreshHealth() } catch (_: Exception) { error("STORAGE_UNAVAILABLE") } }
    }
    fun allowed(permission: String) = ContextCompat.checkSelfPermission(context, permission) == PackageManager.PERMISSION_GRANTED
    fun device() = prefs.getString("device", null)
    private fun alias() = prefs.getString("alias", null)
    fun currentRules() = rules
    fun captureAllowed() = sessionRunning && device() != null && prefs.getBoolean("consent", false) && !prefs.getBoolean("identity_blocked", false)
    fun error(code: String) {
        val editor = prefs.edit().putString("error", code.take(80))
        if (code in setOf("SMS_CAPTURE_INTERRUPTED", "PARSER_FAILURE_sms")) editor.putBoolean("sms_issue", true)
        if (code in setOf("BANK_APP_SIGNER_MISMATCH", "NOTIFICATION_CAPTURE_INTERRUPTED", "PARSER_FAILURE_notification")) editor.putBoolean("notification_issue", true)
        if (code in setOf("STORAGE_UNAVAILABLE", "KEY_OR_QUEUE_UNREADABLE")) editor.putBoolean("storage_issue", true)
        editor.apply()
        scope.launch { runCatching { refreshHealth() } }
    }
    fun approvedPackage(name: String): Boolean = runCatching {
        val expected = rules?.packages?.get(name) ?: return false
        @Suppress("DEPRECATION")
        val signers = context.packageManager.getPackageInfo(name, PackageManager.GET_SIGNING_CERTIFICATES).signingInfo?.apkContentsSigners.orEmpty()
        signers.isNotEmpty() && signers.all { Protocol.sha256(it.toByteArray()) in expected }
    }.getOrDefault(false)
    suspend fun refreshHealth() {
        val pending = dao.pending()
        val blocked = dao.blocked()
        val storageHealthy = !prefs.getBoolean("storage_issue", false) && pending < 10000 && blocked == 0
        val base = captureAllowed() && rules != null && storageHealthy && api.configured
        val smsHealthy = base && !prefs.getBoolean("sms_issue", false) && allowed(Manifest.permission.RECEIVE_SMS) && allowed(Manifest.permission.READ_SMS) && prefs.getBoolean("backfill_complete", false)
        val notificationHealthy = base && !prefs.getBoolean("notification_issue", false) && listenerConnected && NotificationManagerCompat.getEnabledListenerPackages(context).contains(context.packageName) && rules!!.packages.keys.any { approvedPackage(it) }
        val fresh = lastHeartbeatElapsed > 0 && SystemClock.elapsedRealtime() - lastHeartbeatElapsed < 90000
        mutableHealth.value = Health(device(), if (trust == "online" && !fresh) "offline" else trust, sessionRunning,
            smsHealthy, notificationHealthy,
            rules?.version, pending, blocked, prefs.getLong("last_heartbeat", 0),
            smsHealthy && notificationHealthy && trust == "online" && gatewayOpen && fresh,
            prefs.getString("error", "").orEmpty(), api.configured, prefs.getInt("alerts", 0),
            device() == null && alias() != null,
            if (BuildConfig.STAGING_PAIRING) runCatching { staging.view() }.getOrElse { StagingPairingView("storage_unavailable") } else null)
    }
    suspend fun prepareStagedPairing(input: String, expiresAt: String) = pairMutex.withLock {
        try { staging.prepare(input, expiresAt) } finally { refreshHealth() }
    }
    suspend fun submitStagedPairing() = pairMutex.withLock {
        try { staging.submit() } finally { refreshHealth() }
    }
    suspend fun activateStagedPairing() = pairMutex.withLock {
        uploadMutex.withLock {
            check(!sessionRunning) { "CAPTURE_MUST_BE_STOPPED" }
            check(dao.pending() == 0) { "PENDING_EVIDENCE_MUST_BE_RESOLVED" }
            staging.activate { id, key ->
                check(prefs.edit().putString("device", id).putString("alias", key).putString("pair_state", "paired")
                    .putLong("paired_at", System.currentTimeMillis()).remove("rules").remove("sms_cursor")
                    .remove("last_heartbeat").remove("last_alert").putBoolean("backfill_complete", false)
                    .putBoolean("identity_blocked", false).putString("error", "").commit()) { "STORAGE_UNAVAILABLE" }
            }
            rules = null; trust = "pending_review"; gatewayOpen = false; lastHeartbeatElapsed = 0
            refreshHealth()
        }
    }
    suspend fun pair(input: String) = pairMutex.withLock {
        if (!api.configured) failPairing("TLS_CONFIGURATION_REQUIRED")
        if (device() != null || alias() != null) failPairing("PAIRING_RECOVERY_REQUIRED")
        val token = try { Protocol.pairingToken(input, BuildConfig.GATEWAY_ORIGIN) }
            catch (_: Exception) { failPairing("INVALID_PAIRING_QR") }
        val newAlias = "hx-device-${UUID.randomUUID()}"
        if (!prefs.edit().putString("alias", newAlias).putString("pair_state", "preparing").commit()) failPairing("STORAGE_UNAVAILABLE")
        // The alias is persisted first: a lost response must never silently generate a second key.
        try { vault.generate(newAlias, token) } catch (_: Exception) { failPairing("HARDWARE_ATTESTATION_UNAVAILABLE") }
        if (!prefs.edit().putString("pair_state", "sending").commit()) failPairing("STORAGE_UNAVAILABLE")
        val body = JSONObject().put("pairing_token", token).put("public_key", vault.publicPem(newAlias))
            .put("attestation_certificate_chain", JSONArray(vault.chain(newAlias)))
            .put("app_version", BuildConfig.VERSION_NAME)
            .put("metadata", JSONObject().put("label", "Dedicated capture phone").put("model", Build.MODEL.take(80)).put("android_version", Build.VERSION.RELEASE.take(20)))
        val result = try { api.request("POST", "/api/device/v1/pair", body.toString().toByteArray(Charsets.UTF_8)) }
            catch (cancelled: CancellationException) { throw cancelled }
            catch (_: Exception) { failPairing("PAIRING_CONNECTION_FAILED") }
        if (result.code != 201) failPairing(PairingFeedback.responseCode(result.code, result.json))
        val id = try { UUID.fromString(result.json.getString("device_id")).toString() }
            catch (_: Exception) { failPairing("PAIRING_RESPONSE_INVALID") }
        if (!prefs.edit().putString("device", id).putString("pair_state", "paired").putLong("paired_at", System.currentTimeMillis()).putString("error", "").commit()) failPairing("STORAGE_UNAVAILABLE")
        trust = "pending_review"
        refreshRules()
        refreshHealth()
    }
    private fun failPairing(code: String): Nothing {
        // Only bounded local/allowlisted codes are logged, never QR tokens or response bodies.
        android.util.Log.w("HxPairing", code)
        error(code)
        throw PairingFailure(code)
    }
    suspend fun refreshRules() {
        if (device() == null || !api.configured) return
        val response = signed("GET", "/api/device/v1/parser-rules")
        if (response.code != 200) { rules = null; error("RULES_HTTP_${response.code}"); return }
        try {
            val parsed = Rules.decode(response.json)
            if (rules?.version != parsed.version) prefs.edit().putBoolean("sms_issue", false).putBoolean("notification_issue", false).apply()
            rules = parsed
            prefs.edit().putString("rules", response.json.toString()).putString("error", "").apply()
        } catch (_: Exception) {
            rules = null
            prefs.edit().remove("rules").apply()
            error("APPROVED_PARSER_REQUIRED")
        }
    }
    private suspend fun signed(method: String, path: String, body: String = ""): ApiResult = api.request(method, path, body.toByteArray(Charsets.UTF_8), device(), alias())
    suspend fun heartbeat() {
        if (device() == null || !api.configured) return
        refreshHealth()
        val h = health.value
        val body = JSONObject().put("observed_at", Instant.now().toString()).put("sms_listener_healthy", h.sms)
            .put("notification_listener_healthy", h.notifications).put("app_version", BuildConfig.VERSION_NAME)
        h.parserVersion?.let { body.put("parser_rule_version", it) }
        val result = signed("POST", "/api/device/v1/heartbeat", body.toString())
        if (result.code != 200) {
            gatewayOpen = false
            if (result.code == 401 || result.code == 403) { prefs.edit().putBoolean("identity_blocked", true).apply(); trust = "Identity needs recovery" }
            error("HEARTBEAT_HTTP_${result.code}")
            return
        }
        trust = result.json.getString("status")
        gatewayOpen = result.json.getBoolean("gateway_open")
        lastHeartbeatElapsed = SystemClock.elapsedRealtime()
        prefs.edit().putLong("last_heartbeat", System.currentTimeMillis()).apply()
        val alerts = result.json.optJSONArray("alerts") ?: JSONArray()
        prefs.edit().putInt("alerts", alerts.length()).apply()
        for (i in 0 until alerts.length()) {
            val id = alerts.getJSONObject(i).getLong("id")
            if (id > prefs.getLong("last_alert", 0)) {
                Notices.alert(context, id)
                prefs.edit().putLong("last_alert", id).apply()
            }
        }
        refreshHealth()
    }
    suspend fun capture(source: String, identity: String, text: String, occurred: Long, notificationKey: String? = null) {
        if (!captureAllowed()) return
        val activeRules = rules ?: return
        val parser = CreditParser(activeRules)
        val credit = parser.parse(source, identity, text) ?: run {
            if (parser.candidate(source, identity, text)) { fault("parser_failure", source); error("PARSER_FAILURE_$source") }
            return
        }
        val boundDevice = device() ?: return
        if (occurred <= 0 || occurred > System.currentTimeMillis() + 300000) { error("SOURCE_CLOCK_INVALID"); return }
        val fingerprintInput = if (source == "notification" && notificationKey != null)
            "$boundDevice\n$source\n$identity\n$notificationKey\n${credit.amountMinor}\n${credit.reference ?: Protocol.sha256(text.toByteArray())}"
            else "$boundDevice\n$source\n$identity\n$occurred\n${text.trim()}"
        val fingerprint = Protocol.sha256(fingerprintInput.toByteArray(Charsets.UTF_8))
        val id = UUID.randomUUID().toString()
        val captured = System.currentTimeMillis()
        val payload = JSONObject().put("client_signal_id", id).put("source", source).put("amount_minor", credit.amountMinor)
            .put("reference_number", credit.reference ?: JSONObject.NULL).put("occurred_at", Instant.ofEpochMilli(occurred).toString())
            .put("captured_at", Instant.ofEpochMilli(captured).toString()).put("parser_rule_version", activeRules.version)
            .put("source_metadata", JSONObject().put(if (source == "sms") "sender_id" else "package_name", identity))
        try {
            database.withTransaction {
                if (!dao.exists(fingerprint)) dao.insert(Envelope(id, boundDevice, fingerprint, "signal", source, vault.encrypt(payload.toString(), "$boundDevice:$id"), captured))
            }
            DrainWorker.schedule(context)
            refreshHealth()
        } catch (e: Exception) {
            if (e is CancellationException) throw e
            error("STORAGE_UNAVAILABLE")
            throw e // Backfill cursor must not advance when persistence failed.
        }
    }
    suspend fun fault(type: String, source: String) {
        require(type in setOf("parser_failure", "upload_error", "sync_error") && source in setOf("sms", "notification", "device"))
        val device = device() ?: return
        val episode = System.currentTimeMillis() / 3600000
        val fingerprint = Protocol.sha256("$device:$type:$source:$episode".toByteArray())
        val id = UUID.randomUUID().toString()
        val payload = JSONObject().put("client_fault_id", id).put("type", type).put("source", source).put("occurred_at", Instant.now().toString())
        rules?.let { payload.put("parser_rule_version", it.version) }
        dao.insert(Envelope(id, device, fingerprint, "fault", source, vault.encrypt(payload.toString(), "$device:$id"), System.currentTimeMillis()))
        DrainWorker.schedule(context)
    }
    suspend fun drain(): Boolean = uploadMutex.withLock {
        val boundDevice = device() ?: return@withLock false
        if (!api.configured || prefs.getBoolean("identity_blocked", false)) return@withLock false
        repeat(50) {
            val item = dao.next(boundDevice, System.currentTimeMillis()) ?: return@withLock dao.retryable(boundDevice) > 0
            val plain = try { vault.decrypt(item.encryptedPayload, "$boundDevice:${item.id}") } catch (_: Exception) {
                dao.update(item.copy(state = "blocked", errorCode = "KEY_OR_QUEUE_UNREADABLE")); error("KEY_OR_QUEUE_UNREADABLE"); return@withLock false
            }
            val result = try {
                signed("POST", if (item.kind == "signal") "/api/device/v1/signals" else "/api/device/v1/faults",
                    if (item.kind == "signal") "{\"signals\":[$plain]}" else plain)
            } catch (e: Exception) {
                if (e is CancellationException) throw e
                ApiResult(0, JSONObject())
            }
            val record = if (item.kind == "signal") result.json.optJSONArray("data")?.takeIf { it.length() == 1 }?.optJSONObject(0) else result.json
            val accepted = record?.optBoolean("accepted", false) == true && record.optLong(if (item.kind == "signal") "signal_id" else "report_id", 0) > 0
            val action = DeliveryPolicy.action(result.code, accepted)
            when (action) {
                DeliveryAction.ACKNOWLEDGE -> dao.update(item.copy(state = "delivered", errorCode = if (record?.optString("status") == "needs_review") "SERVER_REVIEW" else "", acknowledgedAt = System.currentTimeMillis()))
                DeliveryAction.BLOCK -> {
                    dao.update(item.copy(state = "blocked", attempts = item.attempts + 1, errorCode = if (result.code in 200..299) "EVIDENCE_REJECTED" else "HTTP_${result.code}"))
                    if (result.code in setOf(401, 403)) { prefs.edit().putBoolean("identity_blocked", true).apply(); gatewayOpen = false }
                    error("UPLOAD_HTTP_${result.code}")
                    return@withLock false
                }
                DeliveryAction.RETRY -> {
                    val delay = maxOf(result.retryAfter ?: 0, DeliveryPolicy.delayMillis(item.attempts, kotlin.random.Random.nextDouble()))
                    dao.update(item.copy(attempts = item.attempts + 1, nextAttempt = System.currentTimeMillis() + delay, errorCode = "HTTP_${result.code}"))
                    error("UPLOAD_RETRY_PENDING")
                    return@withLock true
                }
            }
        }
        dao.retryable(boundDevice) > 0
    }
    suspend fun maintenance() { dao.prune(System.currentTimeMillis() - 7 * 86400000L); refreshHealth() }
    suspend fun recover() {
        prefs.edit().putBoolean("identity_blocked", false).putString("error", "").apply()
        // A storage/key failure requires operator recovery, not a UI flag reset.
        check(!prefs.getBoolean("storage_issue", false)) { "STORAGE_REQUIRES_OPERATOR_RECOVERY" }
        refreshRules()
        heartbeat()
        if (!prefs.getBoolean("identity_blocked", false)) { dao.retryBlocked(); DrainWorker.schedule(context) }
        refreshHealth()
    }
    suspend fun backfill() = backfillMutex.withLock {
        if (!captureAllowed() || rules == null || !allowed(Manifest.permission.READ_SMS)) return@withLock
        val cutoff = prefs.getLong("paired_at", System.currentTimeMillis())
        val watermark = prefs.getLong("sms_cursor", cutoff)
        val until = System.currentTimeMillis()
        val senders = rules!!.senders.toList()
        val selection = "date >= ? AND date <= ? AND address IN (${senders.joinToString(",") { "?" }})"
        val args = listOf(maxOf(cutoff, watermark - 600000).toString(), until.toString()) + senders
        context.contentResolver.query(android.provider.Telephony.Sms.Inbox.CONTENT_URI,
            arrayOf("address", "body", "date", "date_sent"), selection, args.toTypedArray(), "date ASC, _id ASC")?.use { cursor ->
            while (cursor.moveToNext()) {
                if (!captureAllowed() || rules == null) return@withLock
                val occurred = cursor.getLong(3).takeIf { it > 0 } ?: cursor.getLong(2)
                capture("sms", cursor.getString(0), cursor.getString(1).orEmpty(), occurred)
            }
            prefs.edit().putLong("sms_cursor", until).putBoolean("backfill_complete", true).commit()
        }
        refreshHealth()
    }
    suspend fun prepareFreshPairing() = pairMutex.withLock {
        check(!sessionRunning) { "CAPTURE_MUST_BE_STOPPED" }
        check(dao.pending() == 0) { "PENDING_EVIDENCE_MUST_BE_RESOLVED" }
        // Keep old Keystore keys and receipts. No evidence can be reassigned to a new identity.
        check(prefs.edit().remove("device").remove("alias").remove("pair_state").remove("rules").remove("sms_cursor")
            .remove("paired_at").remove("last_heartbeat").remove("last_alert").putBoolean("backfill_complete", false)
            .putBoolean("identity_blocked", false).putString("error", "").commit()) { "STORAGE_UNAVAILABLE" }
        rules = null; trust = "Not paired"; gatewayOpen = false; lastHeartbeatElapsed = 0
        refreshHealth()
    }
    fun summary(item: Envelope): String = runCatching {
        if (item.kind != "signal") return@runCatching "Device health report"
        val json = JSONObject(vault.decrypt(item.encryptedPayload, "${item.deviceId}:${item.id}"))
        val amount = json.getLong("amount_minor")
        val reference = json.optString("reference_number").takeIf { it.matches(Regex("[0-9]{12}")) }?.takeLast(4)
        "₹${amount / 100}.${(amount % 100).toString().padStart(2, '0')} · ${reference?.let { "•••• $it" } ?: "Reference missing"}"
    }.getOrDefault("Encrypted evidence unavailable")
}
