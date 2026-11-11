package app.vpsrouter.client.tunnel

import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import org.junit.Assert.assertEquals
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode
import java.io.File

@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34])
class SpeedNotifierTest {
    @Test fun shortFormat() {
        assertEquals("950", SpeedNotifier.short(950))
        assertEquals("12K", SpeedNotifier.short(12_400))
        assertEquals("3.4M", SpeedNotifier.short(3_400_000))
        assertEquals("25M", SpeedNotifier.short(25_000_000))
    }

    /** Значок на тёмном фоне строки состояния — для просмотра в docs/screenshots. */
    @Test fun renderIcon() {
        val icon = SpeedNotifier.iconBitmap(3_400_000, 85_000)
        val out = Bitmap.createBitmap(240, 120, Bitmap.Config.ARGB_8888)
        Canvas(out).apply { drawColor(Color.BLACK); drawBitmap(icon, 72f, 12f, null) }
        File("../docs/screenshots").mkdirs()
        File("../docs/screenshots/09_speed_icon.png").outputStream().use { out.compress(Bitmap.CompressFormat.PNG, 100, it) }
    }
}
