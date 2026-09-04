<?php

declare(strict_types=1);

namespace Kinetis\Testing\Runtime;

use Kinetis\Runtime\RuntimeAdapterInterface;
use PHPUnit\Framework\TestCase;

/**
 * The behaviors every runtime adapter has to agree on, expressed once and
 * run against each adapter through its own {@see RuntimeAdapterDriver}.
 * Superglobals and a Lambda event build equivalent PSR-7 requests through
 * entirely different code; this is what "equivalent" means, checked.
 *
 *     final class LambdaConformanceTest extends RuntimeAdapterConformanceTestCase
 *     {
 *         protected function driver(): RuntimeAdapterDriver
 *         {
 *             return new LambdaDriver();
 *         }
 *     }
 *
 * Scope rule: only behavior every environment can exhibit belongs here.
 * An input only one environment can produce — a base64-flagged event
 * body, an absent client IP, an event that fails shape validation — is
 * that adapter's own test to write — held to the same contract through
 * the public assertion helpers here. The byte-size cap on a request body
 * is the Kernel's (`MaxBodySizeMiddleware`), identical across adapters
 * and tested there; what this suite checks is what the adapter owes it
 * (the declared `Content-Length`, delivered; a large body, whole) and
 * that the adapter's own parse-failure path is clean.
 *
 * Facts the environment decides (the client IP it reports, whether it
 * can stream, what it cannot parse) come from the driver and are
 * asserted against, so every method here runs on every adapter — none
 * is skipped for an environment that "doesn't do" something.
 */
abstract class RuntimeAdapterConformanceTestCase extends TestCase
{
    abstract protected function driver(): RuntimeAdapterDriver;

    // --- Request line ---------------------------------------------------

    final public function test_method_path_and_query_reach_the_request(): void
    {
        $outcome = $this->dispatch(new WireRequest('GET', '/users/42', 'limit=5&sort=name'));

        $observed = $this->observed($outcome);
        self::assertSame('GET', $observed->method);
        self::assertSame('/users/42', $observed->path);
        self::assertSame('limit=5&sort=name', $observed->query);
        self::assertSame(['limit' => '5', 'sort' => 'name'], $observed->queryParams);
    }

    /**
     * A purely-numeric query parameter name ends up as a PHP int array
     * key on every adapter — `json_decode()` and `parse_str()` both
     * coerce it, and re-keying can't undo it — but PHP's own array
     * lookup coerces a numeric-string read the same way, so the value
     * is still reachable by its real name. Pinned as the shared,
     * honest outcome rather than "fixed" differently per adapter.
     */
    final public function test_a_numeric_query_parameter_name_is_still_readable(): void
    {
        $outcome = $this->dispatch(new WireRequest('GET', '/', '123=ok'));

        self::assertSame('ok', $this->observed($outcome)->queryParams['123'] ?? null);
    }

    // --- Headers --------------------------------------------------------

    final public function test_a_header_reaches_the_request(): void
    {
        $outcome = $this->dispatch(new WireRequest(headers: [['X-Request-Id', 'abc-123']]));

        self::assertSame(['abc-123'], $this->observed($outcome)->header('X-Request-Id'));
    }

    /**
     * A header repeated on the wire arrives as one comma-joined value
     * everywhere: API Gateway folds repeats before the event exists, and
     * a SAPI folds them into the single `$_SERVER` slot a header name
     * has. Neither adapter can do better than its environment, so the
     * shared contract is the fold — and that both agree on its shape.
     */
    final public function test_a_repeated_header_arrives_as_one_comma_joined_value(): void
    {
        $outcome = $this->dispatch(new WireRequest(headers: [['X-Dup', 'first'], ['X-Dup', 'second']]));

        self::assertSame(['first, second'], $this->observed($outcome)->header('X-Dup'));
    }

