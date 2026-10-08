<?php

namespace App;

/**
 * Тонкая обёртка над встроенным mail() — локальный exim на сервере уже
 * принимает почту (тот же, что использует ISPmanager), поэтому внешний
 * SMTP/новые зависимости не нужны. Домен отправителя берём из хоста
 * текущего запроса ($_SERVER['HTTP_HOST']) — доступен всегда в тех местах,
 * где реально отправляется письмо (форма "забыли пароль", веб-запрос).
 */
class Mailer
{
    public static function send(string $to, string $subject, string $body): bool
    {
        $from = self::fromAddress();
        // Защита от инъекций заголовков/команд SMTP.
        if (preg_match('/[\r\n]/', $to) || preg_match('/[\r\n]/', $from) || preg_match('/[\r\n]/', $subject)) {
            return false;
        }

        // Предпочитаем прямую сдачу на локальный exim по SMTP (127.0.0.1:25,
        // он в relay_from_hosts): envelope-from (MAIL FROM) ставится ЯВНО = $from
        // и его НЕ перезаписывает локальный пользователь (в отличие от sendmail -f
        // у недоверенного php-fpm-юзера, из-за чего конверт становился panel@<host>
        // и получатель отклонял письмо «sender verification failed»). Это и чинит
        // доставку. При недоступности SMTP — откат на mail().
        if (self::smtpSend($from, $to, $subject, $body)) {
            return true;
        }

        $headers = implode("\r\n", [
            'From: VPS Router <' . $from . '>',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ]);
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        return @mail($to, $encodedSubject, $body, $headers, '-f ' . escapeshellarg($from));
    }

    /** Локальный SMTP-хост:порт для сдачи почты (по умолчанию 127.0.0.1:25). */
    private static function smtpTarget(): string
    {
        $t = trim((string) \App\Models\Setting::get('mail_smtp', ''));
        return $t !== '' ? $t : '127.0.0.1:25';
    }

    /**
     * Минимальный SMTP-клиент к локальному MTA. Без AUTH/TLS — только localhost,
     * которому exim разрешает relay. Возвращает true при принятии (250 после «.»).
     */
    private static function smtpSend(string $from, string $to, string $subject, string $body): bool
    {
        $target = self::smtpTarget();
        [$host, $port] = array_pad(explode(':', $target, 2), 2, '25');
        $fp = @fsockopen($host, (int) $port, $errno, $errstr, 8);
        if (!$fp) {
            return false;
        }
        stream_set_timeout($fp, 12);

        $read = function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                // Последняя строка ответа: «NNN <пробел>...» (без дефиса).
                if (strlen($line) >= 4 && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $expect = function (string $resp, string $code): bool {
            return str_starts_with(ltrim($resp), $code);
        };

        $ok = true;
        try {
            if (!$expect($read(), '220')) { $ok = false; }
            $ehloHost = gethostname() ?: 'localhost';
            if ($ok) { fwrite($fp, "EHLO {$ehloHost}\r\n"); if (!$expect($read(), '250')) { $ok = false; } }
            if ($ok) { fwrite($fp, "MAIL FROM:<{$from}>\r\n"); if (!$expect($read(), '250')) { $ok = false; } }
            if ($ok) { fwrite($fp, "RCPT TO:<{$to}>\r\n"); $r = $read(); if (!$expect($r, '250') && !$expect($r, '251')) { $ok = false; } }
            if ($ok) { fwrite($fp, "DATA\r\n"); if (!$expect($read(), '354')) { $ok = false; } }
            if ($ok) {
                $headers = "From: VPS Router <{$from}>\r\n"
                    . "To: <{$to}>\r\n"
                    . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
                    . 'Date: ' . date('r') . "\r\n"
                    . "MIME-Version: 1.0\r\n"
                    . "Content-Type: text/plain; charset=UTF-8\r\n"
                    . "Content-Transfer-Encoding: 8bit\r\n";
                // Нормализуем переводы строк и dot-stuffing (строки с ведущей точкой).
                $msg = preg_replace('/\r\n|\r|\n/', "\r\n", $body);
                $msg = preg_replace('/^\./m', '..', $msg);
                fwrite($fp, $headers . "\r\n" . $msg . "\r\n.\r\n");
                if (!$expect($read(), '250')) { $ok = false; }
            }
            fwrite($fp, "QUIT\r\n");
        } catch (\Throwable $e) {
            $ok = false;
        }
        fclose($fp);
        return $ok;
    }

    /**
     * Адрес отправителя. Приоритет — Setting 'mail_from' (например
     * noreply.com: домен должен быть в SPF и, желательно, с DKIM). Иначе —
     * noreply@<хост>: из HTTP_HOST в вебе или hostname сервера в CRON.
     */
    public static function fromAddress(): string
    {
        $cfg = trim((string) \App\Models\Setting::get('mail_from', ''));
        if ($cfg !== '' && filter_var($cfg, FILTER_VALIDATE_EMAIL)) {
            return $cfg;
        }
        $host = $_SERVER['HTTP_HOST'] ?? (gethostname() ?: 'localhost');
        $host = preg_replace('/:\d+$/', '', $host);
        return 'noreply@' . $host;
    }
}
