package `in`.hxdev.capture

import android.os.ParcelFileDescriptor
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assume.assumeTrue
import org.junit.Rule
import org.junit.Test

class PairingRecoveryUiTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()

    @Test fun interruptedPairingOffersRecoveryDirectlyInSetupWithoutResettingUserData() {
        assumeTrue(!BuildConfig.STAGING_PAIRING)
        val app = compose.activity.application as CaptureApp
        assumeTrue(app.repository.prefs.getString("device", null) == null && app.repository.prefs.getString("alias", null) != null)
        val descriptor = InstrumentationRegistry.getInstrumentation().uiAutomation.executeShellCommand(
            "am start -W -f 0x00020000 -n in.hxdev.capture.debug/in.hxdev.capture.MainActivity")
        ParcelFileDescriptor.AutoCloseInputStream(descriptor).use { it.readBytes() }
        compose.activityRule.scenario.onActivity { it.window.addFlags(android.view.WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON) }
        compose.waitUntil(10000) { runCatching { compose.onAllNodesWithText("HX PAY").fetchSemanticsNodes().isNotEmpty() }.getOrDefault(false) }
        compose.onNodeWithText("Setup").performClick()
        compose.onNodeWithText("Previous pairing needs recovery").performScrollTo().assertIsDisplayed()
        compose.onNodeWithText("Pair this phone").assertDoesNotExist()
        compose.onNodeWithText("Prepare a new pairing").performScrollTo().performClick()
        compose.onNodeWithText("Prepare a new pairing?").assertIsDisplayed()
        compose.onNodeWithText("Verify phone lock").assertIsDisplayed()
        // The operator performs credential confirmation. This test never resets their identity.
        compose.onNodeWithText("Cancel").performClick()
        compose.onNodeWithText("Previous pairing needs recovery").performScrollTo().assertIsDisplayed()
    }
}