    /**
     * "123" is an RFC 9110-valid header name (a token, which includes
     * digits). It takes the int-array-key route on the way in, and an
     * adapter that forgets to cast it back hands PSR-7 an int it rejects.
     */
    final public function test_a_purely_numeric_header_name_survives(): void
    {
        $outcome = $this->dispatch(new WireRequest(headers: [['123', 'ok']]));

        self::assertSame(['ok'], $this->observed($outcome)->header('123'));
    }

    // --- Cookies --------------------------------------------------------

    final public function test_cookies_reach_both_the_cookie_header_and_cookie_params(): void
    {
        $outcome = $this->dispatch(new WireRequest(cookies: ['kinetis_session=abc123', 'theme=dark']));

        $observed = $this->observed($outcome);
        self::assertSame(['kinetis_session=abc123; theme=dark'], $observed->header('Cookie'));
        self::assertSame(['kinetis_session' => 'abc123', 'theme' => 'dark'], $observed->cookieParams);
    }

    final public function test_no_cookies_means_no_cookie_header_and_empty_cookie_params(): void
    {
        $outcome = $this->dispatch(new WireRequest());

        $observed = $this->observed($outcome);
        self::assertSame([], $observed->header('Cookie'));
        self::assertSame([], $observed->cookieParams);
    }

    // --- Client address -------------------------------------------------

    final public function test_the_client_address_the_environment_reports_is_remote_addr(): void
    {
        $outcome = $this->dispatch(new WireRequest());

        self::assertSame($this->driver()->expectedClientIp(), $this->observed($outcome)->remoteAddr);
    }

    // --- Bodies ---------------------------------------------------------

    final public function test_a_url_encoded_post_body_is_parsed(): void
    {
        $this->assertUrlEncodedParsed('POST');
    }

    /**
     * The SAPI only auto-parses a form body for POST; PUT and PATCH go
     * through a different path in the superglobals bridge
     * (`request_parse_body()`, with its own url-encoded and multipart
     * branches) and through no different path at all on Lambda. A
     * caller must not be able to tell — so every combination runs.
     */
    final public function test_a_url_encoded_put_body_is_parsed_the_same_as_post(): void
    {
        $this->assertUrlEncodedParsed('PUT');
    }

    final public function test_a_url_encoded_patch_body_is_parsed_the_same_as_post(): void
    {
        $this->assertUrlEncodedParsed('PATCH');
    }

    final public function test_a_multipart_post_body_is_parsed_into_fields_and_files(): void
    {
        $this->assertMultipartParsed('POST');
    }

    final public function test_a_multipart_put_body_is_parsed_the_same_as_post(): void
    {
        $this->assertMultipartParsed('PUT');
    }

    final public function test_a_multipart_patch_body_is_parsed_the_same_as_post(): void
    {
        $this->assertMultipartParsed('PATCH');
    }

    /**
     * A media type's type and subtype are case-insensitive (RFC 9110
     * §8.3.1), so a client spelling the header this way is sending a
     * form body — and every environment here has to read it as one,
     * whether the parsing is the SAPI's own or the adapter's.
     */
    final public function test_a_mixed_case_form_content_type_is_parsed(): void
    {
        $outcome = $this->dispatch(new WireRequest(
            'POST',
            '/forms',
            headers: [['Content-Type', 'Application/X-WWW-Form-Urlencoded; charset=UTF-8']],
            body: 'name=Mixed+Case',
        ));

        self::assertSame(['name' => 'Mixed Case'], $this->observed($outcome)->parsedBody);
    }

    final public function test_a_mixed_case_multipart_content_type_is_parsed(): void
    {
        $boundary = 'KinetisConformanceBoundary';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"name\"\r\n\r\n"
            . "Alon\r\n"
            . "--{$boundary}--\r\n";

        $outcome = $this->dispatch(new WireRequest(
            'POST',
            '/avatars',
            headers: [['Content-Type', "Multipart/Form-Data; boundary={$boundary}"]],
            body: $body,
        ));

        self::assertSame(['name' => 'Alon'], $this->observed($outcome)->parsedBody);
    }

