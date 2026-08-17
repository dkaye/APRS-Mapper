pluginManagement {
    val flutterSdkPath =
        run {
            val properties = java.util.Properties()
            file("local.properties").inputStream().use { properties.load(it) }
            val flutterSdkPath = properties.getProperty("flutter.sdk")
            require(flutterSdkPath != null) { "flutter.sdk not set in local.properties" }
            flutterSdkPath
        }

    includeBuild("$flutterSdkPath/packages/flutter_tools/gradle")

    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}

plugins {
    id("dev.flutter.flutter-plugin-loader") version "1.0.0"
    id("com.android.application") version "9.0.1" apply false
    id("org.jetbrains.kotlin.android") version "2.3.20" apply false
    // Kotlin 2.x moved the Compose compiler out of the Kotlin plugin and into its own,
    // versioned in lockstep with Kotlin. Only :wear uses it -- the phone app is Flutter
    // and draws nothing with Compose.
    id("org.jetbrains.kotlin.plugin.compose") version "2.3.20" apply false
}

include(":app")

// The Wear OS companion. A separate APK with the same applicationId as the phone app,
// which is what lets Play deliver it to a paired watch; see wear/build.gradle.kts and
// README.md ("Wear OS Companion").
include(":wear")
