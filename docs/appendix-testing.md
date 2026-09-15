# Appendix: Testing Reference

The procedures and contracts behind {doc}`testing`: how the test client
builds a request, how to prove an operation keeps the event loop
responsive, and how a runtime adapter is held to the shared conformance
suite. For testing routes, configuration, services and databases, see
{doc}`testing`.

(testing-reference-client-requests)=
## How the test client builds a request

`post()`, `put()`, `patch()` and `request()` JSON-encode an array `body`
and set `Content-Type: application/json` unless the caller passes one. A
caller-supplied `Content-Type` must be JSON-shaped: `application/json`,
or an `application/*+json` structured suffix for a vendor media type. A
`; charset=...` parameter is accepted, since only the bare media type is
checked. Any other value throws rather than sending JSON bytes under a
`Content-Type` that claims otherwise.

`postForm()`, `putForm()` and `patchForm()` apply the same rule to
`application/x-www-form-urlencoded`: the override may carry parameters,
and anything that is not form-urlencoded throws. The raw bytes are
exactly `http_build_query($form)`, and `getParsedBody()` is that string
parsed back with `parse_str()` rather than `$form` itself. Every scalar
comes back as a string, a `null` value is omitted, and a nested array is
re-encoded and re-parsed — the shape a real form post produces.

The header is resolved case-insensitively. Two keys naming
`Content-Type` under different letter-case with different values throw;
two with the same value are collapsed into one outgoing header. The check
runs on a request with no body too, so `get()` and `delete()` still
refuse a conflicting `Content-Type` in `headers`.

Query parameters, from `get()`'s `query:` or `request()`'s, are encoded
into the request URI's query string, and `getQueryParams()` is parsed
back from that string. A `Cookie` header, under any letter-case, is sent
verbatim and `getCookieParams()` is parsed from it. Both relationships
match a request a runtime adapter builds.

`raw()` sends a string body exactly as given. `send()` dispatches a
PSR-7 request exactly as handed over and completes nothing: a request
that needs cookies read sets `withCookieParams()` alongside its `Cookie`
header. Every other method builds a request and calls `send()`.

(testing-reference-loop-liveness)=
## Proving a path keeps the loop responsive

Static analysis reports the blocking calls it can name (see
{ref}`non-blocking-application-io`). A test observes whether one
operation lets the event loop turn:
`Kinetis\Testing\LoopLiveness::turnedDuring()` runs the operation next
to a `Timer::delay()` sentinel, as two `concurrently()` tasks, and
reports whether the sentinel resumed while the operation was still in
flight.

### The procedure

Give the operation something slow to wait on. A local upstream served by
`php -S` that answers after 200 ms is enough:

```{code-block} php
:caption: tests/Fixtures/slow-upstream.php

<?php

usleep(200_000);

header('Content-Type: application/json');
echo '{"status":"in_transit"}';
```

```{code-block} php
:caption: tests/CarrierLivenessTest.php

use Kinetis\RevoltHttpClient\Http;
use Kinetis\Testing\FreePort;
use Kinetis\Testing\LoopLiveness;
use PHPUnit\Framework\TestCase;

final class CarrierLivenessTest extends TestCase
{
    public function test_tracking_a_shipment_keeps_the_loop_responsive(): void
    {
        $port = FreePort::reserve();
        $server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/Fixtures/slow-upstream.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        if ($server === false) {
            self::fail('Could not start the upstream.');
        }

        try {
            for ($attempt = 0; ($probe = @fsockopen('127.0.0.1', $port)) === false; $attempt++) {
                self::assertLessThan(500, $attempt, 'The upstream did not start.');
                usleep(10_000);
            }

            fclose($probe);

            $http = new Http()->withBaseUrl("http://127.0.0.1:{$port}");

            self::assertTrue(LoopLiveness::turnedDuring(
                static fn () => $http->get('/shipments/1Z999')->throw()->json(),
            ));
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }
}
```

Replace the `Http` call with the application path under test, pointed at
the slow upstream — in an `ApplicationTestCase`, a service from
`$this->app` or a route through `$this->client`.

The test body's `proc_open()`, `fsockopen()` and `usleep()` block only
the test process while it sets up the upstream, before and outside the
operation `turnedDuring()` observes: only the closure handed to it runs
beside the sentinel. The upstream's own `usleep()` runs in the separate
`php -S` process. When the project's PHPStan `paths` include `tests/`,
exempt both files with a standard ignore:

```{code-block} yaml
:caption: phpstan.neon

parameters:
    ignoreErrors:
        # Liveness-test setup and its fixture server, outside the observed operation.
        -
            identifier: kinetis.blockingCall
            paths:
                - tests/CarrierLivenessTest.php
                - tests/Fixtures/slow-upstream.php
```

### Outcomes

- **`true`** — the sentinel resumed while the operation was in flight, so
  the loop turned during it. One suspension anywhere in the operation is
  enough; this does not show that every wait inside it yields.
