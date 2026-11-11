import java.security.MessageDigest
import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
    id("org.jetbrains.kotlin.plugin.serialization")
}

android {
    namespace = "app.vpsrouter.client"
    compileSdk = 35

    defaultConfig {
        applicationId = "app.vpsrouter.client"
        minSdk = 24
        targetSdk = 35
        // Версия приложения. При каждом выпуске увеличивайте versionCode — по нему
        // приложение и панель понимают, что есть обновление.
        versionCode = 2
        versionName = "0.2.0"
    }

    // Ключ подписи релизов: .secrets/android/keystore.properties в корне репозитория
    // (каталог в .gitignore). Без него — debug-ключ.
    // Обновления ставятся поверх только при подписи ТЕМ ЖЕ ключом — храните его надёжно.
    val ksFile = rootProject.file("../.secrets/android/keystore.properties")
    if (ksFile.exists()) {
        val ks = Properties().apply { ksFile.inputStream().use { load(it) } }
        signingConfigs.create("release") {
            storeFile = File(ksFile.parentFile, ks.getProperty("storeFile"))
            storePassword = ks.getProperty("storePassword")
            keyAlias = ks.getProperty("keyAlias")
            keyPassword = ks.getProperty("keyPassword")
        }
    }

    sourceSets["main"].assets.srcDir(layout.buildDirectory.dir("generated/provision-assets"))

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            signingConfig = signingConfigs.findByName("release") ?: signingConfigs.getByName("debug")
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
        isCoreLibraryDesugaringEnabled = true
    }
    kotlinOptions {
        jvmTarget = "17"
    }
    buildFeatures {
        compose = true
        buildConfig = true
    }
    testOptions {
        unitTests {
            isIncludeAndroidResources = true
            all {
                it.systemProperty("roborazzi.test.record", "true")
                it.maxHeapSize = "3g"
            }
        }
    }
    packaging {
        jniLibs {
            useLegacyPackaging = true
        }
        resources {
            excludes += setOf("META-INF/*.md", "META-INF/versions/9/OSGI-INF/MANIFEST.MF")
        }
    }
}

dependencies {
    implementation(project(":tunnel"))

    val composeBom = platform("androidx.compose:compose-bom:2024.12.01")
    implementation(composeBom)
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-extended")
    implementation("androidx.compose.ui:ui-tooling-preview")
    debugImplementation("androidx.compose.ui:ui-tooling")

    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.activity:activity-compose:1.9.3")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.7")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.8.7")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.8.7")
    implementation("androidx.navigation:navigation-compose:2.8.5")

    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.9.0")
    implementation("org.jetbrains.kotlinx:kotlinx-serialization-json:1.7.3")

    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("com.github.mwiede:jsch:0.2.21")
    implementation("com.google.zxing:core:3.5.3")
    implementation("com.journeyapps:zxing-android-embedded:4.3.0")

    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.3")

    // Скриншот-тесты интерфейса (Robolectric + Roborazzi) — рендер экранов без эмулятора
    testImplementation("junit:junit:4.13.2")
    testImplementation("org.robolectric:robolectric:4.14.1")
    testImplementation("io.github.takahirom.roborazzi:roborazzi:1.40.1")
    testImplementation("io.github.takahirom.roborazzi:roborazzi-compose:1.40.1")
    testImplementation("androidx.compose.ui:ui-test-junit4")
    debugImplementation("androidx.compose.ui:ui-test-manifest")
}

// Скрипты установки берутся прямо из deploy/provision этого же репозитория — приложение
// и панель всегда используют одну и ту же версию _lib.sh и relay-forward.sh.
val syncProvisionScripts by tasks.registering(Copy::class) {
    from(rootProject.file("../deploy/provision")) { include("_lib.sh", "relay-forward.sh") }
    into(layout.buildDirectory.dir("generated/provision-assets/provision"))
}
tasks.named("preBuild") { dependsOn(syncProvisionScripts) }

// ./gradlew publishToPanel — собрать release и положить APK в panel/app/: панель раздаёт его
// на странице «Приложение», а установленные приложения получают его как обновление.
// Самообновление панели (SelfUpdater) приносит каталог app/ вместе с кодом.
tasks.register("publishToPanel") {
    dependsOn("assembleRelease")
    doLast {
        val apk = layout.buildDirectory.file("outputs/apk/release/app-release.apk").get().asFile
        check(apk.exists()) { "Нет $apk" }
        val outDir = rootProject.file("../panel/app").apply { mkdirs() }
        val target = File(outDir, "vps-router.apk")
        apk.copyTo(target, overwrite = true)
        val sha = MessageDigest.getInstance("SHA-256").digest(target.readBytes()).joinToString("") { "%02x".format(it) }
        val v = android.defaultConfig
        File(outDir, "version.json").writeText(
            """{"versionCode": ${v.versionCode}, "versionName": "${v.versionName}", "size": ${target.length()}, "sha256": "$sha", "minSdk": ${v.minSdk}}""" + "\n"
        )
        println("APK ${v.versionName} (${v.versionCode}) -> $target, sha256 $sha")
    }
}
