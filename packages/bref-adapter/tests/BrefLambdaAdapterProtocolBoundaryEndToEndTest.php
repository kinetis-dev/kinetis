<?php

declare(strict_types=1);

namespace Kinetis\BrefAdapter\Tests;

use Kinetis\BrefAdapter\BrefLambdaAdapter;
use Kinetis\Http\Form\FormLimits;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClassConstant;
use Throwable;

/**
 * Two Runtime API protocol-boundary behaviors, each needing its own
 * server/state directory per test (unlike
 * BrefLambdaAdapterEndToEndTest's single shared one) since both hinge on
 * per-route response timing and event content that a shared counter-file
 * fixture can't isolate across test methods — real per-test setUp()/
 * tearDown() instead of setUpBeforeClass()/tearDownAfterClass().
 *
 * - The next-invocation long poll (GET .../invocation/next) must not
 *   inherit the short, finite timeout the response/error POSTs use —
 *   AWS's own Runtime API reference says not to set one at all, since the
 *   poll can legitimately stay open for a long time while an execution
 *   environment sits idle.
 * - A malformed or non-object invocation event body must be posted to
 *   the invocation error endpoint, not silently downgraded into an
 *   empty, plausible-looking GET / that reaches application routing.
 */
final class BrefLambdaAdapterProtocolBoundaryEndToEndTest extends TestCase
{
    private static function limits(): FormLimits
    {
        return new FormLimits(FormLimits::DEFAULT_MAX_BODY_BYTES);
    }

    private const HOST = '127.0.0.1:8097';

    /** @var resource */
    private $serverProcess;

    private string $stateDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/kinetis-bref-boundary-' . bin2hex(random_bytes(8));
        mkdir($this->stateDir);

        $this->serverProcess = proc_open(
            ['php', '-S', self::HOST, __DIR__ . '/Fixtures/fake-runtime-api.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['LAMBDA_TEST_STATE_DIR' => $this->stateDir],
        );

        usleep(300_000);
    }

