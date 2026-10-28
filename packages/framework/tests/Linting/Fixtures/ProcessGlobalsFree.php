<?php

declare(strict_types=1);

namespace App;

function header(string $line): void
{
}

function session_token(): string
{
    return '';
}

final class Foo
{
    public mixed $_SERVER = null;

    public static function header(): void
    {
    }

    public function processGlobalsFree(object $response): void
    {
        header('X-A: 1');
        $response->header('X-A');
        Foo::header();
        $lowercase = $_get ?? null;
        $property = $this->_SERVER;
        $dynamic = ${'_' . 'GET'} ?? null;
        $environment = $_ENV;
        $globals = $GLOBALS;
        getenv('APP_ENV');
        flush();
        headers_sent();
        ob_start();
        echo 'body';
        function_exists('session_start');
        session_token();
        \App\session_token();
    }
}
