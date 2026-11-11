# kotlinx.serialization
-keepattributes *Annotation*, InnerClasses
-dontnote kotlinx.serialization.**
-keep,includedescriptorclasses class app.vpsrouter.client.**$$serializer { *; }
-keepclassmembers class app.vpsrouter.client.** {
    *** Companion;
}
-keepclasseswithmembers class app.vpsrouter.client.** {
    kotlinx.serialization.KSerializer serializer(...);
}

# JSch грузит алгоритмы по имени класса через рефлексию
-keep class com.jcraft.jsch.** { *; }
-dontwarn com.jcraft.jsch.**
-dontwarn org.ietf.jgss.**
-dontwarn org.newsclub.net.unix.**
-dontwarn com.sun.jna.**
-dontwarn org.apache.logging.log4j.**
-dontwarn org.slf4j.**
-dontwarn org.bouncycastle.**
-dontwarn org.conscrypt.**
-dontwarn org.openjsse.**
