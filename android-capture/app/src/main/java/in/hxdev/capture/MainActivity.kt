package `in`.hxdev.capture

import android.Manifest
import android.app.KeyguardManager
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import android.view.WindowManager
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import `in`.hxdev.capture.core.CaptureRepository
import `in`.hxdev.capture.core.Health
import `in`.hxdev.capture.core.PairingFeedback
import `in`.hxdev.capture.core.PairingFailure
import `in`.hxdev.capture.sync.CaptureService
import kotlinx.coroutines.launch
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter

class MainActivity : ComponentActivity() {
    private val repository get() = (application as CaptureApp).repository
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
        setContent {
            val dark = isSystemInDarkTheme()
            val colors = if (dark) darkColorScheme(primary = Color(0xFFB9B1FF), background = Color(0xFF11121C), surface = Color(0xFF1B1D2B))
                else lightColorScheme(primary = Color(0xFF5145CD), background = Color(0xFFF5F5FB), surface = Color.White)
            MaterialTheme(colorScheme = colors) { CaptureUi(repository) }
        }
    }
    override fun onResume() {
        super.onResume()
        repository.scope.launch { runCatching { repository.refreshHealth() } }
    }
    private fun open(action: String, uri: Uri? = null) { runCatching { startActivity(Intent(action, uri)) }.onFailure { repository.error("SETTINGS_NOT_AVAILABLE") } }
    private fun panel(path: String) = open(Intent.ACTION_VIEW, Uri.parse(BuildConfig.GATEWAY_ORIGIN + path))

    @Composable private fun CaptureUi(repo: CaptureRepository) {
        val h by repo.health.collectAsState()
        LaunchedEffect(Unit) { while (true) { runCatching { repo.refreshHealth() }; kotlinx.coroutines.delay(5000) } }
        val scope = rememberCoroutineScope()
        var page by rememberSaveable { mutableIntStateOf(0) }
        var busy by remember { mutableStateOf(false) }
        var notice by remember { mutableStateOf("") }
        var reset by remember { mutableStateOf(false) }
        val tabs = listOf("Overview", "Setup", "Activity", "Settings")
        val permissions = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
            scope.launch { repo.refreshHealth() }
        }
        val authenticate = rememberLauncherForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
            if (result.resultCode == RESULT_OK) scope.launch {
                runCatching { kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) { repo.prepareFreshPairing() } }
                    .onSuccess { page = 1; notice = "The resolved attempt is archived. Generate a fresh owner-approved QR; its new key needs a new server fingerprint." }
                    .onFailure { notice = PairingFeedback.message(it.message.orEmpty()) }
            }
        }
        fun work(block: suspend () -> Unit) {
            if (busy) return
            busy = true
            scope.launch {
                try { kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) { block() } }
                catch (cancelled: kotlinx.coroutines.CancellationException) { throw cancelled }
                catch (failure: PairingFailure) { notice = PairingFeedback.message(failure.code) + "\n\nCode: " + failure.code + if (failure.detail.isNotBlank()) "\n" + failure.detail else "" }
                catch (failure: Exception) { notice = PairingFeedback.message(failure.message.orEmpty()) }
                finally { busy = false }
            }
        }
        Scaffold(containerColor = MaterialTheme.colorScheme.background, bottomBar = {
            NavigationBar {
                tabs.forEachIndexed { index, title -> NavigationBarItem(selected = page == index, onClick = { page = index },
                    icon = { Text(listOf("◉", "+", "≡", "⚙")[index], fontSize = 23.sp) }, label = { Text(title) }) }
            }
        }) { padding ->
            Column(Modifier.padding(padding).fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 22.dp, vertical = 24.dp), verticalArrangement = Arrangement.spacedBy(18.dp)) {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                    Column { Text("HX PAY", fontWeight = FontWeight.ExtraBold, color = MaterialTheme.colorScheme.primary, letterSpacing = 3.sp); Text("CAPTURE", style = MaterialTheme.typography.labelSmall, letterSpacing = 2.sp) }
                    Surface(color = MaterialTheme.colorScheme.secondaryContainer, shape = RoundedCornerShape(12.dp)) {
                        Text(if (BuildConfig.DEBUG) "TEST BUILD" else "DEDICATED DEVICE", Modifier.padding(10.dp), style = MaterialTheme.typography.labelSmall)
                    }
                }
                Text(tabs[page], style = MaterialTheme.typography.headlineLarge, fontWeight = FontWeight.Bold)
                if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
                when (page) {
                    0 -> {
                        Surface(color = MaterialTheme.colorScheme.primary, shape = RoundedCornerShape(26.dp), modifier = Modifier.fillMaxWidth()) {
                            Column(Modifier.padding(24.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                                Text(if (h.gatewayOpen) "Gateway accepting orders" else "Setup or recovery needed", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.onPrimary)
                                Text(if (h.device == null) "Connect this dedicated phone to your merchant account to begin." else "${displayTrust(h.trust)}. Capture health and gateway availability are checked separately.", color = MaterialTheme.colorScheme.onPrimary)
                                Button(onClick = { page = 1 }, colors = ButtonDefaults.buttonColors(containerColor = MaterialTheme.colorScheme.onPrimary, contentColor = MaterialTheme.colorScheme.primary)) { Text(if (h.device == null) "Set up device" else "Review setup") }
                            }
                        }
                        Section("Capture health") {
                            StatusRow("SMS reception + backfill", if (h.sms) "Healthy" else "Needs setup")
                            StatusRow("Bank notification listener", if (h.notifications) "Healthy" else "Needs setup")
                            StatusRow("Parser rules", h.parserVersion?.let { "Version $it" } ?: "Awaiting approved rules")
                            StatusRow("Capture session", if (h.session) "Running" else "Stopped")
                            StatusRow("Last heartbeat", time(h.lastHeartbeat))
                            StatusRow("Pending uploads", h.pending.toString())
                            if (h.blocked > 0) StatusRow("Uploads needing recovery", h.blocked.toString())
                        }
                        Section("Collections & alerts") {
                            Text("Confirmed collections come from the gateway. Local bank alerts are evidence, not paid orders.")
                            Text("${h.alerts} unresolved alerts returned by the last heartbeat.", fontWeight = FontWeight.Medium)
                            OutlinedButton(onClick = { panel("/panel") }, modifier = Modifier.fillMaxWidth()) { Text("Open merchant panel") }
                        }
                        ErrorCard(h)
                    }
                    1 -> {
                        Section("1   Your dedicated capture phone") {
                            Text("HX Pay reads approved Kotak credit SMS and bank-app notifications. It keeps structured payment evidence encrypted on this phone and uploads it to your merchant gateway. Raw messages stay out of storage and uploads.")
                            Text("Only the gateway can confirm an order. Keep this phone dedicated to the intended bank account.")
                            var consent by remember { mutableStateOf(repo.prefs.getBoolean("consent", false)) }
                            Row {
                                Checkbox(checked = consent, onCheckedChange = { consent = it; repo.prefs.edit().putBoolean("consent", it).apply(); if (!it) stopService(Intent(this@MainActivity, CaptureService::class.java)) })
                                Text("I authorize bank alert capture on this phone.", Modifier.padding(top = 12.dp))
                            }
                        }
                        Section("2   Pair with your merchant account") {
                            Text(BuildConfig.GATEWAY_ORIGIN, color = MaterialTheme.colorScheme.primary)
                            if (!h.configured) Text("You can scan a QR locally. Sending the pairing request is locked until approved TLS pins are configured.")
                            if (BuildConfig.STAGING_PAIRING) {
                                StagingPairingPanel(repo, h, busy, { work(it) }, { notice = it })
                            } else if (h.device != null) {
                                Text("Device paired · ${displayTrust(h.trust)}")
                                Text("Pairing does not mean this phone is ready to accept payments.")
                                Text("Existing identities are preserved. Use staging replacement after resolving original evidence. The legacy reset cannot discard a paired identity.")
                                OutlinedButton(onClick = { reset = true }, enabled = !busy, modifier = Modifier.fillMaxWidth()) { Text("Prepare a new pairing") }
                            } else if (h.pairingRecovery) {
                                Text("Previous pairing needs recovery", fontWeight = FontWeight.Bold)
                                Text("The unfinished attempt remains saved. Only an attempt-bound definitive rejection permits local archival. A lost or legacy result needs server pending-key recovery, which is not yet available.")
                                if (h.error.isNotEmpty()) {
                                    Text(PairingFeedback.message(h.error))
                                    Text("Code: ${h.error}", style = MaterialTheme.typography.labelSmall)
                                }
                                OutlinedButton(onClick = { reset = true }, enabled = !busy, modifier = Modifier.fillMaxWidth()) { Text("Prepare a new pairing") }
                            } else {
                                var token by remember { mutableStateOf("") }
                                val scanner = rememberLauncherForActivityResult(ScanContract()) { result -> result.contents?.let { token = it } }
                                Text("In the merchant Devices page, verify the owner's email and create a fresh single-use QR.")
                                OutlinedButton(onClick = { scanner.launch(ScanOptions().setDesiredBarcodeFormats(ScanOptions.QR_CODE).setPrompt("Scan the HX Pay pairing QR").setBeepEnabled(false).setOrientationLocked(false)) }, enabled = !busy, modifier = Modifier.fillMaxWidth()) { Text("Scan pairing QR") }
                                OutlinedTextField(value = token, onValueChange = { if (it.length <= 2048) token = it }, label = { Text("Pairing token or QR JSON") }, visualTransformation = PasswordVisualTransformation(), modifier = Modifier.fillMaxWidth())
                                Button(onClick = { val value = token; token = ""; work { repo.pair(value) } }, enabled = h.configured && token.isNotBlank() && !busy && repo.prefs.getBoolean("consent", false), modifier = Modifier.fillMaxWidth()) { Text("Pair this phone") }
                            }
                            TextButton(onClick = { panel("/panel/devices") }) { Text("Open merchant Devices page") }
                        }
                        Section("3   Allow capture access") {
                            Text("SMS access enables reception and inbox recovery. Notification access lets HX Pay read approved bank-app alerts. Android may restrict these settings for sideloaded apps.")
                            OutlinedButton(onClick = {
                                permissions.launch(buildList { add(Manifest.permission.RECEIVE_SMS); add(Manifest.permission.READ_SMS); if (Build.VERSION.SDK_INT >= 33) add(Manifest.permission.POST_NOTIFICATIONS) }.toTypedArray())
                            }, modifier = Modifier.fillMaxWidth()) { Text("Allow SMS & notification display") }
                            OutlinedButton(onClick = { open(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS) }, modifier = Modifier.fillMaxWidth()) { Text("Open notification access") }
                            TextButton(onClick = { open(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:$packageName")) }) { Text("App permissions & restricted settings") }
                        }
                        Section("4   Start a visible capture session") {
                            Text("Keep the ongoing notification visible. On your iQOO Neo 9 Pro, review OriginOS autostart and background power settings. After restart or force-stop, reopen HX Pay and resume capture.")
                            Button(onClick = {
                                if (h.session) stopService(Intent(this@MainActivity, CaptureService::class.java))
                                else runCatching { ContextCompat.startForegroundService(this@MainActivity, Intent(this@MainActivity, CaptureService::class.java)) }.onFailure { notice = "Android could not start capture. Open app settings and try again." }
                            }, enabled = h.device != null && repo.prefs.getBoolean("consent", false), modifier = Modifier.fillMaxWidth()) { Text(if (h.session) "Stop capture" else "Start capture") }
                            Text("Pending trust, missing rules, or denied access will keep the gateway unavailable.", style = MaterialTheme.typography.bodySmall)
                        }
                        ErrorCard(h)
                    }
                    2 -> {
                        val rows by repo.activity.collectAsState(initial = emptyList())
                        Text("Captured alerts", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                        Text("The latest 100 local records. A delivered alert is not a confirmed payment. Delivered receipts expire after 7 days; unresolved evidence is retained.")
                        if (rows.isEmpty()) Section("No captured alerts yet") { Text("Approved bank credits will appear here after pairing, permissions and parser setup are complete.") }
                        rows.forEach { row -> Section(if (row.source == "sms") "SMS credit" else if (row.source == "notification") "Bank notification" else "Device report") {
                            Text(repo.summary(row), fontWeight = FontWeight.SemiBold)
                            StatusRow("Delivery", row.state.replaceFirstChar { it.uppercase() })
                            Text(time(row.capturedAt), style = MaterialTheme.typography.bodySmall)
                            if (row.errorCode.isNotEmpty()) Text(row.errorCode, style = MaterialTheme.typography.bodySmall)
                        } }
                    }
                    3 -> {
                        Section("Device & environment") {
                            StatusRow("App", BuildConfig.VERSION_NAME)
                            StatusRow("Phone", Build.MODEL)
                            StatusRow("Android", Build.VERSION.RELEASE)
                            Text(BuildConfig.GATEWAY_ORIGIN)
                            Text("Install updates from your operator with the same signing identity. Never clear app data to repair a delivery problem.")
                        }
                        Section("Background reliability") {
                            Text("OriginOS: check Settings → Apps → HX Pay Capture for permissions and notification access. In battery/background power controls, allow this app to run in the background and enable autostart where available. Menu names vary by firmware.")
                            Text("Keep the bank app signed in. Overnight reliability must be measured on this phone; an exemption does not guarantee delivery.")
                            OutlinedButton(onClick = { open(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS) }, modifier = Modifier.fillMaxWidth()) { Text("Open battery settings") }
                        }
                        Section("Recovery & diagnostics") {
                            Text("An interrupted pairing requires an authoritative pending-key recovery API. The owner panel cannot establish its key outcome. Keep existing keys and evidence; repeated submissions or expiry do not resolve it.")
                            OutlinedButton(onClick = { work { repo.recover() } }, enabled = !busy && h.device != null, modifier = Modifier.fillMaxWidth()) { Text("Refresh rules & retry blocked uploads") }
                            OutlinedButton(onClick = {
                                val report = "HX Pay ${BuildConfig.VERSION_NAME}\nAndroid ${Build.VERSION.RELEASE}\nSession=${h.session}\nSMS=${h.sms}\nListener=${h.notifications}\nParser=${h.parserVersion}\nPending=${h.pending}\nBlocked=${h.blocked}\nError=${h.error}"
                                startActivity(Intent.createChooser(Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, report), "Export safe diagnostics"))
                            }, modifier = Modifier.fillMaxWidth()) { Text("Export safe diagnostics") }
                            if (BuildConfig.STAGING_PAIRING) TextButton(onClick = { page = 1 }) { Text("Open staging pairing recovery") }
                            else TextButton(onClick = { reset = true }) { Text("Prepare a new pairing") }
                        }
                        ErrorCard(h)
                    }
                }
                Text("HX Pay · Evidence captured here. Payments verified by the gateway.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                Spacer(Modifier.height(12.dp))
            }
        }
        if (notice.isNotEmpty()) AlertDialog(onDismissRequest = { notice = "" },
            title = { Text("Device setup") }, text = { Text(notice) },
            confirmButton = { TextButton(onClick = { notice = "" }) { Text("OK") } })
        if (reset) AlertDialog(onDismissRequest = { reset = false }, title = { Text("Prepare a new pairing?") },
            text = { Text(when {
                h.session -> PairingFeedback.message("CAPTURE_MUST_BE_STOPPED")
                h.pending > 0 -> PairingFeedback.message("PENDING_EVIDENCE_MUST_BE_RESOLVED")
                else -> "This action can archive only a definitively rejected attempt with a matching durable receipt. Paired identities and uncertain or legacy attempts stay blocked. The owner panel cannot override this guard."
            }) },
            confirmButton = { TextButton(onClick = {
                reset = false
                @Suppress("DEPRECATION")
                val intent = getSystemService(KeyguardManager::class.java).createConfirmDeviceCredentialIntent("Confirm device recovery", "Authenticate to prepare a fresh pairing")
                if (intent != null) authenticate.launch(intent) else notice = "Set a secure screen lock before local recovery."
            }, enabled = !busy && !h.session && h.pending == 0) { Text("Verify phone lock") } }, dismissButton = { TextButton(onClick = { reset = false }) { Text("Cancel") } })
    }

    @Composable private fun StagingPairingPanel(repo: CaptureRepository, h: Health, busy: Boolean,
        work: (suspend () -> Unit) -> Unit, notice: (String) -> Unit) {
        var token by remember { mutableStateOf("") }
        var expiry by remember { mutableStateOf("") }
        var ownerAuthorized by remember { mutableStateOf(false) }
        var serverPinReady by remember(h.stagedPairing?.fingerprint) { mutableStateOf(false) }
        var authenticating by remember { mutableStateOf(false) }
        var authAction by remember { mutableStateOf("") }
        val authenticateStage = rememberLauncherForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
            authenticating = false
            if (result.resultCode == RESULT_OK) {
                val action = authAction
                val value = token
                val expiryValue = expiry
                if (action == "prepare") token = ""
                work {
                    when (action) {
                        "prepare" -> repo.prepareStagedPairing(value, expiryValue)
                        "activate" -> repo.activateStagedPairing()
                    }
                }
            }
        }
        fun authenticate(action: String) {
            authAction = action
            @Suppress("DEPRECATION")
            val intent = getSystemService(KeyguardManager::class.java).createConfirmDeviceCredentialIntent(
                "Confirm staging pairing", "Authenticate to $action this pairing. Existing keys and evidence are retained.")
            if (intent != null) { authenticating = true; authenticateStage.launch(intent) }
            else notice("Set a secure screen lock before staging pairing.")
        }
        val scanner = rememberLauncherForActivityResult(ScanContract()) { result -> result.contents?.let { token = it } }
        val stage = h.stagedPairing
        val locked = busy || authenticating
        Text("Staging pairing · prepare, review, submit", fontWeight = FontWeight.Bold)
        Text("Your existing identity, keys and unresolved evidence stay saved. Preparing a key does not send a pairing request.")
        if (stage != null) {
            StatusRow("Saved attempt", stage.state.replace('_', ' '))
            if (stage.fingerprint.isNotEmpty()) {
                androidx.compose.foundation.text.selection.SelectionContainer { Text(stage.fingerprint, style = MaterialTheme.typography.bodySmall) }
                OutlinedButton(onClick = {
                    val report = "Pending DER SPKI SHA-256: ${stage.fingerprint}\n${stage.publicPem}\nQR expiry: ${stage.expiresAt}"
                    startActivity(Intent.createChooser(Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, report), "Share pending public key"))
                }) { Text("Share fingerprint & public key") }
            }
            Text("QR expires ${stage.expiresAt}")
            if (stage.error.isNotEmpty()) { Text(PairingFeedback.message(stage.error)); Text("Code: ${stage.error}") }
            if (stage.detail.isNotEmpty()) Text(stage.detail, style = MaterialTheme.typography.bodySmall)
            when (stage.state) {
                "prepared", "rejected" -> {
                    Text("Give this fingerprint to the server operator. Submission requires its matching exception, stopped capture and no unresolved evidence: server registration can immediately revoke the old key.")
                    Row { Checkbox(serverPinReady, { serverPinReady = it }); Text("The server exception matches this fingerprint.", Modifier.padding(top = 12.dp)) }
                    Button(onClick = { work { repo.submitStagedPairing() } }, enabled = !locked && serverPinReady && h.configured && !h.session && h.pending == 0) { Text("Submit this saved pairing") }
                }
                "uncertain" -> Text("Submission outcome unknown. Ask the operator to check this fingerprint on the server. Retry and replacement are blocked; expiry does not prove rejection.")
                "accepted" -> {
                    Text("Server returned device ${stage.device}. Activate only when capture is stopped and unresolved evidence is resolved under its original identity.")
                    Button(onClick = { authenticate("activate") }, enabled = !locked && !h.session && h.pending == 0) { Text("Activate confirmed pairing") }
                }
                "activated" -> Text("Confirmed pairing activated. Previous identity details remain archived; configure capture access and refresh rules.")
                "expired" -> Text("Expired without a successful submission. This attempt is retained. A new QR requires a new fingerprint.")
                "storage_unavailable" -> Text("Pairing journal unavailable. Preserve app data and contact the operator.")
            }
        }
        if (stage == null || stage.state in setOf("prepared", "rejected", "expired", "creating", "preparation_failed", "activated")) {
            Text("Create a fresh owner-authorized QR in the merchant panel. Copy its exact UTC expiry below.")
            OutlinedButton(onClick = { scanner.launch(ScanOptions().setDesiredBarcodeFormats(ScanOptions.QR_CODE).setPrompt("Scan owner-authorized staging QR").setBeepEnabled(false).setOrientationLocked(false)) }, enabled = !locked) { Text("Scan staging QR") }
            OutlinedTextField(token, { if (it.length <= 2048) token = it }, label = { Text("Pairing token or QR JSON") }, visualTransformation = PasswordVisualTransformation(), enabled = !locked, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(expiry, { if (it.length <= 40) expiry = it }, label = { Text("QR expiry UTC · YYYY-MM-DDTHH:mm:ssZ") }, enabled = !locked, modifier = Modifier.fillMaxWidth())
            Row { Checkbox(ownerAuthorized, { ownerAuthorized = it }); Text("The owner authorized this fresh QR.", Modifier.padding(top = 12.dp)) }
            Button(onClick = { authenticate("prepare") }, enabled = !locked && token.isNotBlank() && expiry.isNotBlank() && ownerAuthorized) { Text("Prepare key & show fingerprint") }
        }
    }
}

