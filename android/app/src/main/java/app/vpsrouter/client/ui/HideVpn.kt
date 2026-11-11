package app.vpsrouter.client.ui

/**
 * Российские приложения, которые часто проверяют, включён ли VPN (банки, госуслуги,
 * школьные/городские сервисы, маркетплейсы, операторы). Используется только для
 * подсказки в «Скрыть VPN от приложений» — пользователь сам отмечает, что исключить.
 */
object RuApps {
    private val prefixes = listOf(
        // Госуслуги, Москва (МЭШ, Мос.ру, Электронный дневник), налоги
        "ru.gosuslugi", "ru.rtlabs", "ru.mos.", "ru.mos", "ru.nalog", "ru.fns", "ru.pfr", "ru.sfr",
        // Банки
        "ru.sberbank", "ru.sberbankmobile", "ru.vtb24", "ru.vtb", "com.idamob.tinkoff", "ru.tinkoff", "ru.tbank",
        "ru.alfabank", "ru.gazprombank", "ru.raiffeisen", "ru.psbank", "ru.rshb", "ru.openbank", "ru.mtsbank",
        "ru.pochtabank", "ru.sovcombank", "ru.akbars", "ru.uralsib", "ru.rosbank", "ru.bspb", "ru.mkb",
        // Платежи, маркетплейсы, операторы, магазины приложений
        "ru.nspk", "ru.ozon", "com.wildberries", "ru.wildberries", "ru.avito", "com.avito", "ru.yandex.market",
        "ru.mts", "ru.megafon", "ru.beeline", "ru.tele2", "ru.rostelecom", "ru.vk.store", "ru.rustore",
    )
    private val keywords = listOf(
        "мэш", "госуслуги", "мос.ру", "дневник", "сбер", "втб", "т-банк", "тинькофф", "альфа", "газпромбанк",
        "райффайзен", "псб", "россельхоз", "почта банк", "совкомбанк", "мир pay", "сбп", "налог", "rustore",
    )

    fun isLikely(pkg: String, label: String): Boolean {
        val p = pkg.lowercase(); val l = label.lowercase()
        return prefixes.any { p == it.trimEnd('.') || p.startsWith(it.trimEnd('.') + ".") || p.startsWith(it) && it.endsWith('.') } ||
            keywords.any { l.contains(it) }
    }
}
