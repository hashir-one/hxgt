package `in`.hxdev.capture.sync

import android.app.*
import android.content.Context
import android.content.Intent
import android.os.IBinder
import android.os.SystemClock
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import `in`.hxdev.capture.CaptureApp
import `in`.hxdev.capture.MainActivity
import `in`.hxdev.capture.R
import kotlinx.coroutines.*

object Notices {
    private fun channel(context: Context, id: String, name: String, importance: Int) {
        context.getSystemService(NotificationManager::class.java).createNotificationChannel(NotificationChannel(id, name, importance))
    }
    private fun open(context: Context) = PendingIntent.getActivity(context, 0, Intent(context, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
    fun ongoing(context: Context): Notification {
        channel(context, "capture", "Capture session", NotificationManager.IMPORTANCE_LOW)
        val stop = PendingIntent.getService(context, 1, Intent(context, CaptureService::class.java).setAction("stop"), PendingIntent.FLAG_IMMUTABLE)
        return NotificationCompat.Builder(context, "capture").setSmallIcon(R.drawable.ic_capture).setContentTitle("HX Pay capture is running")
            .setContentText("Monitoring approved bank alerts. Tap to check health.").setOngoing(true).setContentIntent(open(context))
            .setVisibility(NotificationCompat.VISIBILITY_PRIVATE).addAction(0, "Stop capture", stop).build()
    }
    fun alert(context: Context, id: Long) = post(context, (id % 100000 + 100).toInt(), "Gateway needs attention", "Open HX Pay and review alerts in the merchant panel.")
    fun resume(context: Context) = post(context, 2, "Resume bank capture", "Your phone restarted. Open HX Pay to start a capture session.")
    private fun post(context: Context, id: Int, title: String, text: String) {
        channel(context, "alerts", "Gateway alerts", NotificationManager.IMPORTANCE_DEFAULT)
        val manager = NotificationManagerCompat.from(context)
        if (manager.areNotificationsEnabled()) {
            try { manager.notify(id, NotificationCompat.Builder(context, "alerts").setSmallIcon(R.drawable.ic_capture).setContentTitle(title)
                .setContentText(text).setContentIntent(open(context)).setAutoCancel(true).setVisibility(NotificationCompat.VISIBILITY_PRIVATE).build()) }
            catch (_: SecurityException) { /* The health screen still exposes unresolved alert counts. */ }
        }
    }
}

class CaptureService : Service() {
    private val repository get() = (application as CaptureApp).repository
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var started = false
    override fun onBind(intent: Intent?): IBinder? = null
    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == "stop") { stopSelf(); return START_NOT_STICKY }
        if (started) return START_NOT_STICKY
        if (repository.device() == null || !repository.prefs.getBoolean("consent", false)) { stopSelf(); return START_NOT_STICKY }
        startForeground(1, Notices.ongoing(this))
        started = true
        repository.sessionRunning = true
        scope.launch {
            while (isActive) {
                val began = SystemClock.elapsedRealtime()
                try { repository.heartbeat() }
                catch (e: Exception) { if (e is CancellationException) throw e; repository.error("HEARTBEAT_NETWORK_OR_KEY_ERROR") }
                delay((60000 - (SystemClock.elapsedRealtime() - began)).coerceAtLeast(1000))
            }
        }
        scope.launch {
            while (isActive) {
                try { repository.refreshRules(); repository.backfill(); repository.maintenance() }
                catch (e: Exception) { if (e is CancellationException) throw e; repository.error("SYNC_NEEDS_ATTENTION") }
                delay(300000)
            }
        }
        scope.launch {
            while (isActive) {
                try { repository.drain(); repository.refreshHealth() }
                catch (e: Exception) { if (e is CancellationException) throw e; repository.error("UPLOAD_NEEDS_ATTENTION") }
                delay(5000)
            }
        }
        DrainWorker.schedule(this)
        return START_NOT_STICKY
    }
    override fun onDestroy() {
        repository.sessionRunning = false
        scope.cancel()
        repository.scope.launch { repository.refreshHealth() }
        super.onDestroy()
    }
    override fun onTimeout(startId: Int, fgsType: Int) { repository.error("ANDROID_SESSION_TIMEOUT"); stopSelf() }
}
