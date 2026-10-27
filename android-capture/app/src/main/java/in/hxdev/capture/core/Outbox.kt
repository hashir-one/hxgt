package `in`.hxdev.capture.core

import androidx.room.*
import kotlinx.coroutines.flow.Flow

@Entity(tableName = "outbox", indices = [Index(value = ["fingerprint"], unique = true)])
data class Envelope(
    @PrimaryKey val id: String,
    val deviceId: String,
    val fingerprint: String,
    val kind: String,
    val source: String,
    val encryptedPayload: String,
    val capturedAt: Long,
    val state: String = "pending",
    val attempts: Int = 0,
    val nextAttempt: Long = 0,
    val errorCode: String = "",
    val acknowledgedAt: Long? = null
)
@Dao interface OutboxDao {
    @Insert(onConflict = OnConflictStrategy.IGNORE) suspend fun insert(item: Envelope): Long
    @Query("SELECT * FROM outbox ORDER BY capturedAt DESC LIMIT 100") fun observe(): Flow<List<Envelope>>
    @Query("SELECT COUNT(*) FROM outbox WHERE state != 'delivered'") suspend fun pending(): Int
    @Query("SELECT COUNT(*) FROM outbox WHERE state = 'blocked'") suspend fun blocked(): Int
    @Query("SELECT * FROM outbox WHERE deviceId = :device AND state = 'pending' AND nextAttempt <= :now ORDER BY capturedAt, id LIMIT 1") suspend fun next(device: String, now: Long): Envelope?
    @Query("SELECT EXISTS(SELECT 1 FROM outbox WHERE fingerprint = :fingerprint)") suspend fun exists(fingerprint: String): Boolean
    @Query("SELECT COUNT(*) FROM outbox WHERE deviceId = :device AND state = 'pending'") suspend fun retryable(device: String): Int
    @Update suspend fun update(item: Envelope)
    @Query("DELETE FROM outbox WHERE state = 'delivered' AND acknowledgedAt < :before") suspend fun prune(before: Long)
    @Query("UPDATE outbox SET state = 'pending', nextAttempt = 0 WHERE state = 'blocked' AND errorCode IN ('HTTP_401','HTTP_403','HTTP_422','EVIDENCE_REJECTED')") suspend fun retryBlocked()
}
@Database(entities = [Envelope::class], version = 1, exportSchema = true)
abstract class CaptureDatabase : RoomDatabase() { abstract fun outbox(): OutboxDao }
