import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

val keyProps = Properties().also { props ->
    val f = rootProject.file("key.properties")
    if (f.exists()) f.inputStream().use { props.load(it) }
}

android {
    namespace = "org.w6sg.aprsmap"
    compileSdk = 36
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
        isCoreLibraryDesugaringEnabled = true
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "org.w6sg.aprsmap"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        create("release") {
            keyAlias = keyProps["keyAlias"] as String?
            keyPassword = keyProps["keyPassword"] as String?
            storeFile = keyProps["storeFile"]?.let { file(it) }
            storePassword = keyProps["storePassword"] as String?
        }
    }

    buildTypes {
        release {
            // Always the release config, never a fallback. This used to drop to debug
            // signing when key.properties was absent, which produced an APK that looked
            // correct in every observable way -- same log, same size, same version -- and
            // differed only in a certificate nobody reads. The failure surfaced later as
            // INSTALL_FAILED_UPDATE_INCOMPATIBLE on a user's phone, forcing an uninstall
            // that costs them their tracker token and registration. A release that cannot
            // be signed with the real key should not be produced at all.
            signingConfig = signingConfigs.getByName("release")
        }
    }
}

// Checked when the task graph is known rather than at configuration time, so debug
// builds and tooling on a machine without the keystore still work -- only assembling a
// release demands it. Without this the missing-key error arrives from deep inside AGP
// as a null storeFile, which says nothing about what to do next.
gradle.taskGraph.whenReady {
    if (!allTasks.any { it.name.contains("Release") }) return@whenReady
    val missing = listOf("storeFile", "storePassword", "keyAlias", "keyPassword")
        .filter { (keyProps[it] as String?).isNullOrBlank() }
    val store = (keyProps["storeFile"] as String?)?.let { file(it) }
    val problem = when {
        missing.isNotEmpty() -> "android/key.properties is missing or incomplete (no ${missing.joinToString(", ")})"
        store?.exists() != true -> "the keystore at ${store?.path} does not exist"
        else -> null
    } ?: return@whenReady
    throw GradleException(
        """
        |Refusing to build a release: $problem
        |
        |Signing with the debug key instead would produce an APK that installs fine on a
        |clean device and is REJECTED as an update for every existing user, who would have
        |to uninstall -- losing their tracker token and registration -- to take it.
        |
        |The canonical copy lives in the old repo, which predates the merge into marsaprs:
        |    cp ~/aprs-map/android/key.properties app/android/key.properties
        |
        |It needs storeFile, storePassword, keyAlias and keyPassword. The keystore is
        |~/aprs-map-release.jks, alias aprs-map. Verify the result before publishing:
        |    apksigner verify --print-certs <apk> | grep SHA-256
        |    expected 0d30a9f258e1330cb22a092ca6df65af5d3bf085905fb9bc01d74e54c866da57
        """.trimMargin()
    )
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

dependencies {
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")
    // The Data Layer, for the Wear OS companion in :wear. This is the phone's half of the
    // link -- WatchConnectivity's counterpart -- and it lives in the phone APK because that
    // is the app a watch pairs with. See org.w6sg.aprsmap.watch.WatchBridge.
    implementation("com.google.android.gms:play-services-wearable:18.2.0")
}

flutter {
    source = "../.."
}