- **`false`** — the operation ran for at least the sentinel interval
  (20 ms by default) and finished before the sentinel could resume, so
  nothing let the loop turn. A blocking call is the usual cause;
  CPU-bound work monopolizes the loop the same way and gives the same
  answer.
- **`Kinetis\Testing\Exception\LoopLivenessInconclusiveException`** — the
  operation finished inside the sentinel interval, before there was
  anything to observe. Make the upstream slower than the sentinel by
  several intervals, so scheduling jitter cannot decide the result.

An exception from the operation is rethrown unchanged, and an interval
that is zero, negative, or not finite throws `InvalidArgumentException`.
`turnedDuring()` works from a plain test and from inside a
`concurrently()` task, and leaves no watcher behind.

It is a diagnostic, not a timeout: an operation that never returns keeps
`turnedDuring()` from returning too, so run the suite under an outer
timeout, such as the CI job's own. Nor is a `true` proof that the whole
application is non-blocking — it covers the one operation the test drives.

(testing-reference-conformance)=
## Conformance-testing a runtime adapter

A runtime adapter turns whatever its environment delivers — superglobals
and `php://input`, an API Gateway event, a Goridge frame — into a PSR-7
request, and turns the PSR-7 response back. Adapters built through
entirely different code have to agree on what that conversion means:
which header a repeated header becomes, where cookies end up, what the
URI's scheme, authority and request target are and that they agree with
the `Host` header, that a `PUT` or `PATCH` form body parses the same as
a `POST` one (url-encoded and multipart alike), that nested and repeated
field and file names nest identically, that the declared
`Content-Length` and a large body both arrive intact, that a binary body
arrives byte for byte, that two `Set-Cookie` headers leave as two
cookies, what happens to a body the environment cannot parse and to one
past a form-complexity ceiling. `Kinetis\Testing\Runtime` expresses each
of those once, as a PHPUnit base class, and runs the whole list against
any adapter that provides a driver:

```{code-block} php
use Kinetis\Testing\Runtime\RuntimeAdapterConformanceTestCase;
use Kinetis\Testing\Runtime\RuntimeAdapterDriver;

final class SwooleConformanceTest extends RuntimeAdapterConformanceTestCase
{
    protected function driver(): RuntimeAdapterDriver
    {
        return new SwooleDriver();
    }
}
```

### The driver

The driver is the only adapter-specific code. It pushes one
`WireRequest` (method, path, query string, headers as repeatable pairs,
cookies, raw body) through the adapter, has the handler answer with the
given `ResponseSpec`, and reports an `Outcome`: the `ObservedRequest`
the handler saw (`null` if the adapter never reached it) and the
`WireResponse` the environment received — or an `AdapterRejection`, when
the adapter refused outright.

```{code-block} php
interface RuntimeAdapterDriver
{
    public function dispatch(WireRequest $request, ResponseSpec $response): Outcome;
    public function expectedClientIp(): string;
    public function supportsStreaming(): bool;
    public function expectedScheme(): string;
    public function preservesNumericHeaderNames(): bool;
    public function preservesCookieOrder(): bool;
    public function trustsTheConnectingClient(): bool;
    public function supportsPlaintextRequests(): bool;
}
```

Everything after `dispatch()` is a fact the environment decides, not the
test: the address it reports as `REMOTE_ADDR` (a real socket's peer for
a SAPI, whatever the driver injects as `sourceIp` for Lambda), the
scheme it serves over when nothing forwards one, whether a
`StreamedResponse` can reach the client incrementally, whether a
purely-numeric header name and the client's cookie order survive its own
request decoding, whether the peer the driver connects from is a trusted
edge whose `X-Forwarded-Proto` may decide the request's scheme, and
whether a plaintext request can reach the environment at all.

A parsed form body's raw bytes are not among them. The staged body is
seekable and rewound, so `getBody()` after `getParsedBody()` is the
request byte for byte under every adapter, and the suite asserts that
rather than asking.

Read a driver for a worked example —
`Kinetis\Tests\Runtime\Conformance\SuperglobalsDriver` in the framework
package, `Kinetis\BrefAdapter\Tests\Conformance\LambdaDriver` in
kinetis/bref-adapter, `Kinetis\RoadRunnerAdapter\Tests\Conformance\RoadRunnerDriver`
in kinetis/roadrunner-adapter.

### Declarations are asserted in both directions

A declaration never becomes a skip. A streaming environment must deliver
every chunk in order; a non-streaming one must refuse the response rather
than buffer it. An environment that keeps a numeric header name must
deliver its value unchanged; one that cannot must drop the header
outright, never deliver it under another name or with another value. An
environment that treats this client as an edge must honor a forwarded
scheme, `http` and `https` alike; one that does not must ignore it
completely and serve the scheme it serves itself, which on an environment
already terminating TLS leaves the request `https`. An environment no
plaintext request can reach — `supportsPlaintextRequests()` says so — has
nothing to honor and nothing to ignore when a forwarded scheme names
`http`: that names a request it cannot have received, and it is refused
before the handler. Every method runs on every adapter.

### Over-limit and malformed bodies