    /**
     * A longer media type that merely begins with a form one is a
     * different media type: its body stays raw bytes for the
     * application to read, exactly as any other unrecognized content
     * type's does.
     */
    final public function test_a_content_type_that_only_looks_like_a_form_type_is_left_unparsed(): void
    {
        $outcome = $this->dispatch(new WireRequest(
            'POST',
            '/forms',
            headers: [['Content-Type', 'application/x-www-form-urlencodedevil']],
            body: 'name=Not+A+Form',
        ));

        $observed = $this->observed($outcome);
        self::assertNull($observed->parsedBody);
        self::assertSame('name=Not+A+Form', $observed->body);
    }

    final public function test_a_json_body_is_left_for_the_application_to_decode(): void
    {
        $outcome = $this->dispatch(WireRequest::json('POST', '/users', '{"name":"Alon"}', ['X-Trace-Id' => 't-1']));

        $observed = $this->observed($outcome);
        self::assertNull($observed->parsedBody);
        self::assertSame('{"name":"Alon"}', $observed->body);
        self::assertSame(['application/json'], $observed->header('Content-Type'));
        self::assertSame(['t-1'], $observed->header('X-Trace-Id'));
    }

    final public function test_a_binary_request_body_arrives_byte_for_byte(): void
    {
        $binary = "\xFF\x00\x89PNG\r\n\x1A\n\x00";
        $outcome = $this->dispatch(new WireRequest(
            'POST',
            '/upload',
            headers: [['Content-Type', 'application/octet-stream']],
            body: $binary,
        ));

        self::assertSame($binary, $this->observed($outcome)->body);
    }

    /**
     * The byte cap on a request body is the Kernel's
     * (`MaxBodySizeMiddleware`), identical under every adapter — but its
     * cheap first check reads the declared `Content-Length`, which only
     * works if the adapter delivers that header as the environment
     * received it. The header is declared here explicitly, so what's
     * asserted is that a *supplied* value comes through unchanged — not
     * that a driver can count bytes. A driver adds one only when the
     * request carries none.
     */
    final public function test_the_declared_content_length_reaches_the_request(): void
    {
        $body = str_repeat('x', 1_234);
        $outcome = $this->dispatch(new WireRequest(
            'POST',
            '/',
            headers: [['Content-Type', 'text/plain'], ['Content-Length', '1234']],
            body: $body,
        ));

        self::assertSame(['1234'], $this->observed($outcome)->header('Content-Length'));
    }

    /**
     * A raw body well past any form-parsing limit the environment
     * enforces (`post_max_size` governs form bodies, not this) arrives
     * whole — no truncation, no silent cap the application can't see.
     * The size the application accepts is its own decision, made in the
     * Kernel with the full body available.
     */
    final public function test_a_large_raw_body_arrives_whole(): void
    {
        $body = random_bytes(1_048_576);
        $outcome = $this->dispatch(new WireRequest(
            'POST',
            '/upload',
            headers: [['Content-Type', 'application/octet-stream']],
            body: $body,
        ));

        $observed = $this->observed($outcome);
        self::assertSame(strlen($body), strlen($observed->body));
        self::assertSame(hash('sha256', $body), hash('sha256', $observed->body));
    }

    final public function test_an_empty_body_is_empty_not_missing(): void
    {
        $outcome = $this->dispatch(WireRequest::json('POST', '/', ''));

        self::assertSame('', $this->observed($outcome)->body);
    }

    /**
     * "0" is the body PHP's own truthiness most easily loses — any
     * `?: ''` on the way through collapses it to empty.
     */
    final public function test_a_body_of_the_literal_zero_is_not_treated_as_empty(): void
    {
        $outcome = $this->dispatch(new WireRequest('POST', '/', headers: [['Content-Type', 'text/plain']], body: '0'));

        self::assertSame('0', $this->observed($outcome)->body);
    }

    // --- Responses ------------------------------------------------------

