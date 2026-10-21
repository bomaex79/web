import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// Optional: eigener Signierschlüssel. Datei keystore.properties im Projektordner anlegen (siehe Anleitung).
val ksFile = rootProject.file("keystore.properties")
val ksProps = Properties()
if (ksFile.exists()) ksFile.inputStream().use { ksProps.load(it) }

android {
    namespace = "de.bomaex.browser"
    compileSdk = 34

    defaultConfig {
        applicationId = "de.bomaex.browser"
        minSdk = 26
        targetSdk = 34
        versionCode = 1
        versionName = "1.0"
    }

    signingConfigs {
        create("bm") {
            if (ksFile.exists()) {
                storeFile = rootProject.file(ksProps.getProperty("storeFile"))
                storePassword = ksProps.getProperty("storePassword")
                keyAlias = ksProps.getProperty("keyAlias")
                keyPassword = ksProps.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            signingConfig = if (ksFile.exists()) signingConfigs.getByName("bm") else signingConfigs.getByName("debug")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
}
