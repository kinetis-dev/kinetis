<?php

declare(strict_types=1);

namespace Kinetis\Testing\Runtime;

use Kinetis\Http\Form\FormLimits;
use Kinetis\Runtime\RuntimeAdapterInterface;
use PHPUnit\Framework\TestCase;

/**
 * The behaviors every runtime adapter has to agree on, expressed once and
 * run against each adapter through its own {@see RuntimeAdapterDriver}.
 * Superglobals, a Lambda event and a Goridge frame build equivalent PSR-7
 * requests through entirely different code; this is what "equivalent"
 * means, checked.
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
 * body, an event that fails shape validation — is that adapter's own
 * test to write, held to the same contract through the public assertion
 * helpers here.
 *
 * Facts the environment decides (the client IP it reports, the scheme it
 * serves, whether it can stream, whether a numeric header name or cookie
 * order survives, what it cannot parse) come from the driver and are
 * asserted against in *both* directions, so every method here runs on
 * every adapter — none is skipped for an environment that "doesn't do"
 * something, and an environment that cannot do something has to fail at
 * it visibly rather than quietly.
 *
 * Two ceilings, two answers, everywhere. A body that cannot be parsed is
 * a `400` carrying {@see RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE}
 * and nothing else; a body past {@see FormLimits} is a `413` naming the
 * limit. Both happen before the handler, and neither ever hands on a
 * form with the over-limit part quietly removed — every over-limit case
 * here puts a security-significant field last, past the ceiling, and
 * proves the handler never ran at all.
 */
abstract class RuntimeAdapterConformanceTestCase extends TestCase
{
    /**
     * The authority the identity cases address the environment as.
     * Not the host:port a driver connects to: an adapter that rebuilds
     * the authority from its own listener instead of from the request
     * would still look correct if the two matched.
     */
    private const string CLIENT_HOST = 'conformance.example';

    /** The delimiter every multipart case in this suite declares. */
    private const string BOUNDARY = 'KinetisConformanceBoundary';

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

    /**
     * The query string is the client's bytes, and the parameters are
     * `parse_str()`'s reading of exactly those bytes — on every adapter,
     * including the two whose environment hands them a parsed map of its
     * own alongside the raw string. A repeated parameter is where those
     * two readings diverge: PHP keeps the last one, and an environment
     * that collects repeats into a list or joins them with a comma
     * produces a value no PHP application expects.
     */
    final public function test_repeated_query_parameters_are_read_the_way_php_reads_them(): void
    {
        $query = 'tag=a+b&tag=c&list%5B%5D=1&list%5B%5D=2';
        $outcome = $this->dispatch(new WireRequest('GET', '/search', $query));

        $observed = $this->observed($outcome);
        self::assertSame($query, $observed->query, 'the raw query string is the client\'s own bytes, unrewritten');
        self::assertSame(['tag' => 'c', 'list' => ['1', '2']], $observed->queryParams);
    }

    // --- Request identity -----------------------------------------------

    /**
     * The authority a client addressed is the authority the application
     * sees — in the URI and in the `Host` header, agreeing. An adapter
     * that fills the URI in from its own listening socket, or from a
     * gateway's own record of itself, passes every path-and-query test
     * and still builds URLs no client can follow.
     */
    final public function test_the_uri_authority_is_the_host_the_client_addressed(): void
    {
        $outcome = $this->dispatch(new WireRequest(headers: [['Host', self::CLIENT_HOST]]));

        $observed = $this->observed($outcome);
        self::assertSame(self::CLIENT_HOST, $observed->host);
        self::assertNull($observed->port, 'a default port for the scheme is not part of the authority');
        self::assertSame([self::CLIENT_HOST], $observed->header('Host'));
    }

    final public function test_a_port_in_the_host_header_reaches_the_uri(): void
    {
        $authority = self::CLIENT_HOST . ':8443';
        $outcome = $this->dispatch(new WireRequest(headers: [['Host', $authority]]));

        $observed = $this->observed($outcome);
        self::assertSame(self::CLIENT_HOST, $observed->host);
        self::assertSame(8443, $observed->port);
        self::assertSame([$authority], $observed->header('Host'));
    }

    final public function test_the_uri_scheme_is_the_one_the_environment_serves(): void
    {
        $outcome = $this->dispatch(new WireRequest());

        self::assertSame($this->driver()->expectedScheme(), $this->observed($outcome)->scheme);
    }