    protected function tearDown(): void
    {
        proc_terminate($this->serverProcess);
        proc_close($this->serverProcess);

        foreach (glob($this->stateDir . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->stateDir);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function writeEvent(array $event): void
    {
        file_put_contents($this->stateDir . '/event.json', json_encode($event, JSON_THROW_ON_ERROR));
    }

    private function writeRawEvent(string $rawBody): void
    {
        file_put_contents($this->stateDir . '/event.json', $rawBody);
    }

    private function delayRoute(string $name, float $seconds): void
    {
        file_put_contents("{$this->stateDir}/delay-{$name}.txt", (string) $seconds);
    }

    /**
     * A real AWS API Gateway HTTP API (payload format 2.0) event shape —
     * matching AWS's own documented example structure, not a partial
     * stand-in that happens to carry only the fields this adapter reads.
     * That distinction matters here specifically: an earlier version of
     * this fixture omitted "version": "2.0" entirely, which meant every
     * test built on it was accepting a versionless event as a matter of
     * course rather than proving anything about the real payload-v2
     * discriminator.
     *
     * `headers` is `(object) []`, not a plain `[]` — a real, previously
     * missed fixture bug: json_encode() serializes a plain empty PHP
     * array as a JSON list (`[]`), not the JSON object (`{}`) AWS's own
     * headers shape actually is, so every test built on the earlier
     * version of this fixture was silently exercising the validator
     * against a malformed collection shape it should have rejected —
     * the object cast is what makes the serialized JSON genuinely
     * payload-v2-shaped.
     */
    private function minimalEvent(): array
    {
        return [
            'version' => '2.0',
            'routeKey' => '$default',
            'rawPath' => '/',
            'rawQueryString' => '',
            'headers' => (object) [],
            'requestContext' => [
                'domainName' => 'kinetis.execute-api.eu-west-1.amazonaws.com',
                'http' => [
                    'method' => 'GET',
                    'path' => '/',
                    'protocol' => 'HTTP/1.1',
                    'sourceIp' => '203.0.113.7',
                ],
                'requestId' => 'test-request-id',
                'routeKey' => '$default',
                'stage' => '$default',
            ],
            'body' => '',
            'isBase64Encoded' => false,
        ];
    }

    public function test_an_explicit_next_invocation_timeout_override_is_independent_of_the_response_timeout(): void
    {
        $this->writeEvent($this->minimalEvent());
        // Longer than $responseTimeoutSeconds below, proving the poll
        // isn't bound by that shorter value — the two constructor
        // parameters are wired to their own call sites, not sharing one
        // value. This only proves the two overrides are independent of
        // each other; it does not, on its own, prove the real shipped
        // default has no timeout at all (an explicit large-but-finite
        // override would pass this exact assertion too) — see
        // test_the_default_long_poll_timeout_survives_past_a_shrunk_default_socket_timeout()
        // below for that.
        $this->delayRoute('next', 0.8);

        $adapter = new BrefLambdaAdapter(self::HOST, self::limits(), nextInvocationTimeoutSeconds: 3.0, responseTimeoutSeconds: 0.3);

        try {
            $adapter->run(static fn (ServerRequestInterface $request): ResponseInterface => new Response(204));

            self::fail('run() should not return normally — the fixture is expected to stop it via the second poll\'s 500.');
        } catch (Throwable) {
            // Expected: same "second /next poll answers 500" mechanism
            // BrefLambdaAdapterEndToEndTest already relies on to end the
            // otherwise-infinite loop after exactly one invocation.
        }

        self::assertFileExists(
            $this->stateDir . '/response-test-request-1.json',
            'the delayed poll must still have completed and reached a successful response post',
        );
    }

    /**
     * An integration-level wiring smoke test, not proof of the no-timeout
     * property itself — that distinction matters and is explained here
     * rather than left implied. Constructing the adapter with no
     * nextInvocationTimeoutSeconds override exercises the real, shipped
     * DEFAULT_NEXT_INVOCATION_TIMEOUT_SECONDS end to end through run()/
     * request()/stream_context_create(), confirming the constant actually
     * reaches the stream context rather than being lost or overridden
     * somewhere in the pipeline. It does *not* distinguish -1.0 from a
     * merely large finite value (the previous 86_400.0 survives this
     * exact assertion too, confirmed directly): PHP's http wrapper reads
     * an explicit `timeout` context option directly regardless of its
     * value, finite or not, so *any* explicit override — not just a real
     * "disable it" sentinel — beats a shrunk default_socket_timeout the
     * identical way. The two tests below are what actually prove -1.0
     * specifically, and that -1.0 specifically means "no timeout at all"
     * rather than merely "a very large one".
     */
    public function test_the_default_next_invocation_timeout_is_wired_through_to_the_real_request(): void
    {
        $this->writeEvent($this->minimalEvent());
        $this->delayRoute('next', 0.8);

        $adapter = new BrefLambdaAdapter(self::HOST, self::limits());

        try {
            $adapter->run(static fn (ServerRequestInterface $request): ResponseInterface => new Response(204));

            self::fail('run() should not return normally — the fixture is expected to stop it via the second poll\'s 500.');
        } catch (Throwable) {
            // Same loop-terminating mechanism as every other test here.
        }

        self::assertFileExists(
            $this->stateDir . '/response-test-request-1.json',
            'the real default must be wired through to a real, completed poll',
        );
    }

    /**
     * The exact value under test, checked directly rather than inferred
     * from timing: no behavioral test run in a realistic amount of time
     * can distinguish -1.0 (PHP's real "disable the timeout entirely"
     * sentinel — see the sibling test below) from a merely large finite
     * value such as the previous 86_400.0, since both survive any delay
     * short enough to fit in a test suite. This is what actually pins the
     * constant to the real sentinel rather than any large-enough number.
     */
    public function test_the_default_next_invocation_timeout_constant_is_the_real_no_timeout_sentinel(): void
    {
        $constant = new ReflectionClassConstant(BrefLambdaAdapter::class, 'DEFAULT_NEXT_INVOCATION_TIMEOUT_SECONDS');

        self::assertSame(-1.0, $constant->getValue());
    }

    /**
     * The mechanism -1.0 relies on, proven directly rather than assumed
     * from PHP's own documentation: a negative `timeout` context value is
     * PHP's real "disable the read timeout entirely" sentinel, not merely
     * a value large enough to be practically unbounded. Reuses this
     * test's own already-running fixture server rather than starting a
     * second one, but calls file_get_contents() directly with a raw
     * stream context — bypassing BrefLambdaAdapter entirely — since what's
     * under test here is PHP's own runtime behavior for this exact
     * context option value, independent of anything this class does with
     * it. default_socket_timeout is forced down to 1 second first: a
     * genuinely disabled timeout must survive a delay well past that
     * shrunk ini fallback, which no finite value — however large — could
     * be relied on to do without being coincidentally larger than the
     * delay; -1.0 either ignores the timeout mechanism entirely or it
     * doesn't, and this is what tells the two apart.
     */
    public function test_a_negative_timeout_context_value_genuinely_disables_the_read_timeout(): void
    {
        $this->delayRoute('next', 2.5);

        $originalDefaultSocketTimeout = ini_set('default_socket_timeout', '1');
        self::assertIsString($originalDefaultSocketTimeout, 'ini_set() must report the previous value to restore it afterward');

        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'ignore_errors' => true,
                    'timeout' => -1.0,
                ],
            ]);