@Composable private fun Section(title: String, content: @Composable ColumnScope.() -> Unit) {
    Card(Modifier.fillMaxWidth(), shape = RoundedCornerShape(22.dp), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
        Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            content()
        }
    }
}
@Composable private fun StatusRow(label: String, value: String) {
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(3.dp)) {
        Text(label, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Text(value, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium)
    }
}
@Composable private fun ErrorCard(h: Health) {
    if (h.error.isNotEmpty()) Section("Needs attention") {
        Text(when (h.error) {
            "APPROVED_PARSER_REQUIRED" -> "Ask the operator to publish validated Android-compatible bank rules. No approved parser is active."
            "PAIRING_RECOVERY_REQUIRED" -> PairingFeedback.message(h.error)
            "STORAGE_UNAVAILABLE", "KEY_OR_QUEUE_UNREADABLE" -> "Evidence storage is unavailable. Preserve app data and contact your operator."
            else -> "Check network, permissions and merchant device status, then use recovery in Settings."
        })
        Text(h.error, style = MaterialTheme.typography.labelSmall)
    }
}
private fun displayTrust(value: String) = when (value) { "pending_review" -> "Awaiting server hardware verification"; "online" -> "Verified and online"; "degraded" -> "Verified, capture degraded"; "offline" -> "Awaiting healthy heartbeats"; else -> value }
private fun time(epoch: Long) = if (epoch == 0L) "Never" else DateTimeFormatter.ofPattern("dd MMM, HH:mm:ss").withZone(ZoneId.systemDefault()).format(Instant.ofEpochMilli(epoch))