Over-limit input needs no declaration: the ceilings are
`Kinetis\Http\Form\FormLimits`' own and identical everywhere, so the
suite builds those requests itself — one field, one file, one nesting
level, one part past each limit, with a security-significant field
(`csrf_token`, a signature upload) placed beyond the edge — and requires
a `413` with the handler never reached. That is the case a truncating
parser passes by handing on a form that looks complete with exactly that
field missing.

Three of those cases exist because they are invisible to a limit checked
after parsing, and every runtime has to meet them the same way: a body
repeating **one** name past the ceiling (a thousand pairs on the wire,
one leaf in the result), a body of **unnamed** multipart parts (which
build nothing and still cost a parser everything), and a part repeating
**one header line** past the ceiling (one entry in any header map). The
empty file control is the fourth: submitted by a file input the user left
alone, and reported as `UPLOAD_ERR_NO_FILE` on every adapter, so upload
validation written against PHP behaves identically everywhere.

The multipart contract is asserted as raw wire bodies rather than through
the suite's own part builder — what is being checked is exactly what a
well-formed builder would never produce. A line whose boundary token is
only a prefix stays payload, byte for byte; a root `Content-Type` naming
the boundary twice or trailing syntax after it, a padded delimiter, a
boundary after a bare LF, a decoding `Content-Transfer-Encoding`, an
RFC 2047 encoded word, an RFC 5987 extended parameter, a nested
`multipart/*` part and a repeated `Content-Disposition` are each a `400`;
and a file part declaring no `Content-Type` reports no client media type
at all. One parse produces all of those, under every runtime, so running
the cases on every adapter is what proves each one delivers its body to
that parse intact rather than reshaping it on the way. See
{ref}`runtime-reference-multipart` for the rules themselves.

### Environment-specific inputs

Only behavior every environment can exhibit belongs in the shared suite.
An input one environment alone can produce — a base64-flagged event
body, say — is that adapter's own test to write, alongside the
conformance run; the suite's public assertion helpers
(`assertMalformedBodyResponse()`, `assertOverLimitFormResponse()`) hold
that input to the same contract the shared cases use, so the *outcome*
stays unified even where the *trigger* can't be. The byte cap on a raw
request body is not the adapter's to test — it is
`RequestBodyMiddleware`'s, in the Kernel, identical under every adapter
and tested there. `Kinetis\Testing\FreePort::reserve()` hands a fixture
server a port nothing is listening on, so two suites spawning servers in
one checkout don't collide on a hard-coded number.

### What each run proves

- **In-process**, with no wire and no SAPI: the Lambda conversion.
  `LambdaDriver` calls `BrefLambdaAdapter::handleEvent()` with an event
  built the way API Gateway builds one. That proves the conversion; it
  cannot prove anything about the Runtime API poll and response POST
  around it, which the bref-adapter package's own end-to-end tests cover
  against a real fake server.
- **Under a spawned server, over a real socket**: the committed
  framework suite spawns `php -S -d enable_post_data_reading=0` and,
  through `RuntimeDetector`, runs `FpmAdapter` under the CLI server's
  superglobal population — the only way `php://input` sees a genuine
  request. The CLI server is not a production SAPI, so what this proves
  is the bridge's own behavior, not FPM's or FrankenPHP's. It also
  spawns servers configured the *wrong* way, on purpose: one with
  `enable_post_data_reading` left on, to prove the refusal; one with no
  trusted-proxy policy, to prove a forwarded scheme from a
  directly-reachable client is ignored; and one with `max_input_vars`
  set below the contract, to prove a form that runtime's own
  `parse_str()` would have shortened is refused instead.
  `kinetis/roadrunner-adapter` runs the same suite this way against a
  real, spawned `rr serve` process, which *is* the production path: a
  RoadRunner request only ever exists as the real Goridge wire protocol
  between `rr` and a real PHP worker.
- **Under the real SAPIs**, in CI (`integration.yml`'s
  `runtime-conformance` job): a FrankenPHP worker loop behind Caddy, and
  PHP-FPM behind nginx, each in its own container with the same driver
  pointed at it instead of at a spawned process. That is the only place
  each production SAPI's own population of headers, client address and
  body, and its own streaming path, are exercised. The streaming case
  times the body as it arrives, so a proxy holding a stream back until
  the end fails it — which is what nginx does with `fastcgi_buffering` at
  its default `on`, and why the FPM fixture sets it `off`.

The RoadRunner run has its own CI job (`integration.yml`'s
`roadrunner-conformance`), which needs `ext-sockets` and a fetched `rr`
binary. It runs the suite unfiltered: the two behaviors that environment
cannot deliver are declared by its driver and asserted in both directions
rather than skipped. {doc}`appendix-ci` describes both jobs.

## See also

- {doc}`testing` — application tests, configuration overrides, test
  doubles and database isolation.
- {doc}`concurrency` — the non-blocking I/O a liveness test observes.
- {doc}`appendix-runtime` — the request-body, forwarded-header and
  adapter contracts the conformance suite enforces.
