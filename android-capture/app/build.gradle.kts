plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.compose.compiler)
    alias(libs.plugins.ksp)
}

fun quoted(value: String) = "\"" + value.replace("\\", "\\\\").replace("\"", "\\\"") + "\""
val releasePins = providers.environmentVariable("HX_TLS_PINS").orElse("")
android {
    namespace = "in.hxdev.capture"
    compileSdk = 36
    defaultConfig {
        applicationId = "in.hxdev.capture"
        minSdk = 29
        targetSdk = 36
        versionCode = 1
        versionName = "0.1.0"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }
    signingConfigs {
        create("operator") {
            val path = System.getenv("HX_SIGNING_STORE")
            if (!path.isNullOrBlank()) {
                storeFile = file(path)
                storePassword = System.getenv("HX_SIGNING_STORE_PASSWORD")
                keyAlias = System.getenv("HX_SIGNING_ALIAS")
                keyPassword = System.getenv("HX_SIGNING_KEY_PASSWORD")
            }
        }
    }
    buildTypes {
        debug {
            buildConfigField("boolean", "STAGING_PAIRING", providers.gradleProperty("hxStagingPairing").orElse("false").get().toBoolean().toString())
            applicationIdSuffix = ".debug"
            versionNameSuffix = "-debug"
            buildConfigField("String", "GATEWAY_ORIGIN", quoted(providers.gradleProperty("hxDebugOrigin").orElse("https://pay.hxdev.in").get()))
            buildConfigField("String", "TLS_PINS", quoted(providers.gradleProperty("hxDebugPins").orElse("").get()))
            resValue("string", "app_name", "HX Pay Capture · Test")
        }
        release {
            buildConfigField("boolean", "STAGING_PAIRING", "false")
            isMinifyEnabled = true
            isShrinkResources = true
            signingConfig = signingConfigs.getByName("operator")
            buildConfigField("String", "GATEWAY_ORIGIN", quoted("https://pay.hxdev.in"))
            buildConfigField("String", "TLS_PINS", quoted(releasePins.get()))
            resValue("string", "app_name", "HX Pay Capture")
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
    }
    compileOptions { sourceCompatibility = JavaVersion.VERSION_17; targetCompatibility = JavaVersion.VERSION_17 }
    buildFeatures { compose = true; buildConfig = true; resValues = true }
    testOptions { unitTests.isIncludeAndroidResources = false }
    packaging { resources.excludes += "/META-INF/{AL2.0,LGPL2.1}" }
}
kotlin { compilerOptions { jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17) } }
ksp { arg("room.schemaLocation", "$projectDir/schemas") }

val validateRelease by tasks.registering {
    doLast {
        val pins = releasePins.get().split(',').filter { it.isNotBlank() }
        check(pins.distinct().size >= 2 && pins.all { it.matches(Regex("sha256/[A-Za-z0-9+/]{43}=")) }) {
            "Release requires independently controlled active and backup HX_TLS_PINS."
        }
        check(!System.getenv("HX_SIGNING_STORE").isNullOrBlank()) { "Configure external release signing. Debug signing is never used for release." }
    }
}
tasks.matching { it.name == "preReleaseBuild" }.configureEach { dependsOn(validateRelease) }
dependencies {
    implementation(platform(libs.compose.bom))
    implementation(libs.compose.ui)
    implementation(libs.compose.material3)
    implementation(libs.activity.compose)
    implementation(libs.room.runtime)
    ksp(libs.room.compiler)
    implementation(libs.work.runtime)
    implementation(libs.okhttp)
    implementation(libs.coroutines)
    implementation(libs.re2j)
    implementation(libs.scanner)
    testImplementation(libs.junit)
    testImplementation(libs.json)
    androidTestImplementation(libs.android.test.runner)
    androidTestImplementation(libs.android.test.junit)
    androidTestImplementation(platform(libs.compose.bom))
    androidTestImplementation("androidx.compose.ui:ui-test-junit4")
}
