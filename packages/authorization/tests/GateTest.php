<?php

declare(strict_types=1);

namespace Kinetis\Authorization\Tests;

use Kinetis\Authorization\AuthorizationResponse;
use Kinetis\Authorization\Exception\AuthorizationException;
use Kinetis\Authorization\Gate;
use Kinetis\Authorization\Tests\Fixtures\{FakeClaimsUser, FakeCurrentUser, FixtureArticlePolicy, FixturePost, FixturePostPolicy};
use Kinetis\Container\AppScope;
use PHPUnit\Framework\TestCase;

final class GateTest extends TestCase
{
    public function test_allows_is_true_for_a_plain_bool_true_check(): void
    {
        $gate = new Gate();
        $user = new FakeCurrentUser(1);

        self::assertTrue($gate->allows($user, static fn (FakeCurrentUser $u): bool => true));
    }

    public function test_allows_is_false_for_a_plain_bool_false_check(): void
    {
        $gate = new Gate();
        $user = new FakeCurrentUser(1);

        self::assertFalse($gate->allows($user, static fn (FakeCurrentUser $u): bool => false));
    }

    public function test_allows_reflects_an_authorization_response_directly(): void
    {
        $gate = new Gate();
        $user = new FakeCurrentUser(1);

        self::assertFalse($gate->allows($user, static fn (FakeCurrentUser $u): AuthorizationResponse => AuthorizationResponse::deny()));
    }

    public function test_denies_is_the_exact_inverse_of_allows(): void
    {
        $gate = new Gate();
        $user = new FakeCurrentUser(1);

        self::assertFalse($gate->denies($user, static fn (FakeCurrentUser $u): bool => true));
        self::assertTrue($gate->denies($user, static fn (FakeCurrentUser $u): bool => false));
    }

    public function test_authorize_does_nothing_on_an_allowed_check(): void
    {
        $gate = new Gate();
        $user = new FakeCurrentUser(1);

        $gate->authorize($user, static fn (FakeCurrentUser $u): bool => true);

        $this->addToAssertionCount(1); // reaching this line without a throw is the assertion
    }

    public function test_authorize_throws_a_generic_message_for_a_bare_false_denial(): void
    {
        $gate = new Gate();
        $user = new FakeCurrentUser(1);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('This action is unauthorized.');

        $gate->authorize($user, static fn (FakeCurrentUser $u): bool => false);
    }

    public function test_authorize_throws_the_specific_message_from_a_denied_response(): void
    {
        $gate = new Gate();
        $user = new FakeCurrentUser(1);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('This post is locked and cannot be edited.');

        $gate->authorize(
            $user,
            static fn (FakeCurrentUser $u): AuthorizationResponse => AuthorizationResponse::deny('This post is locked and cannot be edited.'),
        );
    }

    public function test_a_policy_methods_first_class_callable_reference_works_directly(): void
    {
        $gate = new Gate();
        $policy = new FixturePostPolicy();
        $author = new FakeCurrentUser(7);
        $post = new FixturePost(authorId: 7);

        self::assertTrue($gate->allows($author, $policy->update(...), $post));
    }

    public function test_a_policy_method_can_deny_with_its_own_specific_reason(): void
    {
        $gate = new Gate();
        $policy = new FixturePostPolicy();
        $author = new FakeCurrentUser(7);
        $lockedPost = new FixturePost(authorId: 7, locked: true);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('This post is locked and cannot be edited.');

        $gate->authorize($author, $policy->update(...), $lockedPost);
    }

    public function test_a_different_user_is_denied_by_the_same_policy_method(): void
    {
        $gate = new Gate();
        $policy = new FixturePostPolicy();
        $someoneElse = new FakeCurrentUser(99);
        $post = new FixturePost(authorId: 7);

        self::assertFalse($gate->allows($someoneElse, $policy->update(...), $post));
    }

    /**
     * A Policy method can type-hint a concrete CurrentUserInterface
     * implementation that carries more than an id (kinetis/auth-jwt's
     * JwtUser, or an app's own richer user) directly — no query needed
     * to read a role/claim already decoded onto that object. Gate's own
     * generic @template TUser is what lets a check typed that narrowly
     * pass static analysis at all.
     */
    public function test_a_check_typed_against_a_richer_user_reads_its_claims_with_no_lookup(): void
    {
        $gate = new Gate();
        $policy = new FixtureArticlePolicy();
        $editor = new FakeClaimsUser(1, ['editor']);
        $viewer = new FakeClaimsUser(2, []);

        self::assertTrue($gate->allows($editor, $policy->publish(...)));
        self::assertFalse($gate->allows($viewer, $policy->publish(...)));
    }

    /**
     * The claim the README and docs both make about needing no explicit
     * binding: nothing registers Gate anywhere, so what resolves it is
     * AppScope's own autowiring of a class with no constructor.
     */
    public function test_gate_resolves_through_plain_autowiring_with_no_explicit_binding(): void
    {
        $app = new AppScope();
        $app->boot();

        self::assertFalse($app->has(Gate::class));
        self::assertInstanceOf(Gate::class, $app->get(Gate::class));
    }

    public function test_multiple_arguments_pass_through_to_the_check_in_order(): void
    {
        $gate = new Gate();
        $user = new FakeCurrentUser(1);
        $received = [];

        $gate->allows($user, static function (FakeCurrentUser $u, string $a, int $b) use (&$received): bool {
            $received = [$a, $b];

            return true;
        }, 'first', 2);

        self::assertSame(['first', 2], $received);
    }
}
