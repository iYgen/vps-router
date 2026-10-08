<?php

namespace Tests;

use App\Http;
use App\HttpResponse;
use PHPUnit\Framework\TestCase;

/**
 * Тесты общего шлюза API (Http::guard используется всеми public/api/*.php):
 * авторизация, допустимые методы, CSRF, разбор тела. В режиме Http::$capture
 * ответы приходят исключением HttpResponse вместо echo+exit.
 */
class HttpGuardTest extends TestCase
{
    protected function setUp(): void
    {
        Http::$capture = true;
        Http::$testBody = null;
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    protected function tearDown(): void
    {
        Http::$capture = false;
        Http::$testBody = null;
        $_SESSION = [];
    }

    private function login(): void
    {
        $_SESSION['user_id'] = 1;
    }

    private function catchResp(callable $fn): HttpResponse
    {
        try {
            $fn();
        } catch (HttpResponse $e) {
            return $e;
        }
        $this->fail('Ожидался HttpResponse');
    }

    public function testUnauthenticatedGives401(): void
    {
        $r = $this->catchResp(fn() => Http::guard(['GET']));
        $this->assertSame(401, $r->status);
        $this->assertSame('unauthenticated', $r->data['error']);
    }

    public function testAllowedGetReturnsMethod(): void
    {
        $this->login();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertSame('GET', Http::guard(['GET', 'POST']));
    }

    public function testMethodNotAllowedGives405(): void
    {
        $this->login();
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $r = $this->catchResp(fn() => Http::guard(['GET']));
        $this->assertSame(405, $r->status);
    }

    public function testPostWithoutCsrfGives403(): void
    {
        $this->login();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $r = $this->catchResp(fn() => Http::guard(['GET', 'POST']));
        $this->assertSame(403, $r->status);
        $this->assertSame('invalid_csrf_token', $r->data['error']);
    }

    public function testPostWithValidCsrfPasses(): void
    {
        $this->login();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf_token'] = 'tok123';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'tok123';
        $this->assertSame('POST', Http::guard(['POST']));
    }

    public function testJsonInputParsesAndRejectsBad(): void
    {
        Http::$testBody = '{"a":1,"b":"x"}';
        $this->assertSame(['a' => 1, 'b' => 'x'], Http::jsonInput());

        Http::$testBody = '{ not json';
        $r = $this->catchResp(fn() => Http::jsonInput());
        $this->assertSame(400, $r->status);
    }
}
