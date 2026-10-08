<?php
// Создание/сброс пароля первого администратора.
// Запуск: php bin/create-admin.php <username>
// Пароль запросит интерактивно (не через argv, чтобы не светить в истории shell).

require __DIR__ . '/../src/bootstrap.php';

use App\Database;

$username = $argv[1] ?? null;
if (!$username) {
    fwrite(STDERR, "Usage: php bin/create-admin.php <username>\n");
    exit(1);
}

// Неинтерактивный режим (мастер установки): пароль через env, чтобы не
// светить в argv/истории shell. Иначе — интерактивный запрос как раньше.
$envPassword = getenv('PANEL_ADMIN_PASSWORD');
if ($envPassword !== false && $envPassword !== '') {
    $password = $envPassword;
} else {
    $isTty = stream_isatty(STDIN);
    fwrite(STDOUT, "Пароль для $username: ");
    if ($isTty) {
        system('stty -echo');
    }
    $password = trim(fgets(STDIN));
    if ($isTty) {
        system('stty echo');
    }
    fwrite(STDOUT, "\n");
}

if (strlen($password) < 10) {
    fwrite(STDERR, "Пароль должен быть не короче 10 символов\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$pdo = Database::get();
// SQLite до 3.24 (частый случай на старых системных сборках PHP, напр.
// Remi php82 на CentOS 7) не понимает ON CONFLICT...DO UPDATE — делаем
// portable-вариант через явную проверку существования.
$exists = $pdo->prepare('SELECT id FROM users WHERE username = :u');
$exists->execute(['u' => $username]);
if ($exists->fetchColumn()) {
    $stmt = $pdo->prepare('UPDATE users SET password_hash = :h WHERE username = :u');
} else {
    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (:u, :h)');
}
$stmt->execute(['u' => $username, 'h' => $hash]);

echo "OK: пользователь $username сохранён.\n";
