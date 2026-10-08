<?php

namespace App;

/**
 * Носитель HTTP-ответа для РЕЖИМА ТЕСТОВ: когда включён Http::$capture, методы
 * Http::json()/error() (и JSON-гварды авторизации) бросают это исключение вместо
 * `echo + exit`. Тесты ловят его и проверяют статус/тело эндпоинта. В проде
 * ($capture=false) поведение прежнее — реальный вывод и exit.
 */
class HttpResponse extends \RuntimeException
{
    /** @param array<string,mixed> $data */
    public function __construct(public int $status, public array $data)
    {
        parent::__construct('HTTP ' . $status);
    }
}
