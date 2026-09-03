<?php

declare(strict_types=1);

namespace Kinetis\Tests\Testing;

use InvalidArgumentException;
use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Kinetis\Testing\TestClient;
use Kinetis\Tests\Http\Fixtures\RawRequestController;
use Kinetis\Tests\Http\Fixtures\UploadController;
use Kinetis\Tests\Http\Fixtures\UserController;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\TestCase;

final class TestClientTest extends TestCase
{
    private function client(): TestClient
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $router->register(UserController::class);
        $router->register(RawRequestController::class);
        $router->register(UploadController::class);

        return new TestClient(new Kernel($app, $router));
    }

    public function test_get_dispatches_a_request_and_returns_the_response(): void
    {
        $response = $this->client()->get('/users/42');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['id' => 42], json_decode((string) $response->getBody(), true));
    }

    public function test_get_passes_query_parameters(): void
    {
        $response = $this->client()->get('/users', query: ['page' => 2, 'limit' => 5]);

        self::assertSame(['page' => 2, 'limit' => 5], json_decode((string) $response->getBody(), true));
    }

    public function test_post_sends_a_json_encoded_body(): void
    {
        $response = $this->client()->post('/users', body: ['name' => 'Alon', 'email' => 'alon@example.com']);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            ['name' => 'Alon', 'email' => 'alon@example.com'],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function test_post_sets_a_json_content_type_by_default(): void
    {
        $response = $this->client()->post('/raw-request', body: ['anything' => true]);

        self::assertSame('application/json', json_decode((string) $response->getBody(), true)['contentType']);
    }

    public function test_post_does_not_override_an_explicit_content_type(): void
    {
        $response = $this->client()->post(
            '/raw-request',
            body: ['anything' => true],
            headers: ['Content-Type' => 'application/vnd.custom+json'],
        );

        self::assertSame(
            'application/vnd.custom+json',
            json_decode((string) $response->getBody(), true)['contentType'],
        );
    }

    public function test_patch_sends_a_json_encoded_body(): void
    {
        $response = $this->client()->patch('/users/1/status', body: ['status' => 'active']);

        self::assertSame(['id' => 1, 'status' => 'active'], json_decode((string) $response->getBody(), true));
    }

    public function test_a_failing_validation_returns_422(): void
    {
        $response = $this->client()->post('/users', body: ['name' => 'Al', 'email' => 'not-an-email']);

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_an_unknown_route_returns_404(): void
    {
        $response = $this->client()->get('/does-not-exist');

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * KINETIS-71: an array $body handed to post()/put()/patch()/request()
     * is always JSON — an explicit Content-Type that isn't JSON-shaped
     * would otherwise silently disagree with the bytes actually sent.
     */
    public function test_request_rejects_an_array_body_with_an_incompatible_content_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not JSON-shaped');

        $this->client()->post(
            '/raw-request',
            body: ['anything' => true],
            headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
        );
    }

    public function test_post_form_sends_a_genuinely_form_encoded_body_and_populates_parsed_body(): void
    {
        $response = $this->client()->postForm('/raw-request', ['name' => 'Alon', 'role' => 'admin']);
        $decoded = $response->json();

        self::assertSame('application/x-www-form-urlencoded', $decoded['contentType']);
        self::assertSame(
            'name=Alon&role=admin',
            \base64_decode($decoded['rawBodyBase64'], strict: true),
            'the raw bytes sent must be exactly what http_build_query() produces — no JSON involved.',
        );
        self::assertSame(
            ['name' => 'Alon', 'role' => 'admin'],
            $decoded['parsedBody'],
            'getParsedBody() must return the form data — unlike the JSON shorthand, which leaves it null.',
        );
    }

    public function test_post_form_allows_overriding_the_default_content_type(): void
    {
        $response = $this->client()->postForm(
            '/raw-request',
            ['name' => 'Alon'],
            headers: ['Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8'],
        );

        self::assertSame(
            'application/x-www-form-urlencoded; charset=UTF-8',
            $response->json()['contentType'],
        );
    }

    public function test_put_form_and_patch_form_send_the_same_genuinely_form_encoded_body(): void
    {
        foreach (['putForm', 'patchForm'] as $method) {
            $response = $this->client()->{$method}('/raw-request', ['x' => '1']);

            self::assertSame('x=1', \base64_decode($response->json()['rawBodyBase64'], strict: true));
            self::assertSame(['x' => '1'], $response->json()['parsedBody']);
        }
    }

    public function test_raw_sends_the_exact_string_body_with_no_encoding_and_no_parsed_body(): void
    {
        $response = $this->client()->raw('POST', '/raw-request', 'not json, not form-encoded', [
            'Content-Type' => 'text/plain',
        ]);
        $decoded = $response->json();

        self::assertSame('text/plain', $decoded['contentType']);
        self::assertSame('not json, not form-encoded', \base64_decode($decoded['rawBodyBase64'], strict: true));
        self::assertNull($decoded['parsedBody'], 'a raw body is never treated as parsed form/multipart data.');
    }

    /**
     * The base64 round trip is what actually proves this — a body with
     * real non-UTF-8 bytes would corrupt json_encode() itself if this
     * class (or the fixture) ever embedded it directly as a JSON string.
     */
    public function test_raw_round_trips_arbitrary_binary_bytes_exactly(): void
    {
        $binary = \random_bytes(64);

        $response = $this->client()->raw('POST', '/raw-request', $binary, ['Content-Type' => 'application/octet-stream']);

        self::assertSame($binary, \base64_decode($response->json()['rawBodyBase64'], strict: true));
    }

    /**
     * The direct escape hatch — this class never guesses a multipart
     * boundary from a plain array; the caller builds the real PSR-7
     * request (uploaded file included) and hands it straight to send(),
     * dispatched through the exact same Kernel every other method uses.
     */
    public function test_send_dispatches_a_hand_built_multipart_request_directly(): void
    {
        $avatar = new UploadedFile(Stream::create('fake image bytes'), 17, \UPLOAD_ERR_OK, 'avatar.png', 'image/png');
        $request = new ServerRequest('POST', '/avatars')
            ->withHeader('Content-Type', 'multipart/form-data; boundary=----WebKitFormBoundary')
            ->withParsedBody(['name' => 'Alon'])
            ->withUploadedFiles(['avatar' => $avatar]);

        $response = $this->client()->send($request);

        self::assertSame(
            ['name' => 'Alon', 'filename' => 'avatar.png', 'contents' => 'fake image bytes'],
            $response->json(),
        );
    }

    /**
     * KINETIS-71: getQueryParams() must agree with the URI's own query
     * string component — the same relationship a real incoming request
     * has — rather than being set independently of it. Sent alongside a
     * real JSON body via request() directly (post()'s own shorthand
     * doesn't forward $query), so both are proven from the one request.
     */
    public function test_query_params_agree_with_the_uri_query_string(): void
    {
        $response = $this->client()->request(
            'POST',
            '/raw-request',
            body: ['anything' => true],
            query: ['page' => 2, 'q' => 'a b'],
        );
        $decoded = $response->json();

        self::assertSame('page=2&q=a+b', $decoded['queryString']);
        self::assertSame(['page' => '2', 'q' => 'a b'], $decoded['queryParams']);
    }

    /**
     * KINETIS-71 FEEDBACK: HTTP header names are case-insensitive (RFC
     * 7230) — a lowercase "content-type" key must be recognized exactly
     * like "Content-Type", both for the JSON shorthand's own default
     * and for form()'s override validation.
     */
    public function test_a_lowercase_content_type_header_is_recognized_for_json(): void
    {
        $response = $this->client()->post(
            '/raw-request',
            body: ['anything' => true],
            headers: ['content-type' => 'application/vnd.custom+json'],
        );

        self::assertSame('application/vnd.custom+json', $response->json()['contentType']);
    }

    public function test_a_mixed_case_content_type_header_is_recognized_for_form(): void
    {
        $response = $this->client()->postForm(
            '/raw-request',
            ['x' => '1'],
            headers: ['CONTENT-TYPE' => 'application/x-www-form-urlencoded; charset=UTF-8'],
        );

        self::assertSame(
            'application/x-www-form-urlencoded; charset=UTF-8',
            $response->json()['contentType'],
        );
    }

    /**
     * A "; charset=..." parameter must never cause a legitimate
     * Content-Type to be rejected — only the bare media type before the
     * ";" is what request()/form() actually validate.
     */
    public function test_a_json_content_type_with_a_charset_parameter_is_accepted(): void
    {
        $response = $this->client()->post(
            '/raw-request',
            body: ['anything' => true],
            headers: ['Content-Type' => 'application/json; charset=UTF-8'],
        );

        self::assertSame('application/json; charset=UTF-8', $response->json()['contentType']);
    }

    /**
     * form() must validate its own Content-Type override the same way
     * request() already validates its own — otherwise a caller could
     * declare a JSON (or any other non-form) Content-Type while form()
     * still sends http_build_query() bytes and a form-parsed body,
     * recreating the exact contradictory-request bug this issue exists
     * to close, just relocated to a different method.
     */
    public function test_form_rejects_an_incompatible_content_type_override(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not application/x-www-form-urlencoded');

        $this->client()->postForm(
            '/raw-request',
            ['name' => 'Alon'],
            headers: ['Content-Type' => 'application/json'],
        );
    }

    public function test_form_rejects_a_multipart_content_type_override(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not application/x-www-form-urlencoded');

        $this->client()->postForm(
            '/raw-request',
            ['name' => 'Alon'],
            headers: ['Content-Type' => 'multipart/form-data; boundary=----abc'],
        );
    }

    /**
     * Two differently-cased keys naming the same header with two
     * *different* values describe a genuinely contradictory request —
     * this must never be silently resolved by picking one of them.
     */
    public function test_request_rejects_conflicting_duplicate_content_type_headers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Conflicting Content-Type headers given');

        $this->client()->post(
            '/raw-request',
            body: ['anything' => true],
            headers: ['Content-Type' => 'application/json', 'content-type' => 'text/plain'],
        );
    }

    /**
     * KINETIS-71 FEEDBACK 2: two differently-cased keys with the *same*
     * value are a harmless redundancy, not a contradiction — must not
     * throw. But "not throw" alone isn't proof: Nyholm's own header
     * storage treats two differently-cased keys as one *repeated*
     * header and combines their values into a single comma-joined
     * field, so leaving both in the array handed to it would still
     * corrupt the outgoing Content-Type into "application/json,
     * application/json" even though validation passed cleanly. The
     * fixture's own echo of the *observed* header — not just the
     * status code — is what actually proves the array was collapsed to
     * one entry before the request was built.
     */
    public function test_request_tolerates_redundant_duplicate_content_type_headers_with_the_same_value(): void
    {
        $response = $this->client()->post(
            '/raw-request',
            body: ['anything' => true],
            headers: ['Content-Type' => 'application/json', 'content-type' => 'application/json'],
        );

        self::assertSame('application/json', $response->json()['contentType']);
    }

    /**
     * The identical canonicalization must hold for form mode too, not
     * just the JSON shorthand — form() runs its own, separate call into
     * the same helper.
     */
    public function test_form_tolerates_redundant_duplicate_content_type_headers_with_the_same_value(): void
    {
        $response = $this->client()->postForm(
            '/raw-request',
            ['x' => '1'],
            headers: [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'content-type' => 'application/x-www-form-urlencoded',
            ],
        );

        self::assertSame('application/x-www-form-urlencoded', $response->json()['contentType']);
    }

    /**
     * KINETIS-71 FEEDBACK 2: the duplicate/conflict check must not be
     * bypassable merely because the JSON body array is empty — a
     * bodyless get()/delete() (or request() with no body at all) still
     * routes through request(), which must still canonicalize/reject
     * Content-Type headers rather than skipping the check entirely
     * because there was no body-shape validation to run alongside it.
     */
    public function test_get_rejects_conflicting_duplicate_content_type_headers_despite_no_body(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Conflicting Content-Type headers given');

        $this->client()->get(
            '/raw-request',
            headers: ['Content-Type' => 'application/json', 'content-type' => 'text/plain'],
        );
    }

    public function test_delete_rejects_conflicting_duplicate_content_type_headers_despite_no_body(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Conflicting Content-Type headers given');

        $this->client()->delete(
            '/raw-request',
            headers: ['Content-Type' => 'application/json', 'content-type' => 'text/plain'],
        );
    }

    /**
     * KINETIS-71 FEEDBACK: postForm()'s parsedBody must reflect what
     * actually parsing the emitted http_build_query() bytes produces —
     * not the caller's own original $form array. http_build_query()
     * loses information no wire-format body can carry: every scalar
     * becomes a string, and a null value is omitted entirely.
     */
    public function test_post_form_parsed_body_reflects_the_actual_wire_bytes_not_the_original_array(): void
    {
        $response = $this->client()->postForm('/raw-request', [
            'active' => true,
            'count' => 5,
            'missing' => null,
            'tags' => ['a', 'b'],
        ]);
        $decoded = $response->json();

        self::assertSame(
            'active=1&count=5&tags%5B0%5D=a&tags%5B1%5D=b',
            \base64_decode($decoded['rawBodyBase64'], strict: true),
        );
        self::assertSame(
            ['active' => '1', 'count' => '5', 'tags' => ['a', 'b']],
            $decoded['parsedBody'],
            'every scalar becomes a string and the null-valued key is omitted entirely — '
                . 'exactly what a real request arrives with, not the original typed array.',
        );
    }

    public function test_post_form_encodes_special_characters_before_parsing_them_back(): void
    {
        $response = $this->client()->postForm('/raw-request', ['q' => 'a & b = c?']);
        $decoded = $response->json();

        self::assertSame('q=a+%26+b+%3D+c%3F', \base64_decode($decoded['rawBodyBase64'], strict: true));
        self::assertSame(['q' => 'a & b = c?'], $decoded['parsedBody']);
    }

    /**
     * KINETIS-71 FEEDBACK: a query merged onto a URI that already
     * carries both an existing query string and a #fragment must land
     * *before* the fragment, and the fragment itself must survive
     * untouched — raw string concatenation would instead append the
     * new query text after the fragment, corrupting it and leaving the
     * real query empty.
     */
    public function test_query_merges_before_an_existing_fragment_and_preserves_both(): void
    {
        $response = $this->client()->request(
            'POST',
            '/raw-request?existing=1#section',
            body: ['anything' => true],
            query: ['page' => 2],
        );
        $decoded = $response->json();

        self::assertSame('existing=1&page=2', $decoded['queryString']);
        self::assertSame(['existing' => '1', 'page' => '2'], $decoded['queryParams']);
    }
}
