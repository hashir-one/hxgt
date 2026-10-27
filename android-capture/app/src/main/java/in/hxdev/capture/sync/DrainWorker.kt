package `in`.hxdev.capture.sync

import android.content.Context
import androidx.work.*
import `in`.hxdev.capture.CaptureApp
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.CancellationException

class DrainWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val repository = (applicationContext as CaptureApp).repository
        return try {
            if (repository.drain()) Result.retry() else Result.success()
        } catch (e: Exception) {
            if (e is CancellationException) throw e
            repository.error("DEFERRED_UPLOAD_FAILED")
            Result.retry()
        }
    }
    companion object {
        fun schedule(context: Context) {
            val work = OneTimeWorkRequestBuilder<DrainWorker>().setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 10, TimeUnit.SECONDS).build()
            WorkManager.getInstance(context).enqueueUniqueWork("hx-upload", ExistingWorkPolicy.KEEP, work)
        }
    }
}