    /**
     * Every adapter here can run behind something that terminates TLS —
     * a proxy, a gateway, a load balancer — and `X-Forwarded-Proto` is
     * how that thing says so. An adapter that ignores it behind such an
     * edge makes every absolute URL the application generates `http://`,
     * which is a redirect loop or a mixed-content failure rather than a
     * subtle difference.
     *
     * But the header is an ordinary one any client can send, so who sent
     * it decides whether it means anything. Asserted in both directions
     * of the driver's declaration: an environment that treats this
     * client as an edge has to honor the header, and one that does not
     * has to ignore it completely and serve its own scheme — never
     * "honor it a little", which is what letting a directly reachable
     * client choose the scheme its own request appears to have arrived
     * over amounts to.
     */
    final public function test_a_forwarded_scheme_decides_the_uri_scheme_only_from_a_trusted_edge(): void
    {
        $outcome = $this->dispatch(new WireRequest(headers: [
            ['Host', self::CLIENT_HOST],
            ['X-Forwarded-Proto', 'https'],
        ]));

        $expected = $this->driver()->trustsTheConnectingClient() ? 'https' : $this->driver()->expectedScheme();

        self::assertSame($expected, $this->observed($outcome)->scheme);
    }

    /**
     * The other direction of the same rule, and the one that costs
     * something when it is wrong: an environment that trusts this client
     * must still not be talked *out* of TLS by a forwarded header, and
     * one that does not trust it must not be talked into it. `http`
     * where the environment already serves `https` is the downgrade a
     * spoofed header would aim for.
     */
    final public function test_a_forwarded_scheme_naming_http_cannot_downgrade_an_https_environment(): void
    {
        $outcome = $this->dispatch(new WireRequest(headers: [
            ['Host', self::CLIENT_HOST],
            ['X-Forwarded-Proto', 'http'],
        ]));

        $expected = $this->driver()->trustsTheConnectingClient() ? 'http' : $this->driver()->expectedScheme();

        self::assertSame($expected, $this->observed($outcome)->scheme);
    }

    /**
     * The request target as sent, byte for byte — not rebuilt from a
     * parsed path and a re-encoded query, which is where a `+`, a
     * percent-encoded byte or a repeated key quietly changes.
     */
    final public function test_the_request_target_is_the_origin_form_the_client_sent(): void
    {
        $outcome = $this->dispatch(new WireRequest('GET', '/users/42', 'tag=a+b&tag=c'));

        self::assertSame('/users/42?tag=a+b&tag=c', $this->observed($outcome)->requestTarget);
    }

    final public function test_the_protocol_version_is_the_one_the_client_used(): void
    {
        $outcome = $this->dispatch(new WireRequest());

        self::assertSame('1.1', $this->observed($outcome)->protocolVersion);
    }

    // --- Headers --------------------------------------------------------

    final public function test_a_header_reaches_the_request(): void
    {
        $outcome = $this->dispatch(new WireRequest(headers: [['X-Request-Id', 'abc-123']]));

        self::assertSame(['abc-123'], $this->observed($outcome)->header('X-Request-Id'));
    }

    /**
     * A header repeated on the wire arrives as one comma-joined value
     * everywhere: API Gateway folds repeats before the event exists, a
     * SAPI folds them into the single `$_SERVER` slot a header name
     * has, and RoadRunner's adapter folds what its worker library hands
     * over as a list. Neither the fold nor its shape is optional.
     */
    final public function test_a_repeated_header_arrives_as_one_comma_joined_value(): void
    {
        $outcome = $this->dispatch(new WireRequest(headers: [['X-Dup', 'first'], ['X-Dup', 'second']]));

        self::assertSame(['first, second'], $this->observed($outcome)->header('X-Dup'));
    }

    /**
     * "123" is an RFC 9110-valid header name (a token, which includes
     * digits). It takes the int-array-key route on the way in, and an
     * adapter that forgets to cast it back hands PSR-7 an int it
     * rejects. An environment whose own request decoding drops the
     * header before the adapter exists cannot recover it — that answer
     * is declared, and asserted: the header has to be gone, not present
     * under a different name or carrying a different value, which is
     * the outcome an application could act on without knowing.
     */
    final public function test_a_purely_numeric_header_name_survives_or_is_dropped_as_declared(): void
    {
        $outcome = $this->dispatch(new WireRequest(headers: [['123', 'ok'], ['X-Marker', 'present']]));

        $observed = $this->observed($outcome);
        self::assertSame(['present'], $observed->header('X-Marker'), 'the request itself reached the handler');

        if ($this->driver()->preservesNumericHeaderNames()) {
            self::assertSame(['ok'], $observed->header('123'));

            return;
        }

        self::assertSame([], $observed->header('123'), 'an environment that cannot carry this header must drop it, not reshape it');
    }

    // --- Cookies --------------------------------------------------------

