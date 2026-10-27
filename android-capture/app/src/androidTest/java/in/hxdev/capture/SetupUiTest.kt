package `in`.hxdev.capture

import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.test.platform.app.InstrumentationRegistry
import android.os.ParcelFileDescriptor
import android.view.WindowManager
import org.junit.Before
import org.junit.Rule
import org.junit.Test

class SetupUiTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()
    @Before fun bringTestActivityToForeground() {
        // Some OEM task managers pause an ActivityScenario launch immediately.
        // Reorder only this app's existing task; never dismiss another app or change device policy.
        val descriptor = InstrumentationRegistry.getInstrumentation().uiAutomation.executeShellCommand(
            "am start -W -f 0x00020000 -n in.hxdev.capture.debug/in.hxdev.capture.MainActivity")
        ParcelFileDescriptor.AutoCloseInputStream(descriptor).use { it.readBytes() }
        compose.activityRule.scenario.onActivity { it.window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON) }
        compose.waitUntil(timeoutMillis = 10000) {
            runCatching { compose.onAllNodesWithText("HX PAY").fetchSemanticsNodes().isNotEmpty() }.getOrDefault(false)
        }
        compose.onNode(hasText("Overview") and hasClickAction()).performClick()
    }
    @Test fun unpairedPhoneShowsDegradedHealthAndBlocksUnconfiguredPairing() {
        compose.onNodeWithText("Setup or recovery needed").performScrollTo().assertIsDisplayed()
        compose.onNodeWithText("Setup").performClick()
        compose.onNodeWithText("https://pay.hxdev.in").performScrollTo().assertIsDisplayed()
        compose.onNodeWithText("Scan pairing QR").performScrollTo().assertIsEnabled()
        compose.onNodeWithText("Pair this phone").assertIsNotEnabled()
        compose.onNodeWithText("Start capture").performScrollTo().assertIsNotEnabled()
    }
    @Test fun emptyActivityNeverClaimsPaidOrdersAndSettingsRemainReachable() {
        compose.onNodeWithText("Activity").performClick()
        compose.onNodeWithText("No captured alerts yet").assertIsDisplayed()
        compose.onNodeWithText("Settings").performClick()
        compose.onNodeWithText("Device & environment").assertIsDisplayed()
        compose.onNodeWithText("Export safe diagnostics").performScrollTo().assertIsEnabled()
    }
    @Test fun localQrScannerOpensWithoutNetworkPins() {
        val instrumentation = InstrumentationRegistry.getInstrumentation()
        val monitor = instrumentation.addMonitor("com.journeyapps.barcodescanner.CaptureActivity", null, false)
        try {
            compose.onNodeWithText("Setup").performClick()
            compose.onNodeWithText("Scan pairing QR").performScrollTo().performClick()
            val scanner = instrumentation.waitForMonitorWithTimeout(monitor, 5000)
            org.junit.Assert.assertNotNull("QR camera activity should launch without network configuration", scanner)
            val deadline = android.os.SystemClock.elapsedRealtime() + 10000
            var previewActive = false
            while (!previewActive && android.os.SystemClock.elapsedRealtime() < deadline) {
                instrumentation.runOnMainSync {
                    previewActive = scanner.findViewById<com.journeyapps.barcodescanner.DecoratedBarcodeView>(
                        com.google.zxing.client.android.R.id.zxing_barcode_scanner).barcodeView.isPreviewActive
                }
                if (!previewActive) android.os.SystemClock.sleep(100)
            }
            org.junit.Assert.assertTrue("Physical camera preview should be active", previewActive)
            instrumentation.runOnMainSync { scanner.finish() }
        } finally { instrumentation.removeMonitor(monitor) }
    }
}
