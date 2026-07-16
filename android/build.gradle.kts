allprojects {
    repositories {
        google()
        mavenCentral()
    }
}

val newBuildDir: Directory =
    rootProject.layout.buildDirectory
        .dir("../../build")
        .get()
rootProject.layout.buildDirectory.value(newBuildDir)

subprojects {
    val newSubprojectBuildDir: Directory = newBuildDir.dir(project.name)
    project.layout.buildDirectory.value(newSubprojectBuildDir)
}
subprojects {
    // Force all plugins to compile against SDK 36. Some transitive plugins
    // (e.g. flutter_rotation_sensor -> native_device_orientation) require
    // compileSdk 36 while defaulting to 35. Only affects the compile SDK,
    // not minSdk/targetSdk, so runtime behavior is unchanged.
    // Registered before evaluationDependsOn so afterEvaluate is not attached
    // to an already-evaluated project.
    afterEvaluate {
        val androidExt = project.extensions.findByName("android")
        if (androidExt is com.android.build.gradle.BaseExtension) {
            androidExt.compileSdkVersion(36)
        }
    }
    project.evaluationDependsOn(":app")
}

tasks.register<Delete>("clean") {
    delete(rootProject.layout.buildDirectory)
}
