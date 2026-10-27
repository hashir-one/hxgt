package `in`.hxdev.capture.capture

import android.app.Notification
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.provider.Telephony
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification
import `in`.hxdev.capture.CaptureApp
import `in`.hxdev.capture.core.Protocol
import `in`.hxdev.capture.sync.DrainWorker
import `in`.hxdev.capture.sync.Notices
import kotlinx.coroutines.launch
import kotlinx.coroutines.withTimeout

class SmsReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Telephony.Sms.Intents.SMS_RECEIVED_ACTION) return
        val repository = (context.applicationContext as CaptureApp).repository
        if (!repository.captureAllowed()) return
        val messages = Telephony.Sms.Intents.getMessagesFromIntent(intent)
        val pending = goAsync()
        repository.scope.launch {
            try {
                withTimeout(7000) {
                    messages.groupBy { Pair(it.originatingAddress, it.timestampMillis) }.forEach { (key, parts) ->
                        key.first?.let { repository.capture("sms", it, parts.joinToString("") { p -> p.messageBody.orEmpty() }, key.second) }
                    }
                }
            } catch (_: Exception) { repository.error("SMS_CAPTURE_INTERRUPTED") }
            finally { pending.finish() }
        }
    }
}

class BankNotifications : NotificationListenerService() {
    private val repository get() = (application as CaptureApp).repository
    override fun onListenerConnected() {
        repository.listenerConnected = true
        repository.scope.launch { repository.refreshHealth() }
        // These are active alerts, not a reconstruction of dismissed notifications.
        activeNotifications?.forEach { onNotificationPosted(it) }
    }
    override fun onListenerDisconnected() {
        repository.listenerConnected = false
        repository.scope.launch { repository.refreshHealth() }
    }
    override fun onDestroy() { repository.listenerConnected = false; super.onDestroy() }
    override fun onNotificationPosted(sbn: StatusBarNotification) {
        if (!repository.captureAllowed() || sbn.notification.flags and Notification.FLAG_GROUP_SUMMARY != 0) return
        val rule = repository.currentRules() ?: return
        if (sbn.packageName !in rule.packages) return
        val validSigner = repository.approvedPackage(sbn.packageName)
        if (!validSigner) { repository.error("BANK_APP_SIGNER_MISMATCH"); return }
        val extras = sbn.notification.extras
        val text = listOfNotNull(extras.getCharSequence(Notification.EXTRA_TITLE)?.toString(),
            extras.getCharSequence(Notification.EXTRA_BIG_TEXT)?.toString() ?: extras.getCharSequence(Notification.EXTRA_TEXT)?.toString(),
            extras.getCharSequenceArray(Notification.EXTRA_TEXT_LINES)?.joinToString("\n")).distinct().joinToString("\n")
        repository.scope.launch {
            try { repository.capture("notification", sbn.packageName, text, sbn.postTime, sbn.key) }
            catch (_: Exception) { repository.error("NOTIFICATION_CAPTURE_INTERRUPTED") }
        }
    }
}

class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_BOOT_COMPLETED) return
        val repository = (context.applicationContext as CaptureApp).repository
        if (repository.device() != null) {
            Notices.resume(context)
            DrainWorker.schedule(context)
        }
    }
}
