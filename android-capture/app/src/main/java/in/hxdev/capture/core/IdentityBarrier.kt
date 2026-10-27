package `in`.hxdev.capture.core

import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import java.util.concurrent.atomic.AtomicLong

/** Lock order: pairing mutex -> identity barrier -> staging journal mutex -> database. */
class IdentityBarrier {
    private val mutex = Mutex()
    private val generation = AtomicLong()
    fun ticket(): Long = generation.get()
    suspend fun <T> run(block: suspend () -> T): T = mutex.withLock { block() }
    suspend fun current(ticket: Long, block: suspend () -> Unit) = mutex.withLock {
        if (generation.get() == ticket) block()
    }
    // Call only while holding this barrier, immediately before changing active identity.
    fun changed() { generation.incrementAndGet() }
}
