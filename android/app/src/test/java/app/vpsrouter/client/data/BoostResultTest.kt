package app.vpsrouter.client.data

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class BoostResultTest {
    @Test fun parsesOn() {
        val r = BoostResult.parse("vps", true, "[provision] Ускорение включено: BBR + fq на eth0\nNETBOOST enabled=1 cc=bbr qdisc=fq kernel=6.1.0\n")
        assertTrue(r.ok); assertTrue(r.active); assertEquals("fq", r.qdisc)
    }

    @Test fun kernelWithoutBbr() {
        val r = BoostResult.parse("old", false, "[provision] Ядро 3.10 не поддерживает BBR\nNETBOOST enabled=0 cc=cubic qdisc=pfifo_fast kernel=3.10.0")
        assertFalse(r.ok); assertEquals("ядро 3.10.0 не поддерживает BBR", r.error)
    }

    @Test fun garbage() {
        val r = BoostResult.parse("x", false, "bash: tc: command not found")
        assertFalse(r.ok); assertEquals("bash: tc: command not found", r.error)
    }
}
