plugins {
    kotlin("jvm") version "2.2.0"
    application
}
repositories { mavenCentral(); google() }
dependencies {
    implementation("androidx.annotation:annotation:1.9.1")
    implementation("co.nstant.in:cbor:0.9")
    implementation("com.google.code.gson:gson:2.11.0")
    implementation("com.google.errorprone:error_prone_annotations:2.41.0")
    implementation("com.google.protobuf:protobuf-javalite:4.28.3")
    implementation("com.google.protobuf:protobuf-kotlin-lite:4.28.3")
    implementation("org.bouncycastle:bcpkix-jdk18on:1.86")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-core:1.10.2")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-guava:1.10.2")
    implementation("com.google.guava:guava:33.5.0-jre")
}
kotlin { jvmToolchain(21) }
sourceSets {
    main {
        kotlin.srcDir("upstream/src/main/kotlin")
        kotlin.exclude("testing/**", "VerifierCli.kt", "CertPrinter.kt")
    }
    test { kotlin.srcDir("upstream/src/main/kotlin/testing") }
}
application { mainClass.set("in.hxdev.attestation.MainKt") }
dependencyLocking { lockAllConfigurations() }
tasks.register<JavaExec>("fixtures") {
    dependsOn(tasks.testClasses)
    classpath = sourceSets.test.get().runtimeClasspath
    mainClass.set("in.hxdev.attestation.FixturesKt")
    args(rootProject.file("../../tests/Fixtures/AndroidAttestation").absolutePath)
}
