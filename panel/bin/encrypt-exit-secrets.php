<?php
// Разовый бэкфилл: шифрует уже сохранённые в БД wg_peer_psk/wg_local_privkey/
// protocol_params у exit_servers, если они ещё лежат как plaintext (строки,
// созданные до того, как App\Models\ExitServer::create()/update() стали
// шифровать эти поля через App\Secrets). Идемпотентен — читает КАЖДОЕ
// значение напрямую (минуя ExitServer::find(), которая уже расшифровывает),
// пробует Secrets::decrypt() и шифрует только то, что ещё не расшифровалось
// (т.е. ещё не было шифротекстом). Повторный запуск ничего не меняет.
//
// Запуск один раз после деплоя этой версии панели:
//   php /var/www/panel/bin/encrypt-exit-secrets.php

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Secrets;

const FIELDS = ['wg_peer_psk', 'wg_local_privkey', 'protocol_params'];

$pdo = Database::get();
$rows = $pdo->query('SELECT id, wg_peer_psk, wg_local_privkey, protocol_params FROM exit_servers')->fetchAll();

$updated = 0;
foreach ($rows as $row) {
    $changes = [];
    foreach (FIELDS as $field) {
        $value = $row[$field];
        if ($value === null || $value === '') {
            continue; // нечего шифровать (NULL или плейсхолдер wg_local_privkey='')
        }
        $alreadyEncrypted = true;
        try {
            Secrets::decrypt($value);
        } catch (\Throwable) {
            $alreadyEncrypted = false;
        }
        if (!$alreadyEncrypted) {
            $changes[$field] = Secrets::encrypt($value);
        }
    }

    if ($changes) {
        $set = implode(', ', array_map(fn($f) => "$f = :$f", array_keys($changes)));
        $stmt = $pdo->prepare("UPDATE exit_servers SET $set WHERE id = :id");
        $stmt->execute($changes + ['id' => $row['id']]);
        $updated++;
        echo "exit_servers#{$row['id']}: зашифрованы поля [" . implode(', ', array_keys($changes)) . "]\n";
    }
}

echo $updated
    ? "Готово: обновлено строк — $updated.\n"
    : "Готово: все секреты exit_servers уже зашифрованы, изменений не потребовалось.\n";