    final public function test_the_status_and_headers_reach_the_environment(): void
    {
        $outcome = $this->dispatch(new WireRequest(), ResponseSpec::json(201, '{"id":1}'));

        $response = $this->wire($outcome);
        self::assertSame(201, $response->status);
        self::assertSame(['application/json'], $response->header('Content-Type'));
        self::assertSame('{"id":1}', $response->body);
    }

    /**
     * A comma inside one header's value is not a second header — the
     * fold used for repeated headers must not be applied in reverse.
     */
    final public function test_a_header_value_containing_a_comma_is_not_split(): void
    {
        $spec = new ResponseSpec(200, [['Link', '</a>; rel="next", </b>; rel="last"']]);
        $outcome = $this->dispatch(new WireRequest(), $spec);

        self::assertSame(['</a>; rel="next", </b>; rel="last"'], $this->wire($outcome)->header('Link'));
    }

    /**
     * Two cookies must leave as two cookies. A cookie's own attributes
     * (`Expires`, in particular) already contain a comma, so joining
     * them the way ordinary repeated headers are joined produces a value
     * no client can split back apart.
     */
    final public function test_two_set_cookie_headers_leave_as_two_distinct_cookies(): void
    {
        $cookies = ['a=1; Path=/', 'b=2; Path=/; Expires=Wed, 21 Oct 2026 07:28:00 GMT'];
        $outcome = $this->dispatch(new WireRequest(), new ResponseSpec(setCookies: $cookies));

        $response = $this->wire($outcome);
        self::assertSame(200, $response->status);
        self::assertSame($cookies, $response->setCookies);
    }

    final public function test_a_binary_response_body_leaves_byte_for_byte(): void
    {
        $binary = "\xFF\x00\x89PNG\r\n\x1A\n\x00";
        $spec = new ResponseSpec(200, [['Content-Type', 'image/png']], body: $binary);
        $outcome = $this->dispatch(new WireRequest(), $spec);

        self::assertSame($binary, $this->wire($outcome)->body);
    }

    /**
     * Asserted in both directions of the driver's declaration. A
     * streaming environment has to deliver every chunk, in order, *as it
     * is written* — the chunks are spaced out on the emitting side, so
     * a response that arrives whole at the end shows up as near-zero
     * time between its first and last byte, which is what a buffering
     * proxy in front of the SAPI produces and exactly what this catches.
     * One that can't stream has to refuse the response outright — after
     * the handler ran, since that is where the adapter first sees it —
     * never buffer it silently or drop it.
     */
    final public function test_a_streamed_response_is_delivered_or_refused_as_the_environment_declares(): void
    {
        $chunks = ['data: 1', "\n\n", 'data: 2', "\n\n"];
        $spec = new ResponseSpec(200, [['Content-Type', 'text/event-stream']], streamChunks: $chunks, streamDelayMs: 300);
        $outcome = $this->dispatch(new WireRequest(), $spec);

        if ($this->driver()->supportsStreaming()) {
            $response = $this->wire($outcome);
            self::assertSame(200, $response->status);
            self::assertSame(implode('', $chunks), $response->body);
            self::assertNotNull($response->bodyArrivalSpanSeconds, 'a streaming driver has to time the body on the wire');
            // Three 300 ms gaps were written; a generous floor, so a slow
            // runner can't fail this — only a proxy holding the body
            // back until the end, which arrives in well under 100 ms.
            self::assertGreaterThan(0.5, $response->bodyArrivalSpanSeconds, 'the body arrived all at once: something between the adapter and the client is buffering the stream');

            return;
        }

        self::assertInstanceOf(AdapterRejection::class, $outcome->response, 'a non-streaming environment must refuse, not buffer');
        self::assertNotNull($outcome->observed, 'the refusal happens when the response is emitted, after the handler ran');
    }

    // --- The adapter's own failure path ----------------------------------

