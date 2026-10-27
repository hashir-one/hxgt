package `in`.hxdev.capture

import android.os.ParcelFileDescriptor
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assume.assumeTrue
import org.junit.Rule
import org.junit.Test

class StagingPairingUiTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()
    @Test fun setupExposesPreparationWithoutSubmittingOrResettingIdentity() {
        assumeTrue(BuildConfig.STAGING_PAIRING)
        val app = compose.activity.application as CaptureApp
        assumeTrue(app.repository.staging.view() == null)
        val before = app.repository.prefs.all.toMap()
        val descriptor = InstrumentationRegistry.getInstrumentation().uiAutomation.executeShellCommand(
            "am start -W -f 0x00020000 -n in.hxdev.capture.debug/in.hxdev.capture.MainActivity")
        ParcelFileDescriptor.AutoCloseInputStream(descriptor).use { it.readBytes() }
        compose.activityRule.scenario.onActivity { it.window.addFlags(android.view.WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON) }
        compose.waitUntil(10000) { runCatching { compose.onAllNodesWithText("HX PAY").fetchSemanticsNodes().isNotEmpty() }.getOrDefault(false) }
        compose.onNodeWithText("Setup").performClick()
        compose.onNodeWithText("Staging pairing · prepare, review, submit").performScrollTo().assertIsDisplayed()
        compose.onNodeWithText("Prepare key & show fingerprint").performScrollTo().assertIsNotEnabled()
        compose.onNodeWithText("Submit this saved pairing").assertDoesNotExist()
        compose.onNodeWithText("Prepare a new pairing").assertDoesNotExist()
        org.junit.Assert.assertEquals(before, app.repository.prefs.all)
    }
}
