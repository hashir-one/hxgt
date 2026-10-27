package `in`.hxdev.capture

import `in`.hxdev.capture.core.IdentityBarrier
import kotlinx.coroutines.*
import org.junit.Assert.*
import org.junit.Test

class IdentityBarrierTest {
    @Test fun oldCallbackCompletesBeforeActivationCanCheckPendingEvidence() = runBlocking {
        val barrier = IdentityBarrier()
        val entered = CompletableDeferred<Unit>()
        val release = CompletableDeferred<Unit>()
        val rows = mutableListOf<String>()
        var identity = "old"
        val ticket = barrier.ticket()
        val capture = launch(start = CoroutineStart.UNDISPATCHED) {
            barrier.current(ticket) { entered.complete(Unit); release.await(); rows += identity }
        }
        entered.await()
        val activation = async(start = CoroutineStart.UNDISPATCHED) {
            barrier.run {
                if (rows.isNotEmpty()) false else { identity = "new"; barrier.changed(); true }
            }
        }
        assertFalse(activation.isCompleted)
        release.complete(Unit); capture.join()
        assertFalse(activation.await())
        assertEquals(listOf("old"), rows); assertEquals("old", identity)
    }
    @Test fun queuedOldCallbackCannotWriteUnderNewIdentity() = runBlocking {
        val barrier = IdentityBarrier()
        val ticket = barrier.ticket()
        val entered = CompletableDeferred<Unit>(); val release = CompletableDeferred<Unit>()
        val rows = mutableListOf<String>()
        var identity = "old"
        val activation = launch(start = CoroutineStart.UNDISPATCHED) {
            barrier.run { entered.complete(Unit); release.await(); identity = "new"; barrier.changed() }
        }
        entered.await()
        val oldCallback = launch(start = CoroutineStart.UNDISPATCHED) { barrier.current(ticket) { rows += identity } }
        release.complete(Unit); activation.join(); oldCallback.join()
        assertTrue(rows.isEmpty())
        barrier.current(barrier.ticket()) { rows += identity }
        assertEquals(listOf("new"), rows)
    }
}
