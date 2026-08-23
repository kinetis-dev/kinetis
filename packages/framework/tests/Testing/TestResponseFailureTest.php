<?php

declare(strict_types=1);

namespace Kinetis\Tests\Testing;

use Kinetis\Testing\TestResponse;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * The other direction from TestResponseTest, which checks that each
 * assertion passes when it should. An assertion that cannot fail is
 * worse than no assertion, because a consumer's suite built on it
 * reports success it never earned — so each one here is given a response
 * it must reject.
 */
final class TestResponseFailureTest extends TestCase
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function json(array $body, int $status = 200, array $headers = []): TestResponse
    {
        return new TestResponse(new Response(
            $status,
            ['Content-Type' => 'application/json'] + $headers,
            Stream::create(json_encode($body, JSON_THROW_ON_ERROR)),
        ));
    }

    private function assertFails(callable $assertion): void
    {
        try {
            $assertion();
        } catch (AssertionFailedError) {
            return;
        }

        self::fail('the assertion passed when it should have failed');
    }

    public function test_status_assertions_reject_the_wrong_status(): void
    {
        $this->assertFails(fn () => $this->json([], 200)->assertStatus(201));
        $this->assertFails(fn () => $this->json([], 500)->assertOk());
        $this->assertFails(fn () => $this->json([], 200)->assertCreated());
        $this->assertFails(fn () => $this->json([], 200)->assertNotFound());
        $this->assertFails(fn () => $this->json([], 500)->assertSuccessful());
    }

    public function test_status_assertions_accept_the_right_status(): void
    {
        $this->json([], 201)->assertCreated()->assertSuccessful();
        $this->json([], 404)->assertNotFound();
    }

    public function test_header_assertions_reject_a_missing_or_different_value(): void
    {
        $response = $this->json([], 200, ['X-Trace' => 'abc']);

        $this->assertFails(fn () => $response->assertHeader('X-Absent'));
        $this->assertFails(fn () => $response->assertHeader('X-Trace', 'wrong'));
    }

    public function test_json_assertions_reject_a_body_that_does_not_match(): void
    {
        $response = $this->json(['name' => 'Alon', 'org' => ['id' => 7]]);

        $this->assertFails(fn () => $response->assertJson(['name' => 'someone else']));
        $this->assertFails(fn () => $response->assertJsonPath('name', 'someone else'));
        $this->assertFails(fn () => $response->assertJsonPath('org.missing', 'x'));
        $this->assertFails(fn () => $response->assertJsonPathMissing('name'));
    }

    public function test_json_path_missing_accepts_an_absent_path(): void
    {
        $this->json(['org' => ['id' => 7]])->assertJsonPathMissing('org.absent');
    }

    /**
     * A list index is a path segment like any other — PHP casts the
     * numeric string key, so `items.1.id` resolves.
     */
    public function test_a_json_path_can_index_into_a_list(): void
    {
        $this->json(['items' => [['id' => 1], ['id' => 2]]])->assertJsonPath('items.1.id', 2);
    }

    public function test_validation_error_assertion_rejects_a_successful_response(): void
    {
        $this->assertFails(
            fn () => $this->json(['errors' => ['email' => 'x']], 200)->assertValidationError('email'),
        );
    }

    public function test_body_assertion_rejects_absent_text(): void
    {
        $response = new TestResponse(new Response(200, [], Stream::create('<h1>Welcome</h1>')));

        $response->assertBodyContains('Welcome');
        $this->assertFails(fn () => $response->assertBodyContains('Goodbye'));
    }

    /**
     * It stands in for the response everywhere, so code that took a plain
     * PSR-7 response keeps working — including the mutators, which must
     * return a TestResponse rather than the wrapped response.
     */
    public function test_the_psr7_mutators_are_delegated_and_stay_assertable(): void
    {
        $response = $this->json(['a' => 1], 201, ['X-Trace' => 'abc']);

        self::assertSame('Created', $response->getReasonPhrase());
        self::assertSame('1.1', $response->getProtocolVersion());
        self::assertSame(['abc'], $response->getHeader('X-Trace'));
        self::assertArrayHasKey('X-Trace', $response->getHeaders());

        $response->withStatus(500)->assertStatus(500);
        self::assertSame('2', $response->withProtocolVersion('2')->getProtocolVersion());
        $response->withHeader('X-New', 'v')->assertHeader('X-New', 'v');
        self::assertSame('abc, more', $response->withAddedHeader('X-Trace', 'more')->getHeaderLine('X-Trace'));
        self::assertFalse($response->withoutHeader('X-Trace')->hasHeader('X-Trace'));
        $response->withBody(Stream::create('replaced'))->assertBodyContains('replaced');
    }

    public function test_json_returns_the_decoded_body(): void
    {
        self::assertSame(['a' => 1], $this->json(['a' => 1])->json());
    }
}
