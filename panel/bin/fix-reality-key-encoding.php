<?php
// Разовый фикс: reality_private_key/reality_public_key были сохранены в
// обычном base64 (+/=), но Reality-клиенты (Xray-core и производные)
// ожидают base64 URL-safe без padding (см. App\RealityKeys) — строгие
// клиенты отклоняли ссылку с ошибкой вида `invalid "password": ...`.
// Перекодирует УЖЕ СОХРАНЁННЫЕ ключи в правильный алфавит — те же байты,
// тот же секретный ключ, меняется только текстовое представление.
// Идемпотентен: normalizeBase64Url() на уже url-safe строке не меняет её.
//
// Запуск один раз после деплоя этой версии панели:
//   php /var/www/panel/bin/fix-reality-key-encoding.php

require __DIR__ . '/../src/bootstrap.php';

use App\Models\Setting;
use App\RealityKeys;

foreach (['reality_private_key', 'reality_public_key'] as $key) {
    $value = Setting::get($key);
    if (!$value) {
        echo "$key: не задан, пропущено.\n";
        continue;
    }
    $fixed = RealityKeys::normalizeBase64Url($value);
    if ($fixed === $value) {
        echo "$key: уже в правильном формате.\n";
        continue;
    }
    Setting::set($key, $fixed);
    echo "$key: перекодировано ($value -> $fixed).\n";
}

echo "Готово. Если менялось хотя бы одно значение — нужно применить конфиг (Настройки -> Сохранить и применить, или Review & Apply), чтобы sing-box перечитал ключ, и переоткрыть/переслать QR-ссылки устройств заново.\n";
