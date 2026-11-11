// Модуль туннеля AmneziaWG: Java-слой из amneziawg-android (Apache-2.0, см. LICENSE-amneziawg)
// и готовые нативные libwg-go.so из официального релиза AmneziaWG
// (JNI-символы Java_org_amnezia_awg_GoBackend_* — пакет классов менять нельзя).
plugins {
    id("com.android.library")
}

android {
    namespace = "org.amnezia.awg.tunnel"
    compileSdk = 35
    defaultConfig {
        minSdk = 24
        consumerProguardFiles("consumer-rules.pro")
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
        isCoreLibraryDesugaringEnabled = true
    }
    lint {
        disable += setOf("LongLogTag", "NewApi")
    }
}

dependencies {
    implementation("androidx.annotation:annotation:1.9.1")
    implementation("androidx.collection:collection:1.4.5")
    compileOnly("com.google.code.findbugs:jsr305:3.0.2")
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.3")
}