    /**
     * Whatever this environment cannot parse, the adapter answers for it
     * the same way: a 400 with this framework's error shape, the handler
     * never reached, nothing escaping as a fatal or an uncaught
     * exception. The *trigger* is the environment's own — a form body
     * past a SAPI's `post_max_size`, a multipart body with no usable
     * boundary for an adapter parsing it itself — which is exactly why
     * the driver supplies it; the *outcome* is the contract, and
     * {@see assertMalformedBodyResponse()} is that contract as code, so
     * an adapter's own tests hold its environment-specific inputs to it
     * too.
     */
    final public function test_a_form_body_the_environment_cannot_parse_is_a_clean_400_not_an_uncaught_failure(): void
    {
        $outcome = $this->dispatch($this->driver()->unparseableFormRequest());

        self::assertNull($outcome->observed, 'the handler must not run for a body the adapter could not parse');
        self::assertMalformedBodyResponse($this->wire($outcome));
    }

    /**
     * The one acceptable answer to a request body the adapter could not
     * parse, whatever made it unparseable: 400, JSON, the fixed
     * {@see RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE}, and nothing
     * about the input, which may be attacker-controlled. Public so an
     * adapter's own tests apply it to inputs only its environment can
     * produce — a base64-flagged Lambda body that isn't base64, say.
     */
    final public static function assertMalformedBodyResponse(WireResponse $response): void
    {
        self::assertSame(400, $response->status);
        self::assertSame(['application/json'], $response->header('Content-Type'));
        self::assertSame(['error' => RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE], json_decode($response->body, true));
    }

    // --- Helpers --------------------------------------------------------

    private function dispatch(WireRequest $request, ?ResponseSpec $response = null): Outcome
    {
        return $this->driver()->dispatch($request, $response ?? ResponseSpec::json(200, '{"ok":true}'));
    }

    private function observed(Outcome $outcome): ObservedRequest
    {
        if ($outcome->response instanceof AdapterRejection) {
            self::fail("The adapter rejected the request: {$outcome->response->exceptionClass}: {$outcome->response->message}");
        }

        self::assertNotNull($outcome->observed, 'the handler never ran');

        return $outcome->observed;
    }

    private function wire(Outcome $outcome): WireResponse
    {
        if ($outcome->response instanceof AdapterRejection) {
            self::fail("The adapter rejected the request: {$outcome->response->exceptionClass}: {$outcome->response->message}");
        }

        return $outcome->response;
    }

    private function assertUrlEncodedParsed(string $method): void
    {
        $outcome = $this->dispatch(new WireRequest(
            $method,
            '/forms',
            headers: [['Content-Type', 'application/x-www-form-urlencoded']],
            body: 'name=Url+Encoded&limit=5',
        ));

        $observed = $this->observed($outcome);
        self::assertSame(['name' => 'Url Encoded', 'limit' => '5'], $observed->parsedBody);
        self::assertSame([], $observed->uploadedFiles);
    }

    private function assertMultipartParsed(string $method): void
    {
        $boundary = 'KinetisConformanceBoundary';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"name\"\r\n\r\n"
            . "Alon\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"avatar\"; filename=\"avatar.png\"\r\n"
            . "Content-Type: image/png\r\n\r\n"
            . "fake image bytes\r\n"
            . "--{$boundary}--\r\n";

        $outcome = $this->dispatch(new WireRequest(
            $method,
            '/avatars',
            headers: [['Content-Type', "multipart/form-data; boundary={$boundary}"]],
            body: $body,
        ));

        $observed = $this->observed($outcome);
        self::assertSame(['name' => 'Alon'], $observed->parsedBody);
        self::assertCount(1, $observed->uploadedFiles);
        self::assertSame('avatar', $observed->uploadedFiles[0]['field']);
        self::assertSame('avatar.png', $observed->uploadedFiles[0]['filename']);
        self::assertSame('image/png', $observed->uploadedFiles[0]['mediaType']);
        self::assertSame('fake image bytes', $observed->uploadedFiles[0]['contents']);
    }
}
