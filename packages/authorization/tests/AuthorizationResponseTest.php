<?php

declare(strict_types=1);

namespace Kinetis\Authorization\Tests;

use Kinetis\Authorization\AuthorizationResponse;
use PHPUnit\Framework\TestCase;

final class AuthorizationResponseTest extends TestCase
{
    public function test_allow_is_allowed_with_no_message(): void
    {
        $response = AuthorizationResponse::allow();

        self::assertTrue($response->allowed);
        self::assertNull($response->message);
    }

    public function test_deny_carries_the_given_message(): void
    {
        $response = AuthorizationResponse::deny('Only the author can edit this post.');

        self::assertFalse($response->allowed);
        self::assertSame('Only the author can edit this post.', $response->message);
    }

    public function test_deny_defaults_to_a_generic_message(): void
    {
        self::assertSame('This action is unauthorized.', AuthorizationResponse::deny()->message);
    }

    public function test_from_bool_true_matches_allow(): void
    {
        self::assertEquals(AuthorizationResponse::allow(), AuthorizationResponse::fromBool(true));
    }

    public function test_from_bool_false_matches_deny(): void
    {
        self::assertEquals(AuthorizationResponse::deny(), AuthorizationResponse::fromBool(false));
    }
}
