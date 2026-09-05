<?php

declare(strict_types=1);

namespace Kinetis\Authorization\Tests;

use Kinetis\Authorization\Exception\AuthorizationException;
use Kinetis\Http\Exception\HttpStatusExceptionInterface;
use PHPUnit\Framework\TestCase;

final class AuthorizationExceptionTest extends TestCase
{
    public function test_it_declares_its_status_through_cores_own_interface(): void
    {
        self::assertInstanceOf(HttpStatusExceptionInterface::class, AuthorizationException::denied('nope'));
    }

    public function test_a_denial_declares_a_403(): void
    {
        self::assertSame(403, AuthorizationException::denied('nope')->httpStatus());
    }

    public function test_denied_carries_the_message_verbatim_as_the_client_visible_text(): void
    {
        self::assertSame(
            'This post is locked and cannot be edited.',
            AuthorizationException::denied('This post is locked and cannot be edited.')->getMessage(),
        );
    }
}