    /**
     * Cookies reach both places an application reads them from, with
     * their values intact. Order is the environment's to declare: a
     * runtime that carries cookies through a hash map loses the client's
     * order and cannot get it back, which is a real difference worth
     * stating rather than a failure — but the names and values are
     * asserted either way, and so is the shape of the `Cookie` header
     * itself, which `cookieParams` is only this framework's reading of.
     */
    final public function test_cookies_reach_both_the_cookie_header_and_cookie_params(): void
    {
        $outcome = $this->dispatch(new WireRequest(cookies: ['kinetis_session=abc123', 'theme=dark']));

        $observed = $this->observed($outcome);
        $cookieHeader = $observed->header('Cookie');
        self::assertCount(1, $cookieHeader, 'however many Cookie header lines arrived, one has to leave the adapter');

        $pairs = array_map(trim(...), explode(';', $cookieHeader[0]));
        $cookieParams = $observed->cookieParams;

        if (!$this->driver()->preservesCookieOrder()) {
            sort($pairs);
            ksort($cookieParams);
        }

        self::assertSame(['kinetis_session=abc123', 'theme=dark'], $pairs);
        self::assertSame(['kinetis_session' => 'abc123', 'theme' => 'dark'], $cookieParams);
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
     * A form body is a form body whatever method carried it. No adapter
     * has a per-method branch to get wrong — every one of them stages
     * the raw bytes and hands them to the same `Kinetis\Http\Form`
     * entry point — and a caller must not be able to tell which method
     * it used, so every combination runs.
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
     * A part name is not a key. `user[address][city]` nests, `tags[]`
     * appends, and a repeated plain name replaces — PHP's own rules,
     * which an adapter parsing the body itself has to reproduce rather
     * than assign `$fields[$name]` and flatten all three into shapes a
     * handler reads differently depending on which runtime it is on.
     */
    final public function test_nested_and_repeated_multipart_fields_nest_the_way_php_nests_them(): void
    {
        $outcome = $this->dispatch($this->multipartRequest([
            ['name' => 'user[address][city]', 'value' => 'Tel Aviv'],
            ['name' => 'user[name]', 'value' => 'Alon'],
            ['name' => 'tags[]', 'value' => 'first'],
            ['name' => 'tags[]', 'value' => 'second'],
            ['name' => 'replaced', 'value' => 'earlier'],
            ['name' => 'replaced', 'value' => 'later'],
        ]));

        self::assertSame(
            [
                'user' => ['address' => ['city' => 'Tel Aviv'], 'name' => 'Alon'],
                'tags' => ['first', 'second'],
                'replaced' => 'later',
            ],
            $this->observed($outcome)->parsedBody,
        );
    }

    /**
     * The same rules again for files, which is where losing them costs
     * data rather than shape: two parts named `docs[]` are two uploads,
     * and an adapter keying files by name delivers one.
     */
    final public function test_nested_and_repeated_multipart_files_nest_the_way_php_nests_them(): void
    {
        $outcome = $this->dispatch($this->multipartRequest([
            ['name' => 'docs[]', 'value' => 'first bytes', 'filename' => 'one.txt', 'type' => 'text/plain'],
            ['name' => 'docs[]', 'value' => 'second bytes', 'filename' => 'two.txt', 'type' => 'text/plain'],
            ['name' => 'profile[avatar]', 'value' => 'png bytes', 'filename' => 'avatar.png', 'type' => 'image/png'],
        ]));

        $files = $this->observed($outcome)->uploadedFiles;
        usort($files, static fn (array $a, array $b): int => strcmp($a['field'], $b['field']));

        self::assertSame(
            [
                ['field' => 'docs.0', 'filename' => 'one.txt', 'mediaType' => 'text/plain', 'error' => UPLOAD_ERR_OK, 'contents' => 'first bytes'],
                ['field' => 'docs.1', 'filename' => 'two.txt', 'mediaType' => 'text/plain', 'error' => UPLOAD_ERR_OK, 'contents' => 'second bytes'],
                ['field' => 'profile.avatar', 'filename' => 'avatar.png', 'mediaType' => 'image/png', 'error' => UPLOAD_ERR_OK, 'contents' => 'png bytes'],
            ],
            $files,
        );
    }

    /**
     * Parsing a form must not damage the body it parsed. Every adapter
     * holds the client's bytes in full and parses a copy — the SAPI ones
     * because `enable_post_data_reading=0` leaves `php://input` for the
     * framework to stage, the satellites because a Lambda event and a
     * RoadRunner request each arrive as one string — so the raw body is
     * still there afterwards, byte for byte. A handler reading
     * `getBody()` after `getParsedBody()` gets the request, not a prefix
     * of it and not an empty stream.
     */
    final public function test_parsing_a_multipart_body_leaves_the_raw_bytes_whole(): void
    {
        $request = $this->multipartRequest([
            ['name' => 'name', 'value' => 'Alon'],
            ['name' => 'avatar', 'value' => 'fake image bytes', 'filename' => 'avatar.png', 'type' => 'image/png'],
        ]);

        self::assertSame($request->body, $this->observed($this->dispatch($request))->body);
    }

    /**
     * The same for the other form media type, which no adapter treats
     * differently and every one of them could: a url-encoded body is
     * small enough that consuming it while parsing is easy to do and
     * invisible until a handler reads the raw bytes.
     */
    final public function test_parsing_a_url_encoded_body_leaves_the_raw_bytes_whole(): void
    {
        $body = 'name=Url+Encoded&tags[]=a&tags[]=b';

        $outcome = $this->dispatch(new WireRequest(
            'POST',
            '/forms',
            headers: [['Content-Type', 'application/x-www-form-urlencoded']],
            body: $body,
        ));

        self::assertSame($body, $this->observed($outcome)->body);
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
        $boundary = self::BOUNDARY;
        $body = self::multipartBody($boundary, [['name' => 'name', 'value' => 'Alon']]);

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
     * The byte cap on a raw request body is the Kernel's
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
     * enforces arrives whole — no truncation, no silent cap the
     * application can't see. The size the application accepts is its own
     * decision, made in the Kernel with the full body available.
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

    // --- The form-complexity contract -------------------------------------

    /**
     * One field past {@see FormLimits::MAX_INPUT_VARS}, and the field
     * past it is the one an application would check before trusting any
     * of the others. Refusing the whole form is the only safe answer:
     * every runtime's own parser answers an over-limit form by dropping
     * the tail and reporting success, which hands a handler a form that
     * looks complete and has had exactly the attacker's chosen field
     * removed.
     */
    final public function test_a_form_with_more_input_variables_than_the_contract_allows_is_refused_whole(): void
    {
        $pairs = [];

        for ($i = 0; $i < FormLimits::MAX_INPUT_VARS; $i++) {
            $pairs[] = "field{$i}=v";
        }

        $pairs[] = 'csrf_token=t';

        $this->assertRefusedAsOverLimit(new WireRequest(
            'POST',
            '/forms',
            headers: [['Content-Type', 'application/x-www-form-urlencoded']],
            body: implode('&', $pairs),
        ));
    }

    final public function test_a_form_nested_deeper_than_the_contract_allows_is_refused_whole(): void
    {
        // MAX_NESTING_DEPTH brackets on top of the base name is one
        // level past the ceiling. Every character here is already
        // URL-safe, and the brackets have to stay literal: they are the
        // nesting.
        $name = 'a' . str_repeat('[b]', FormLimits::MAX_NESTING_DEPTH);

        $this->assertRefusedAsOverLimit(new WireRequest(
            'POST',
            '/forms',
            headers: [['Content-Type', 'application/x-www-form-urlencoded']],
            body: "{$name}=deep&csrf_token=t",
        ));
    }

    final public function test_a_multipart_body_with_more_files_than_the_contract_allows_is_refused_whole(): void
    {
        $parts = [];

        for ($i = 0; $i <= FormLimits::MAX_FILE_PARTS; $i++) {
            $parts[] = ['name' => "docs[{$i}]", 'value' => 'bytes', 'filename' => "doc{$i}.txt", 'type' => 'text/plain'];
        }

        $parts[] = ['name' => 'signature', 'value' => 'sig', 'filename' => 'signature.bin', 'type' => 'application/octet-stream'];

        $this->assertRefusedAsOverLimit($this->multipartRequest($parts));
    }

    /**
     * The case a limit checked on the *parsed* form cannot see at all.
     * `a=1` repeated past the ceiling is that many pairs on the wire and
     * exactly one leaf afterwards, so a count taken from
     * `getParsedBody()` reads it as a one-field form — and every parser
     * involved answers its own limit by dropping the tail and reporting
     * success. A `csrf_token` sitting past the edge is then the field
     * that disappears, in a form that looks complete.
     *
     * This is why every runtime counts pairs in the raw body before
     * anything parses it.
     */
    final public function test_a_form_repeating_one_name_past_the_contract_is_refused_whole(): void
    {
        $this->assertDuplicatePaddedFormRefused('POST');
    }

    final public function test_a_repeated_name_put_form_past_the_contract_is_refused_the_same_as_post(): void
    {
        $this->assertDuplicatePaddedFormRefused('PUT');
    }

    final public function test_a_repeated_name_patch_form_past_the_contract_is_refused_the_same_as_post(): void
    {
        $this->assertDuplicatePaddedFormRefused('PATCH');
    }

    /**
     * Far past the ceiling rather than one past it, on the two methods a
     * SAPI never parsed for itself — so an environment that reaches a
     * limit of its own before this framework's meets the same `413` at
     * the same place, instead of a different status or a truncated form.
     */
    final public function test_a_form_far_past_the_contract_is_refused_whole_on_put_and_patch(): void
    {
        foreach (['PUT', 'PATCH'] as $method) {
            $pairs = [];

            for ($i = 0; $i < FormLimits::MAX_INPUT_VARS * 8; $i++) {
                $pairs[] = "field{$i}=v";
            }

            $pairs[] = 'csrf_token=t';

            $this->assertRefusedAsOverLimit(new WireRequest(
                $method,
                '/forms',
                headers: [['Content-Type', 'application/x-www-form-urlencoded']],
                body: implode('&', $pairs),
            ));
        }
    }

    /**
     * A part carrying no `Content-Disposition` name builds neither a
     * field nor a file, so it appears nowhere in the parsed result — and
     * a ceiling counted from that result cannot see it. It still costs a
     * parser a part, which is why the count comes from the envelope.
     */
    final public function test_unnamed_multipart_parts_count_against_the_part_ceiling(): void
    {
        $parts = [];

        for ($i = 0; $i <= FormLimits::MAX_MULTIPART_PARTS; $i++) {
            $parts[] = ['value' => 'padding'];
        }

        $parts[] = ['name' => 'csrf_token', 'value' => 't'];

        $this->assertRefusedAsOverLimit($this->multipartRequest($parts));
    }

    /**
     * Header *lines*, not distinct header names: a part repeating one
     * header has a single entry in any parser's header map and as many
     * lines as it sent on the wire. Counting the map lets a part carry
     * an unbounded header block while appearing to carry one header.
     */
    final public function test_repeated_part_header_lines_count_against_the_part_header_ceiling(): void
    {
        $padding = [];

        for ($i = 0; $i <= FormLimits::MAX_PART_HEADERS; $i++) {
            $padding[] = ['X-Pad', 'v'];
        }

        $this->assertRefusedAsOverLimit($this->multipartRequest([
            ['name' => 'csrf_token', 'value' => 't', 'extraHeaders' => $padding],
        ]));
    }

    /**
     * A file input the user left alone is still submitted: an empty part
     * with `filename=""`. PHP reports it in `$_FILES` as present, with
     * `UPLOAD_ERR_NO_FILE` and no name, type or bytes — so upload
     * validation written against PHP reads "nothing was chosen" and
     * rejects it. An adapter reporting the same part as a successful
     * zero-byte upload would make that validation accept under one
     * runtime exactly what it rejects under another.
     */
    final public function test_an_empty_file_control_is_reported_as_no_file_uploaded(): void
    {
        $outcome = $this->dispatch($this->multipartRequest([
            ['name' => 'avatar', 'value' => '', 'filename' => '', 'type' => 'application/octet-stream'],
            ['name' => 'name', 'value' => 'Alon'],
        ]));

        $observed = $this->observed($outcome);
        self::assertSame(['name' => 'Alon'], $observed->parsedBody, 'an empty file control is not a field');
        self::assertSame(
            [['field' => 'avatar', 'filename' => '', 'mediaType' => '', 'error' => UPLOAD_ERR_NO_FILE, 'contents' => '']],
            $observed->uploadedFiles,
        );
    }

    final public function test_a_multipart_body_with_more_parts_than_the_contract_allows_is_refused_whole(): void
    {
        $parts = [];

        for ($i = 0; $i < FormLimits::MAX_MULTIPART_PARTS; $i++) {
            $parts[] = ['name' => "field{$i}", 'value' => 'v'];
        }

        $parts[] = ['name' => 'csrf_token', 'value' => 't'];

        $this->assertRefusedAsOverLimit($this->multipartRequest($parts));
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
        $spec = ResponseSpec::of(200, [['Link', '</a>; rel="next", </b>; rel="last"']]);
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
        $outcome = $this->dispatch(new WireRequest(), ResponseSpec::of(200, setCookies: $cookies));

        $response = $this->wire($outcome);
        self::assertSame(200, $response->status);
        self::assertSame($cookies, $response->setCookies);
    }

    final public function test_a_binary_response_body_leaves_byte_for_byte(): void
    {
        $binary = "\xFF\x00\x89PNG\r\n\x1A\n\x00";
        $spec = ResponseSpec::of(200, [['Content-Type', 'image/png']], body: $binary);
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
        $spec = ResponseSpec::streaming(200, [['Content-Type', 'text/event-stream']], $chunks, delayMs: 300);
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
     * exception. The *trigger* is the environment's own — which is
     * exactly why the driver supplies it; the *outcome* is the contract,
     * and {@see assertMalformedBodyResponse()} is that contract as code,
     * so an adapter's own tests hold its environment-specific inputs to
     * it too.
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

    /**
     * The other half of the policy: input this framework understood and
     * refused for being past a {@see FormLimits} ceiling. A `413` in
     * this framework's own error shape, carrying a message that names a
     * configured limit — which limit bound first is the runtime's
     * business, since one configured tighter than the contract reaches
     * its own first, and both are real refusals. Public for the same
     * reason as {@see assertMalformedBodyResponse()}: the ceilings an
     * adapter can only meet with its own parser (a part's header count)
     * are held to it from that adapter's own tests.
     */
    final public static function assertOverLimitFormResponse(WireResponse $response): void
    {
        self::assertSame(413, $response->status);
        self::assertSame(['application/json'], $response->header('Content-Type'));

        /** @var array<string, mixed>|null $body */
        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertArrayHasKey('error', $body);
        self::assertIsString($body['error']);
        self::assertNotSame('', $body['error']);
    }

    // --- The multipart contract -----------------------------------------

    /**
     * `Kinetis\Http\Form\MultipartEnvelope` is one reading of
     * `multipart/form-data`, and these are the places a second reading
     * exists — where one parser decodes, splits or normalizes what
     * another passes through byte for byte. Every runtime answers them
     * identically, because every runtime applies that contract to the
     * raw bytes before its own parser runs. Sent as raw wire bodies
     * rather than through {@see multipartBody()}: what is being asserted
     * is exactly what a well-formed builder would never produce.
     */
    final public function test_a_part_body_that_only_begins_like_a_delimiter_stays_payload(): void
    {
        $outcome = $this->dispatch($this->rawMultipartRequest(
            "--" . self::BOUNDARY . "\r\n"
            . "Content-Disposition: form-data; name=\"note\"\r\n\r\n"
            . "first\r\n--" . self::BOUNDARY . "-not-the-delimiter\r\nsecond\r\n"
            . "--" . self::BOUNDARY . "--\r\n",
        ));

        self::assertSame(
            ['note' => "first\r\n--" . self::BOUNDARY . "-not-the-delimiter\r\nsecond"],
            $this->observed($outcome)->parsedBody,
            'a boundary token that only prefixes a longer line is payload, kept whole',
        );
    }

    /**
     * A delimiter line ends right after the boundary. RFC 2046 allows
     * transport padding there, no client sends it, and the parsers this
     * framework runs on do not accept it — so a padded line is not a
     * delimiter on any of them, and a body that relies on one closes
     * nowhere.
     */
    final public function test_a_padded_delimiter_line_does_not_delimit(): void
    {
        $this->assertRefusedAsMalformed($this->rawMultipartRequest(
            "--" . self::BOUNDARY . " \r\n"
            . "Content-Disposition: form-data; name=\"a\"\r\n\r\nvalue\r\n"
            . "--" . self::BOUNDARY . "--\r\n",
        ));
    }

    /**
     * A boundary after a bare LF is a delimiter to a parser reading the
     * body line by line and payload to one matching CRLF delimiters —
     * two different forms from the same bytes, so neither is served.
     */
    final public function test_a_boundary_after_a_bare_newline_is_refused(): void
    {
        $this->assertRefusedAsMalformed($this->rawMultipartRequest(
            "--" . self::BOUNDARY . "\r\n"
            . "Content-Disposition: form-data; name=\"a\"\r\n\r\nvalue\n"
            . "--" . self::BOUNDARY . "\r\n"
            . "Content-Disposition: form-data; name=\"b\"\r\n\r\nsecond\r\n"
            . "--" . self::BOUNDARY . "--\r\n",
        ));
    }

    /**
     * `Content-Transfer-Encoding: base64` is decoded by a parser that
     * implements it and handed over as its literal text by one that does
     * not — one form field with two values depending on the runtime. RFC
     * 7578 §4.7 does not use the header at all.
     */
    final public function test_a_part_declaring_a_decoding_transfer_encoding_is_refused(): void
    {
        $this->assertRefusedAsMalformed($this->rawMultipartRequest(
            "--" . self::BOUNDARY . "\r\n"
            . "Content-Disposition: form-data; name=\"a\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\naGVsbG8=\r\n"
            . "--" . self::BOUNDARY . "--\r\n",
        ));
    }

    /**
     * An RFC 5987 extended parameter is percent-decoded and
     * charset-converted by one parser and read as literal text by
     * another — and the conversion runs through `mb_convert_encoding()`,
     * where a charset the client invented raises an error from the
     * middle of the parse.
     */
    final public function test_a_part_naming_itself_through_an_extended_parameter_is_refused(): void
    {
        $this->assertRefusedAsMalformed($this->rawMultipartRequest(
            "--" . self::BOUNDARY . "\r\n"
            . "Content-Disposition: form-data; name*=not-a-charset''a\r\n\r\nvalue\r\n"
            . "--" . self::BOUNDARY . "--\r\n",
        ));
    }

    /** The same divergence in RFC 2047's spelling. */
    final public function test_a_part_naming_itself_through_an_encoded_word_is_refused(): void
    {
        $this->assertRefusedAsMalformed($this->rawMultipartRequest(
            "--" . self::BOUNDARY . "\r\n"
            . "Content-Disposition: form-data; name=\"=?utf-8?B?YWJj?=\"\r\n\r\nvalue\r\n"
            . "--" . self::BOUNDARY . "--\r\n",
        ));
    }

    /**
     * A nested envelope is a whole further form to a parser that
     * recurses into it and one part's bytes to one that does not — and
     * nothing counted the parts inside it. RFC 7578 §4.3 settles
     * multiple files as repeated parts under one name.
     */
    final public function test_a_part_carrying_a_nested_multipart_body_is_refused(): void
    {
        $this->assertRefusedAsMalformed($this->rawMultipartRequest(
            "--" . self::BOUNDARY . "\r\n"
            . "Content-Disposition: form-data; name=\"files\"\r\n"
            . "Content-Type: multipart/mixed; boundary=Inner\r\n\r\n"
            . "--Inner\r\nContent-Disposition: form-data; name=\"one\"\r\n\r\nfirst\r\n--Inner--\r\n\r\n"
            . "--" . self::BOUNDARY . "--\r\n",
        ));
    }

    /**
     * One part, two names. Which one wins is the parser's own choice —
     * the first line, the last line, or neither — so the part is refused
     * rather than resolved into whichever this runtime happens to pick.
     */
    final public function test_a_part_repeating_its_content_disposition_is_refused(): void
    {
        $this->assertRefusedAsMalformed($this->rawMultipartRequest(
            "--" . self::BOUNDARY . "\r\n"
            . "Content-Disposition: form-data; name=\"a\"\r\n"
            . "Content-Disposition: form-data; name=\"b\"\r\n\r\nvalue\r\n"
            . "--" . self::BOUNDARY . "--\r\n",
        ));
    }

    /**
     * The root `Content-Type` names the delimiter, so a header naming it
     * twice hands every parser a different body: the first boundary to
     * one, the second to the next. Sent with a body well-formed under
     * the first, which is what makes the divergence a form a handler
     * could act on rather than a parse that fails everywhere.
     */
    final public function test_a_content_type_repeating_the_boundary_is_refused(): void
    {
        $this->assertRefusedAsMalformed($this->divergentContentTypeRequest(
            'multipart/form-data; boundary=' . self::BOUNDARY . '; boundary=Second',
        ));
    }

    /**
     * The same divergence one character later: syntax after a quoted
     * boundary is the closing quote to a parser that stops there and
     * part of the delimiter to one that reads to the semicolon.
     */
    final public function test_a_content_type_with_syntax_after_a_quoted_boundary_is_refused(): void
    {
        $this->assertRefusedAsMalformed($this->divergentContentTypeRequest(
            'multipart/form-data; boundary="' . self::BOUNDARY . '"junk',
        ));
    }

    /**
     * A file part that declares no `Content-Type` has no client media
     * type — not `application/octet-stream`, which is what a parser's own
     * default would report and a media type the client never sent.
     */
    final public function test_a_file_part_declaring_no_content_type_reports_none(): void
    {
        $outcome = $this->dispatch($this->rawMultipartRequest(
            "--" . self::BOUNDARY . "\r\n"
            . "Content-Disposition: form-data; name=\"doc\"; filename=\"a.txt\"\r\n\r\nbytes\r\n"
            . "--" . self::BOUNDARY . "--\r\n",
        ));

        self::assertSame(
            [['field' => 'doc', 'filename' => 'a.txt', 'mediaType' => null, 'error' => UPLOAD_ERR_OK, 'contents' => 'bytes']],
            $this->observed($outcome)->uploadedFiles,
        );
    }

    // --- Helpers --------------------------------------------------------

    /**
     * @param list<array{name?: string, value: string, filename?: string, type?: string, extraHeaders?: list<array{0: string, 1: string}>}> $parts
     */
    final protected function multipartRequest(array $parts, string $method = 'POST', string $path = '/forms'): WireRequest
    {
        $boundary = self::BOUNDARY;

        return new WireRequest(
            $method,
            $path,
            headers: [['Content-Type', "multipart/form-data; boundary={$boundary}"]],
            body: self::multipartBody($boundary, $parts),
        );
    }

    /**
     * A part with no `name` key carries no `Content-Disposition` at all —
     * an unnamed part, which builds nothing and still costs one.
     * `extraHeaders` are written as sent, repeats included, which is what
     * a header-line ceiling has to be measured against.
     *
     * @param list<array{name?: string, value: string, filename?: string, type?: string, extraHeaders?: list<array{0: string, 1: string}>}> $parts
     */
    final protected static function multipartBody(string $boundary, array $parts): string
    {
        $body = '';

        foreach ($parts as $part) {
            $body .= "--{$boundary}\r\n";

            if (isset($part['name'])) {
                $disposition = "form-data; name=\"{$part['name']}\"";

                if (isset($part['filename'])) {
                    $disposition .= "; filename=\"{$part['filename']}\"";
                }

                $body .= "Content-Disposition: {$disposition}\r\n";
            }

            if (isset($part['type'])) {
                $body .= "Content-Type: {$part['type']}\r\n";
            }

            foreach ($part['extraHeaders'] ?? [] as [$name, $value]) {
                $body .= "{$name}: {$value}\r\n";
            }

            $body .= "\r\n{$part['value']}\r\n";
        }

        return $body . "--{$boundary}--\r\n";
    }

    private function assertDuplicatePaddedFormRefused(string $method): void
    {
        $pairs = array_fill(0, FormLimits::MAX_INPUT_VARS, 'a=1');
        $pairs[] = 'csrf_token=t';

        $this->assertRefusedAsOverLimit(new WireRequest(
            $method,
            '/forms',
            headers: [['Content-Type', 'application/x-www-form-urlencoded']],
            body: implode('&', $pairs),
        ));
    }

    /**
     * A raw `multipart/form-data` body, byte for byte, under the same
     * boundary every other multipart case here declares.
     */
    final protected function rawMultipartRequest(string $body, string $method = 'POST', string $path = '/forms'): WireRequest
    {
        return new WireRequest(
            $method,
            $path,
            headers: [['Content-Type', 'multipart/form-data; boundary=' . self::BOUNDARY]],
            body: $body,
        );
    }

    /**
     * A body every parser here reads the same way under a `Content-Type`
     * they would each read differently — so what is refused is the
     * header, and the handler is what proves it: no runtime may reach
     * one with a form its neighbor would have built differently.
     */
    private function divergentContentTypeRequest(string $contentType): WireRequest
    {
        return new WireRequest(
            'POST',
            '/forms',
            headers: [['Content-Type', $contentType]],
            body: self::multipartBody(self::BOUNDARY, [['name' => 'name', 'value' => 'Alon']]),
        );
    }

    private function assertRefusedAsMalformed(WireRequest $request): void
    {
        $outcome = $this->dispatch($request);

        self::assertNull($outcome->observed, 'the handler must not run for a body outside the multipart contract');
        self::assertMalformedBodyResponse($this->wire($outcome));
    }

    private function assertRefusedAsOverLimit(WireRequest $request): void
    {
        $outcome = $this->dispatch($request);

        self::assertNull($outcome->observed, 'the handler must not run for a form past a contract limit');
        self::assertOverLimitFormResponse($this->wire($outcome));
    }

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

    /**
     * The authority is asserted here as well as in its own cases
     * because parsing a form is where an adapter is most likely to
     * rebuild the request — a SAPI has to, for a PUT or PATCH body PHP
     * doesn't populate — and a rebuilt request that drops the client's
     * authority looks correct until something generates a URL from it.
     */
    private function assertUrlEncodedParsed(string $method): void
    {
        $outcome = $this->dispatch(new WireRequest(
            $method,
            '/forms',
            headers: [['Host', self::CLIENT_HOST], ['Content-Type', 'application/x-www-form-urlencoded']],
            body: 'name=Url+Encoded&limit=5',
        ));

        $observed = $this->observed($outcome);
        self::assertSame(['name' => 'Url Encoded', 'limit' => '5'], $observed->parsedBody);
        self::assertSame([], $observed->uploadedFiles);
        self::assertSame(self::CLIENT_HOST, $observed->host);
        self::assertSame([self::CLIENT_HOST], $observed->header('Host'));
    }

    private function assertMultipartParsed(string $method): void
    {
        $outcome = $this->dispatch($this->multipartRequest([
            ['name' => 'name', 'value' => 'Alon'],
            ['name' => 'avatar', 'value' => 'fake image bytes', 'filename' => 'avatar.png', 'type' => 'image/png'],
        ], $method, '/avatars'));

        $observed = $this->observed($outcome);
        self::assertSame(['name' => 'Alon'], $observed->parsedBody);
        self::assertSame(
            [['field' => 'avatar', 'filename' => 'avatar.png', 'mediaType' => 'image/png', 'error' => UPLOAD_ERR_OK, 'contents' => 'fake image bytes']],
            $observed->uploadedFiles,
        );
    }
}
