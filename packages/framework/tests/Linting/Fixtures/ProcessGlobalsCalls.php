<?php

declare(strict_types=1);

namespace Kinetis\Tests\Linting\Fixtures;

final class ProcessGlobalsCalls
{
    public function superglobals(): void
    {
        $page = $_GET['page'];
        $body = $_POST;
        $cookies = $_COOKIE;
        $files = $_FILES;
        $input = $_REQUEST;
        $_SESSION['a'] = 1;
        $host = $_SERVER['X'] ?? null;
        $hasQuery = isset($_GET['x']);
    }

    public function sapiCalls(): void
    {
        \header('X-A: 1');
        header('X-B: 2');
        header_remove('X-A');
        http_response_code(204);
        setcookie('a', 'b');
        setrawcookie('a', 'b');
        session_start();
        \session_regenerate_id();
    }

    public function exits(): void
    {
        exit;
    }

    public function exitsWithStatus(): void
    {
        exit(1);
    }

    public function dies(): void
    {
        die;
    }

    public function exitsFullyQualified(): void
    {
        \exit(0);
    }
}
