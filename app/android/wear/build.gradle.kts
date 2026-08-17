/// Wear OS companion — build configuration.
///
/// A separate APK from the phone app, but not a separate product: it carries the same
/// `applicationId`, is signed with the same key, and takes its version from the same
/// `pubspec.yaml`. Google Play uses the package name to decide which phone app a watch
/// app belongs to, so anything else here would ship a watch app that installs but never
/// pairs with anything.
///
/// Deliberately NOT a Flutter module. Flutter does not target Wear OS any more usefully
/// than it targets watchOS: no Data Layer plugin, no ambient-mode support, and a 40 MB
/// engine on a device with a 300 mAh battery. This is native Kotlin and Compose for Wear
/// OS, mirroring `app/ios/WatchApp` file for file.
import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
}

// Read straight from pubspec.yaml rather than from local.properties.
//
// local.properties carries flutter.versionName/versionCode, but only as a side effect of
// the last `flutter build` — it is stale on a clean checkout and stale again any time the
// watch is built without the phone app being built first. pubspec.yaml is the one place
// the version is declared, exactly as `app/ios/WatchApp/WatchApp.xcconfig` arranges on the
// other platform, so the two watch apps and the phone app can never disagree about what
// they are.
val pubspecVersion: Pair<String, Int> = run {
    val text = rootProject.file("../pubspec.yaml").readText()
    val m = Regex("""(?m)^version:\s*([0-9]+\.[0-9]+\.[0-9]+)\+([0-9]+)\s*$""").find(text)
        ?: throw GradleException("No `version: x.y.z+n` line in app/pubspec.yaml")
    m.groupValues[1] to m.groupValues[2].toInt()
}

/// Every APK in one Play release needs its own versionCode, and both APKs here come from
/// the same pubspec number. The offset keeps the watch build ordered with the phone build
/// it shipped alongside while never colliding with a future phone build.
val wearVersionCodeOffset = 100_000

val keyProps = Properties().also { props ->
    val f = rootProject.file("key.properties")
    if (f.exists()) f.inputStream().use { props.load(it) }
}

android {
    // Not org.w6sg.aprsmap: the namespace only names the generated R and BuildConfig
    // classes, and giving the two modules the same one makes every `import
    // org.w6sg.aprsmap.R` ambiguous. The applicationId below is the part Play reads.
    namespace = "org.w6sg.aprsmap.wear"
    compileSdk = 36

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        applicationId = "org.w6sg.aprsmap"
        // Wear OS 3. Below this there is no Compose for Wear, no modern Data Layer
        // behaviour and, on the hardware that old, no useful on-device recogniser —
        // and push-to-talk is the whole point of the app.
        minSdk = 30
        targetSdk = 36
        versionCode = pubspecVersion.second + wearVersionCodeOffset
        versionName = pubspecVersion.first
    }

    signingConfigs {
        create("release") {
            keyAlias = keyProps["keyAlias"] as String?
            keyPassword = keyProps["keyPassword"] as String?
            storeFile = keyProps["storeFile"]?.let { rootProject.file(it) }
            storePassword = keyProps["storePassword"] as String?
        }
    }

    buildTypes {
        release {
            // The same reasoning as app/build.gradle.kts, and one degree worse here: a
            // watch APK signed with a different certificate from the phone APK is not
            // just un-updatable, it will not be delivered to the watch at all, because
            // Play matches the companion by package name AND signature.
            signingConfig = signingConfigs.getByName("release")
            // On by default here, unlike the phone app. Unminified, Compose plus
            // play-services-wearable is 22 MB of dex, and this is going onto a watch --
            // where storage is small, the install crosses Bluetooth, and the operator is
            // often doing it in a car park before an event. R8 takes it to a few megabytes.
            // Everything reachable only from the manifest is kept in proguard-rules.pro.
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro",
            )
        }
    }

    buildFeatures {
        compose = true
    }

    kotlin {
        compilerOptions {
            jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
        }
    }
}

dependencies {
    val composeBom = platform("androidx.compose:compose-bom:2025.09.00")
    implementation(composeBom)

    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.activity:activity-compose:1.9.3")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.7")
    implementation("androidx.lifecycle:lifecycle-process:2.8.7")

    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.foundation:foundation")
    implementation("androidx.compose.material:material-icons-core")

    // Wear Material 1, not Material 3 for Wear. M3 for Wear is still moving, and the
    // components this app needs — ScalingLazyColumn, Chip, ToggleChip, TimeText — have
    // been stable here for years.
    implementation("androidx.wear.compose:compose-material:1.4.1")
    implementation("androidx.wear.compose:compose-foundation:1.4.1")
    implementation("androidx.wear.compose:compose-navigation:1.4.1")

    // Ambient mode: the Wear equivalent of a watchOS dimmed always-on display, and the
    // state this app spends most of a net in. See Announcer.kt.
    implementation("androidx.wear:wear:1.3.0")

    // Not used directly, and here anyway. androidx.wear drags in a fragment version old
    // enough that lint refuses to assemble a release at all --
    // InvalidFragmentVersionForActivityResult, raised as a fatal error against the
    // microphone permission request. This app has no Fragment in it, and the activity is a
    // plain ComponentActivity, so the warning is wrong on the facts; naming a current
    // version is a smaller lie than a lint baseline that would also hide real findings.
    implementation("androidx.fragment:fragment:1.8.5")

    // The Data Layer — MessageClient, DataClient, CapabilityClient. The counterpart of
    // WatchConnectivity, and the only supported way to reach a paired phone.
    implementation("com.google.android.gms:play-services-wearable:18.2.0")

    // The token is a bearer credential for a whole net's traffic; on the other platform
    // it lives in the Keychain with ThisDeviceOnly. See TokenStore.kt.
    implementation("androidx.security:security-crypto:1.1.0-alpha06")

    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.9.0")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-play-services:1.9.0")
}
