package `in`.hxdev.capture

import android.app.Application
import androidx.room.Room
import `in`.hxdev.capture.core.CaptureDatabase
import `in`.hxdev.capture.core.CaptureRepository

class CaptureApp : Application() {
    val repository by lazy {
        CaptureRepository(this, Room.databaseBuilder(this, CaptureDatabase::class.java, "capture.db").build())
    }
}
