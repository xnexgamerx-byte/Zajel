import java.util.Base64
import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

/**
 * اسم التطبيق على الهاتف من ملف إعداد الشركة (APP_LABEL في config/<company>.json، docs/plan/96): لكلّ شركةٍ تطبيقٌ
 * باسمها. يصل من --dart-define-from-file كما يصل غيره، وبلاه «التاجر».
 */
val dartDefines: Map<String, String> =
    (project.findProperty("dart-defines") as String?)
        ?.split(",")
        ?.filter { it.isNotBlank() }
        ?.mapNotNull { encoded ->
            String(Base64.getDecoder().decode(encoded), Charsets.UTF_8)
                .split("=", limit = 2)
                .takeIf { it.size == 2 }
                ?.let { it[0] to it[1] }
        }?.toMap() ?: emptyMap()

/**
 * مفتاح رفع التطبيق إلى المتجر (docs/plan/97): من android/key.properties على الجهاز، أو من متغيّرات البيئة في
 * GitHub (أسرار المستودع). وبلاه يبقى توقيع التجربة، فيُبنى التطبيق للتجربة كما كان.
 */
val keyProps = Properties().apply {
    val file = rootProject.file("key.properties")
    if (file.exists()) file.inputStream().use { load(it) }
}
fun signingValue(key: String, env: String): String? =
    (keyProps.getProperty(key) ?: System.getenv(env))?.takeIf { it.isNotBlank() }

val uploadKeyFile = signingValue("storeFile", "ANDROID_KEYSTORE_PATH")?.let { rootProject.file(it) }?.takeIf { it.exists() }

android {
    namespace = "iq.wahaj.merchant_app"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // معرّف التطبيق على المتجر من ملف إعداد الشركة (APP_ID في config/<company>.json، docs/plan/97): لكلّ شركةٍ
        // معرّفها. لا يتغيّر بعد أوّل رفعٍ إلى المتجر. وبلاه المعرّف القديم، لبناءات التجربة.
        applicationId = dartDefines["APP_ID"]?.takeIf { it.isNotBlank() } ?: "iq.wahaj.merchant_app"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        // Uses the version code from pubspec.yaml. When using split APKs, 1000 * ABI_VERSION
        // is added automatically by Flutter. (https://developer.android.com/studio/build/configure-apk-splits#configure-APK-versions)
        // You can force using the value of versionCode by specifying the `-P force-version-code-ignoring-abi=true`
        // flag during build.
        versionCode = flutter.versionCode
        versionName = flutter.versionName
        manifestPlaceholders["appLabel"] = dartDefines["APP_LABEL"]?.takeIf { it.isNotBlank() } ?: "التاجر"
    }

    signingConfigs {
        if (uploadKeyFile != null) {
            create("upload") {
                storeFile = uploadKeyFile
                storePassword = signingValue("storePassword", "ANDROID_KEYSTORE_PASSWORD")
                keyAlias = signingValue("keyAlias", "ANDROID_KEY_ALIAS")
                keyPassword = signingValue("keyPassword", "ANDROID_KEY_PASSWORD") ?: signingValue("storePassword", "ANDROID_KEYSTORE_PASSWORD")
            }
        }
    }

    buildTypes {
        release {
            // بمفتاح الرفع إن وُجد، وإلّا توقيع التجربة فيعمل `flutter run --release`
            signingConfig = signingConfigs.findByName("upload") ?: signingConfigs.getByName("debug")
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
