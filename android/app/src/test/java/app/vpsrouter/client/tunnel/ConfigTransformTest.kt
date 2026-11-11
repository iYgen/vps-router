package app.vpsrouter.client.tunnel

import app.vpsrouter.client.data.AppSettings
import app.vpsrouter.client.data.RouteMode
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class ConfigTransformTest {
    private val conf = "[Interface]\nPrivateKey = x\nAddress = 10.0.0.2/32\nIncludedApplications = com.a\n\n[Peer]\nPublicKey = y\nAllowedIPs = 0.0.0.0/0\nEndpoint = 1.2.3.4:5\n"

    @Test fun hiddenAppsExcludedInAllMode() {
        val out = ConfigTransform.apply(conf, AppSettings(hiddenApps = listOf("ru.mos.mesh", "ru.sberbankmobile")))
        assertTrue(out.contains("ExcludedApplications = ru.mos.mesh, ru.sberbankmobile"))
        // свой Included из конфига убран — оба сразу AWG не принимает
        assertFalse(out.contains("IncludedApplications"))
        assertTrue(out.indexOf("ExcludedApplications") < out.indexOf("[Peer]"))
    }

    @Test fun hiddenMergedIntoExcludeMode() {
        val s = AppSettings(routeMode = RouteMode.EXCLUDE_APPS, apps = listOf("com.t"), hiddenApps = listOf("ru.mos.mesh", "com.t"))
        assertEquals(emptyList<String>() to listOf("com.t", "ru.mos.mesh"), ConfigTransform.appLists(s))
    }

    @Test fun hiddenRemovedFromOnlyMode() {
        val s = AppSettings(routeMode = RouteMode.ONLY_APPS, apps = listOf("com.chrome", "ru.mos.mesh"), hiddenApps = listOf("ru.mos.mesh"))
        assertEquals(listOf("com.chrome") to emptyList<String>(), ConfigTransform.appLists(s))
    }

    @Test fun nothingHiddenKeepsConfigAsIs() {
        assertTrue(ConfigTransform.apply(conf, AppSettings()).contains("IncludedApplications = com.a"))
    }
}