            $start = microtime(true);
            $result = @file_get_contents(
                'http://' . self::HOST . '/2018-06-01/runtime/invocation/next',
                context: $context,
            );
            $elapsed = microtime(true) - $start;
        } finally {
            ini_set('default_socket_timeout', $originalDefaultSocketTimeout);
        }

        self::assertNotFalse($result, 'the request must have succeeded rather than timing out');
        self::assertGreaterThan(
            2.0,
            $elapsed,
            'the request must have genuinely waited past the shrunk default_socket_timeout, not returned early',
        );
    }

    public function test_the_response_post_honors_its_own_short_timeout_independently_of_the_poll(): void
    {
        $this->writeEvent($this->minimalEvent());
        // Longer than $responseTimeoutSeconds below (proving the POST
        // times out), but under double it: php -S serves one connection
        // at a time, so the slow /response request keeps the fixture
        // server itself busy for the full delay regardless of when the
        // client gives up — the postError() call that follows needs the
        // server to free up within its own timeout window too, which
        // this margin (a 0.4s post-timeout wait against a 0.6s budget)
        // comfortably allows.
        $this->delayRoute('response', 1.0);

        $adapter = new BrefLambdaAdapter(self::HOST, self::limits(), nextInvocationTimeoutSeconds: 5.0, responseTimeoutSeconds: 0.6);

        try {
            $adapter->run(static fn (ServerRequestInterface $request): ResponseInterface => new Response(204));

            self::fail('run() should not return normally — the fixture is expected to stop it via the second poll\'s 500.');
        } catch (Throwable) {
            // Same loop-terminating mechanism as every other test here.
        }

        // Not asserted: whether response-test-request-1.json exists.
        // php -S keeps running a script to completion after the client
        // gives up (PHP doesn't abort on client disconnect by default),
        // so the fixture's own file_put_contents() for /response still
        // happens once its artificial delay elapses, regardless of
        // whether the adapter's own request() considered that POST a
        // timeout — that file's presence proves the server eventually
        // finished, not that the client received a response. The real
        // signal that the client-side timeout fired as configured is
        // postError() having run at all.
        self::assertFileExists(
            $this->stateDir . '/error-test-request-1.json',
            'the timed-out response post must have been caught and reported via postError()',
        );

        $errorPayload = json_decode(
            (string) file_get_contents($this->stateDir . '/error-test-request-1.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('Kinetis\BrefAdapter\Exception\BrefAdapterException', $errorPayload['errorType']);
        self::assertStringContainsString(
            'Could not reach the Lambda Runtime API',
            $errorPayload['errorMessage'],
            'the reported failure must be the transport-timeout path, not some other error',
        );
    }

    /**
     * Runs the adapter against whatever event.json this test already
     * wrote, asserting the handler is never invoked and the failure is
     * reported to the invocation error endpoint rather than merely
     * thrown into the void — the shared assertions every "this event
     * must be rejected before routing" test in this class needs.
     *
     * @return array<string,mixed> the decoded error payload
     */
    private function runAndAssertEventRejectedBeforeRouting(): array
    {
        $adapter = new BrefLambdaAdapter(self::HOST, self::limits());
        $handlerWasCalled = false;

        try {
            $adapter->run(function (ServerRequestInterface $request) use (&$handlerWasCalled): ResponseInterface {
                $handlerWasCalled = true;

                return new Response(204);
            });

            self::fail('run() should not return normally — the fixture is expected to stop it via the second poll\'s 500.');
        } catch (Throwable) {
            // Same loop-terminating mechanism as every other test here.
        }

        self::assertFalse($handlerWasCalled, 'a rejected event must never reach application routing');
        self::assertFileDoesNotExist($this->stateDir . '/response-test-request-1.json');

        $errorFile = $this->stateDir . '/error-test-request-1.json';
        self::assertFileExists($errorFile, 'the rejected event must have been reported via postError()');

        /** @var array<string,mixed> $errorPayload */
        $errorPayload = json_decode((string) file_get_contents($errorFile), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Kinetis\BrefAdapter\Exception\BrefAdapterException', $errorPayload['errorType']);

        return $errorPayload;
    }

    public function test_a_malformed_event_body_is_reported_to_the_error_endpoint_not_routed_as_a_request(): void
    {
        $this->writeRawEvent('{not valid json');

        $errorPayload = $this->runAndAssertEventRejectedBeforeRouting();

        self::assertStringContainsString('malformed', $errorPayload['errorMessage']);
    }

    public function test_a_valid_json_scalar_is_reported_to_the_error_endpoint(): void
    {
        $this->writeRawEvent('"just a string"');

        $this->runAndAssertEventRejectedBeforeRouting();
    }

    /**
     * A JSON list, not an object — an API Gateway v2 event is always an
     * object. json_decode(..., associative: true) alone can't tell this
     * apart from a genuine object, since both map to a plain PHP array;
     * this is the exact gap the fix closes.
     */
    public function test_a_non_empty_json_list_is_rejected_not_routed_as_a_plausible_request(): void
    {
        $this->writeRawEvent('[1,2]');

        $errorPayload = $this->runAndAssertEventRejectedBeforeRouting();

        self::assertStringContainsString('not a JSON object', $errorPayload['errorMessage']);
    }

    /**
     * The sharpest case: `[]` and `{}` decode to the byte-identical PHP
     * array `[]` under json_decode(..., associative: true) — an
     * is_array() check alone can never distinguish an empty JSON list
     * from an empty JSON object. This is what actually proves the fix
     * examines the real JSON shape rather than merely checking
     * is_array() on the already-collapsed result.
     */
    public function test_an_empty_json_list_is_rejected_the_same_as_a_non_empty_one(): void
    {
        $this->writeRawEvent('[]');

        $errorPayload = $this->runAndAssertEventRejectedBeforeRouting();

        self::assertStringContainsString('not a JSON object', $errorPayload['errorMessage']);
    }

    /**
     * The other half of the `[]`/`{}` pair: a genuine (if degenerate)
     * JSON object, correctly recognized as an object by the first check
     * — and then correctly rejected anyway by the required-field checks,
     * starting with the missing "version": "2.0" discriminator, since it
     * carries none of the fields a real event does. Proves the two
     * layers (object-shape, then required fields) are independently
     * meaningful, not that the first one happens to catch everything the
     * second one would too.
     */
    public function test_an_empty_json_object_is_rejected_for_missing_version(): void
    {
        $this->writeRawEvent('{}');

        $errorPayload = $this->runAndAssertEventRejectedBeforeRouting();

        self::assertStringContainsString('"version": "2.0"', $errorPayload['errorMessage']);
    }

    /**
     * A realistic API Gateway REST API / ALB (payload format 1.0) event
     * — a real JSON object, just the wrong one, and (like most real v1
     * events) carrying no "version" field at all. Without the version
     * check, this would silently degrade into a plausible-looking
     * GET / via requestFromEvent()'s own fallbacks instead of a clear
     * error naming the actual problem — payload format 1.0 is
     * unsupported.
     */
    public function test_a_payload_format_1_event_is_rejected_not_silently_downgraded_to_get_slash(): void
    {
        $this->writeRawEvent(json_encode([
            'resource' => '/{proxy+}',
            'path' => '/users/42',
            'httpMethod' => 'GET',
            'headers' => ['Content-Type' => 'application/json'],
            'queryStringParameters' => null,
            'requestContext' => [
                'resourcePath' => '/{proxy+}',
                'httpMethod' => 'GET',
                'identity' => ['sourceIp' => '203.0.113.7'],
            ],
            'body' => null,
            'isBase64Encoded' => false,
        ], JSON_THROW_ON_ERROR));

        $errorPayload = $this->runAndAssertEventRejectedBeforeRouting();

        self::assertStringContainsString('"version": "2.0"', $errorPayload['errorMessage']);
    }

    /**
     * The exact bypass a shape-only check (checking requestContext.http
     * without checking version) would miss: an event that explicitly
     * declares payload format 1.0 but also carries a fabricated,
     * v2-looking requestContext.http.method/rawPath — either a
     * misconfigured caller or a direct, non-API-Gateway Lambda
     * invocation carrying arbitrary JSON. The version discriminator is
     * what catches this; nothing about the nested shape alone can.
     */
    public function test_a_version_1_0_event_with_fabricated_v2_shaped_fields_is_rejected(): void
    {
        $this->writeRawEvent(json_encode([
            'version' => '1.0',
            'rawPath' => '/admin',
            'requestContext' => ['http' => ['method' => 'POST']],
        ], JSON_THROW_ON_ERROR));

        $errorPayload = $this->runAndAssertEventRejectedBeforeRouting();

        self::assertStringContainsString('"version": "2.0"', $errorPayload['errorMessage']);
    }

    /**
     * @param array<string, mixed> $overrides merged over minimalEvent()
     *
     * @return array<string, mixed>
     */
    private function validEventWith(array $overrides): array
    {
        return array_replace_recursive($this->minimalEvent(), $overrides);
    }

    public function test_an_event_with_empty_http_is_rejected(): void
    {
        // Not validEventWith(): array_replace_recursive() merges an
        // empty-array override into the existing http object rather
        // than clearing it (both being arrays, it recurses into them —
        // an override with zero keys changes nothing), so http has to
        // be overwritten directly to actually end up empty. (object) [],
        // not a plain [], so the serialized JSON is genuinely an empty
        // *object* ({}) rather than an empty list ([]) — this test is
        // about a real, if degenerate, http object with no method key,
        // not about http being list-shaped (a separate, already-covered
        // rejection).
        $event = $this->minimalEvent();
        $event['requestContext']['http'] = (object) [];
        $this->writeEvent($event);

        $errorPayload = $this->runAndAssertEventRejectedBeforeRouting();

        self::assertStringContainsString('requestContext.http.method', $errorPayload['errorMessage']);
    }

    public function test_an_event_with_a_missing_method_is_rejected(): void
    {
        $event = $this->minimalEvent();
        unset($event['requestContext']['http']['method']);
        $this->writeEvent($event);

        $errorPayload = $this->runAndAssertEventRejectedBeforeRouting();

        self::assertStringContainsString('requestContext.http.method', $errorPayload['errorMessage']);
    }

    public function test_an_event_with_an_empty_string_method_is_rejected(): void
    {
        $this->writeEvent($this->validEventWith(['requestContext' => ['http' => ['method' => '']]]));

        $errorPayload = $this->runAndAssertEventRejectedBeforeRouting();

        self::assertStringContainsString('requestContext.http.method', $errorPayload['errorMessage']);
    }

    public function test_an_event_with_a_missing_raw_path_is_rejected(): void
    {
        $event = $this->minimalEvent();
        unset($event['rawPath']);
        $this->writeEvent($event);

        $errorPayload = $this->runAndAssertEventRejectedBeforeRouting();

        self::assertStringContainsString('rawPath', $errorPayload['errorMessage']);
    }

    public function test_an_event_with_a_non_string_raw_path_is_rejected(): void
    {
        $this->writeEvent($this->validEventWith(['rawPath' => 42]));

        $errorPayload = $this->runAndAssertEventRejectedBeforeRouting();

        self::assertStringContainsString('rawPath', $errorPayload['errorMessage']);
    }

    /**
     * rawPath present and a string, but empty — the non-empty check is
     * distinct from the is_string() check the sibling test above proves,
     * and needs its own case: an empty path is a real, well-typed value
     * that a bare is_string() check would let straight through.
     */
    public function test_an_event_with_an_empty_string_raw_path_is_rejected(): void
    {
        $this->writeEvent($this->validEventWith(['rawPath' => '']));

        $errorPayload = $this->runAndAssertEventRejectedBeforeRouting();

        self::assertStringContainsString('rawPath', $errorPayload['errorMessage']);
    }

    /**
     * The sharpest collection-shape case: a JSON *object*
     * (`{"session":"abc"}`) where payload-v2's `cookies` is always a
     * JSON *list* of strings. Every value inside it is a genuine
     * string, so a check that only validated member types (without
     * checking the collection itself is list-shaped) would accept this
     * — exactly the gap the associative: false decode closes, since a
     * JSON object decodes to stdClass there rather than a plain array.
     * This case is also lossy in a way worth naming directly: if it were
     * accepted, the object's own key ("session") would be silently
     * discarded by the time it reached a Cookie header, becoming a bare
     * "abc" instead of "session=abc".
     */
    public function test_an_event_with_a_cookie_object_instead_of_a_list_is_rejected(): void
    {
        $this->writeEvent($this->validEventWith(['cookies' => ['session' => 'abc']]));

        $errorPayload = $this->runAndAssertEventRejectedBeforeRouting();

        self::assertStringContainsString('cookies', $errorPayload['errorMessage']);
    }

    /**
     * headers as a JSON *list* (`["a","b"]`) rather than the real
     * payload-v2 object shape — is_array() alone (on the associative:
     * true decode) cannot tell this apart from a valid header object,
     * since both are plain PHP arrays there.
     */
    public function test_an_event_with_a_header_list_instead_of_an_object_is_rejected(): void
    {
        $this->writeEvent($this->validEventWith(['headers' => ['a', 'b']]));

        $errorPayload = $this->runAndAssertEventRejectedBeforeRouting();

        self::assertStringContainsString('headers', $errorPayload['errorMessage']);
    }

    /**
     * A genuine header object, but with an array-valued entry
     * (`{"x-test":["a","b"]}`) — payload-v2 never has this shape; a
     * multi-value header is comma-joined into one string instead (see
     * this class's own top docblock). Distinct from the list-shaped
     * case above: the object/array-at-the-top-level check alone would
     * pass this, since headers genuinely is an object here — only
     * checking each *value*'s type catches it.
     */
    public function test_an_event_with_an_array_valued_header_is_rejected(): void
    {
        $this->writeEvent($this->validEventWith(['headers' => ['x-test' => ['a', 'b']]]));

        $errorPayload = $this->runAndAssertEventRejectedBeforeRouting();

        self::assertStringContainsString('headers', $errorPayload['errorMessage']);
    }

    /**
     * The queryStringParameters equivalent of the array-valued-header
     * case above — same shape violation, different field.
     */
    public function test_an_event_with_an_array_valued_query_parameter_is_rejected(): void
    {
        $this->writeEvent($this->validEventWith(['queryStringParameters' => ['q' => ['a', 'b']]]));

        $errorPayload = $this->runAndAssertEventRejectedBeforeRouting();

        self::assertStringContainsString('queryStringParameters', $errorPayload['errorMessage']);
    }

    /**
     * One representative case per optional-field validation path — not
     * every field individually, but each of the three distinct checking
     * mechanisms (a plain scalar/array type via assertOptionalScalar(),
     * the nested sourceIp check, and the cookie-list-of-strings check):
     * proof the "complete boundary" validation actually runs, not just
     * the three required fields.
     */
    public function test_an_event_with_a_non_boolean_is_base64_encoded_is_rejected(): void
    {
        $this->writeEvent($this->validEventWith(['isBase64Encoded' => 'true']));

        $errorPayload = $this->runAndAssertEventRejectedBeforeRouting();

        self::assertStringContainsString('isBase64Encoded', $errorPayload['errorMessage']);
    }

    public function test_an_event_with_a_non_string_source_ip_is_rejected(): void
    {
        $this->writeEvent($this->validEventWith(['requestContext' => ['http' => ['sourceIp' => 203]]]));

        $errorPayload = $this->runAndAssertEventRejectedBeforeRouting();

        self::assertStringContainsString('sourceIp', $errorPayload['errorMessage']);
    }

    public function test_an_event_with_a_non_string_cookie_entry_is_rejected(): void
    {
        $this->writeEvent($this->validEventWith(['cookies' => ['a=1', 2]]));

        $errorPayload = $this->runAndAssertEventRejectedBeforeRouting();

        self::assertStringContainsString('cookies', $errorPayload['errorMessage']);
    }

    /**
     * The positive counterpart to every rejection test above: a genuine,
     * complete payload-v2 event — including every optional field this
     * class validates, correctly typed — must still be accepted and
     * reach the handler. The rejection tests alone can't rule out the
     * validation being simply too strict.
     */
    public function test_a_fully_populated_valid_v2_event_reaches_the_handler(): void
    {
        $this->writeEvent($this->validEventWith([
            'rawQueryString' => 'tab=billing',
            'cookies' => ['a=1', 'b=2'],
            'headers' => ['content-type' => 'application/json'],
            'queryStringParameters' => ['tab' => 'billing'],
            'requestContext' => ['http' => ['sourceIp' => '203.0.113.7']],
            'body' => 'payload',
            'isBase64Encoded' => false,
        ]));

        $adapter = new BrefLambdaAdapter(self::HOST, self::limits());
        $handlerWasCalled = false;

        try {
            $adapter->run(function (ServerRequestInterface $request) use (&$handlerWasCalled): ResponseInterface {
                $handlerWasCalled = true;

                return new Response(204);
            });

            self::fail('run() should not return normally — the fixture is expected to stop it via the second poll\'s 500.');
        } catch (Throwable) {
            // Same loop-terminating mechanism as every other test here.
        }

        self::assertTrue($handlerWasCalled, 'a fully valid v2 event must reach the handler, not be rejected');
        self::assertFileExists($this->stateDir . '/response-test-request-1.json');
    }

    /**
     * A purely-numeric header name ("123") is a real, RFC 9110-valid
     * header (digits are valid token characters), but json_decode(...,
     * associative: true) coerces a canonical-integer JSON object key
     * to a PHP int array key — and PSR-7's withHeader() requires a real
     * string, throwing InvalidArgumentException on an int even though
     * nothing about the header itself is invalid. End to end, not just
     * at the requestFromEvent() unit level: proves a real invocation
     * carrying this header reaches the handler rather than being
     * rejected with a spurious invocation error over a header name that
     * was never actually malformed.
     */
    public function test_an_event_with_a_purely_numeric_header_name_reaches_the_handler(): void
    {
        $this->writeEvent($this->validEventWith(['headers' => ['123' => 'ok']]));

        /** @var ServerRequestInterface|null $capturedRequest */
        $capturedRequest = null;

        $adapter = new BrefLambdaAdapter(self::HOST, self::limits());

        try {
            $adapter->run(static function (ServerRequestInterface $request) use (&$capturedRequest): ResponseInterface {
                $capturedRequest = $request;

                return new Response(204);
            });

            self::fail('run() should not return normally — the fixture is expected to stop it via the second poll\'s 500.');
        } catch (Throwable) {
            // Same loop-terminating mechanism as every other test here.
        }

        self::assertNotNull($capturedRequest, 'a numeric header name must never be reported as a malformed event');
        self::assertTrue($capturedRequest->hasHeader('123'));
        self::assertSame('ok', $capturedRequest->getHeaderLine('123'));
    }
}
