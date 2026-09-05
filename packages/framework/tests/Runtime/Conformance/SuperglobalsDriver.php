<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime\Conformance;

use Kinetis\Testing\FreePort;
use Kinetis\Testing\Runtime\ObservedRequest;
use Kinetis\Testing\Runtime\Outcome;
use Kinetis\Testing\Runtime\ResponseSpec;
use Kinetis\Testing\Runtime\RuntimeAdapterDriver;
use Kinetis\Testing\Runtime\WireRequest;
use Kinetis\Testing\Runtime\WireResponse;
use RuntimeException;

/**
 * Drives the superglobals adapters through a real server serving
 * Fixtures/index.php. Two ways to get one:
 *
 * - {@see spawn()} starts `php -S` itself on a free port — the committed
 *   suite. A real server is not a preference here: `php://input` cannot
 *   be fed from inside the test process, and the settings the bridge
 *   holds an environment to — `enable_post_data_reading`,
 *   `max_input_vars`, `arg_separator.input` — are `PHP_INI_PERDIR`, so
 *   the only way to serve a request under another value is to start a
 *   process with it. RuntimeDetector picks FpmAdapter under the CLI
 *   server, so this is the bridge plus FpmAdapter::run(), under
 *   `php -S`'s superglobal population.
 * - {@see against()} targets a server something else started — the
 *   integration job's FrankenPHP worker and nginx+PHP-FPM containers,
 *   the real SAPIs. The fixture writes each observed request to a
 *   directory both sides can see, named by the per-dispatch id.
 *
 * Requests go out as hand-written HTTP/1.1 over a socket rather than
 * through the `http://` stream wrapper, so a repeated header and a
 * binary body reach the server byte-exact, repeated `Set-Cookie` lines
 * come back as separate lines — and the body can be timed as it
 * arrives, which is how a streamed response is told apart from one a
 * proxy held back until the end.
 */
final class SuperglobalsDriver implements RuntimeAdapterDriver
{
    /**
     * The proxy policy a spawned fixture is served under. Loopback only
     * — exactly the peer this driver connects from, and nothing else —
     * so the shared forwarded-identity case runs against a real trusted
     * edge rather than against a policy that trusts everything.
     * `SuperglobalsConformanceTest` spawns its own servers with other
     * policies for the cases this one cannot show.
     */
    public const string TRUSTED_PROXIES = '127.0.0.1/32,::1/128';

    /** @var resource|null */
    private $server = null;

    private function __construct(
        private readonly string $hostPort,
        private readonly string $stateDir,
        private readonly bool $ownsStateDir,
        private readonly string $clientIp,
        private readonly string $trustedProxies,
        private readonly bool $postDataReading,
        private readonly ?int $maxInputVars,
        private readonly ?string $argSeparatorInput,
    ) {}

    /**
     * @param string $trustedProxies the `TRUSTED_PROXIES` the fixture is
     *     served under
     * @param bool $postDataReading `enable_post_data_reading`, which the
     *     bridge requires to be off — spawnable as `true` only so the
     *     refusal that produces can be proven
     * @param ?int $maxInputVars this server's own `max_input_vars`.
     *     Null leaves PHP's default (1000, above the contract's own
     *     ceiling); a lower value is a runtime configured below the
     *     contract, which is spawnable only so the refusal that produces
     *     can be proven against a real `parse_str()`
     * @param ?string $argSeparatorInput this server's own
     *     `arg_separator.input`. Null leaves PHP's default, `&`, which
     *     is the one `Kinetis\Http\Form\FormPairs` will run under; any
     *     other value is spawnable only so the refusal that produces can
     *     be proven against a real `parse_str()`
     */
    public static function spawn(
        string $trustedProxies = self::TRUSTED_PROXIES,
        bool $postDataReading = false,
        ?int $maxInputVars = null,
        ?string $argSeparatorInput = null,
    ): self {
        $stateDir = sys_get_temp_dir() . '/kinetis-conformance-' . bin2hex(random_bytes(8));
        mkdir($stateDir);

        return new self(
            '127.0.0.1:' . FreePort::reserve(),
            $stateDir,
            ownsStateDir: true,
            clientIp: '127.0.0.1',
            trustedProxies: $trustedProxies,
            postDataReading: $postDataReading,
            maxInputVars: $maxInputVars,
            argSeparatorInput: $argSeparatorInput,
        );
    }

    /**
     * @param string $stateDir the directory the running server's fixture
     *     writes to — as this process sees it, which may be a different
     *     path from the one the server was given
     * @param string $clientIp the address the server reports as
     *     REMOTE_ADDR for connections from this process
     */
    public static function against(string $hostPort, string $stateDir, string $clientIp): self
    {
        return new self(
            $hostPort,
            $stateDir,
            ownsStateDir: false,
            clientIp: $clientIp,
            // The container was started with the same policy, in
            // Fixtures/php-conformance.ini and the integration job's own
            // environment; this process only records what that is.
            // Not this process's choice: the container was started with
            // a policy covering the private range it is reached from
            // (integration.yml's own CONFORMANCE_TRUSTED_PROXIES). This
            // only records that a policy is in force, which is what the
            // shared forwarded-identity case asserts against.
            trustedProxies: self::TRUSTED_PROXIES,
            postDataReading: false,
            maxInputVars: null,
            argSeparatorInput: null,
        );
    }

    /**
     * @param bool $waitForReady poll `/__conformance/ready` until the
     *     whole adapter path answers 204. False only for a server
     *     configured, on purpose, so that no request can succeed — where
     *     never becoming ready is the behavior under test, not a
     *     failure to wait for.
     */
    public function start(bool $waitForReady = true): void
    {
        if ($this->ownsStateDir) {
            $server = proc_open(
                [
                    'php',
                    // The same values Fixtures/php-conformance.ini gives the
                    // containers — see that file for why.
                    '-d', 'enable_post_data_reading=' . ($this->postDataReading ? '1' : '0'),
                    '-d', 'output_buffering=0',
                    ...($this->maxInputVars === null ? [] : ['-d', 'max_input_vars=' . $this->maxInputVars]),
                    ...($this->argSeparatorInput === null ? [] : ['-d', 'arg_separator.input=' . $this->argSeparatorInput]),
                    '-S', $this->hostPort,
                    __DIR__ . '/Fixtures/index.php',
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                [
                    ...getenv(),
                    'KINETIS_CONFORMANCE_STATE_DIR' => $this->stateDir,
                    'TRUSTED_PROXIES' => $this->trustedProxies,
                ],
            );

            if ($server === false) {
                throw new RuntimeException('Could not start the conformance fixture server.');
            }

            $this->server = $server;
        }

        if ($waitForReady) {
            $this->waitForServerReady();

            return;
        }

        // Still has to be listening before anything is sent to it; the
        // fixture's own answer is what this caller is not waiting for.
        $this->waitForListener();
    }

    public function stop(): void
    {
        if ($this->server !== null) {
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }

        foreach (glob($this->stateDir . '/*.json') ?: [] as $file) {
            unlink($file);
        }

        if ($this->ownsStateDir && is_dir($this->stateDir)) {
            rmdir($this->stateDir);
        }
    }

    #[\Override]
    public function dispatch(WireRequest $request, ResponseSpec $response): Outcome
    {
        $id = bin2hex(random_bytes(16));
        [$wire, $span] = $this->exchange($this->rawHttpRequest($request, $response, $id));

        $observedFile = "{$this->stateDir}/{$id}.json";
        $observed = null;

        if (is_file($observedFile)) {
            /** @var array<string, mixed> $data */
            $data = json_decode((string) file_get_contents($observedFile), true, flags: JSON_THROW_ON_ERROR);
            $observed = ObservedRequest::fromArray($data);
            unlink($observedFile);
        }

        return new Outcome($observed, self::parseResponse($wire, $span));
    }

    #[\Override]
    public function expectedClientIp(): string
    {
        return $this->clientIp;
    }

    #[\Override]
    public function supportsStreaming(): bool
    {
        return true;
    }

    #[\Override]
    public function unparseableFormRequest(): WireRequest
    {
        // A multipart content type with no boundary parameter names no
        // delimiter at all, so there is nothing to split the body on —
        // the same trigger every other driver uses, because with
        // enable_post_data_reading off this environment runs the same
        // Kinetis\Http\Form parser they do rather than PHP's.
        return new WireRequest(
            'POST',
            '/',
            headers: [['Content-Type', 'multipart/form-data']],
            body: 'not a multipart body at all',
        );
    }

    #[\Override]
    public function expectedScheme(): string
    {
        return 'http';
    }

    #[\Override]
    public function preservesNumericHeaderNames(): bool
    {
        return true;
    }

    #[\Override]
    public function preservesCookieOrder(): bool
    {
        return true;
    }

    #[\Override]
    public function trustsTheConnectingClient(): bool
    {
        return $this->trustedProxies !== '';
    }

    /**
     * Sends $raw and reads the whole response, timing the body: the
     * seconds between the read that delivered the first body byte and
     * the read that delivered the last one. Not "until the connection
     * closed" — a server that buffers the whole body and then sits on
     * the FIN would look like a slow stream by that measure; the last
     * *body-bearing* read is what says when the bytes actually arrived.
     *
     * @return array{0: string, 1: ?float}
     */
    private function exchange(string $raw): array
    {
        $socket = @stream_socket_client("tcp://{$this->hostPort}", $errno, $errstr, 5.0);

        if ($socket === false) {
            throw new RuntimeException("Could not connect to the conformance fixture server at {$this->hostPort}: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, 15);
        self::writeAll($socket, $raw);

        $wire = '';
        $bodyStartsAt = null;
        $firstBodyReadAt = null;
        $lastBodyReadAt = null;

        while (!feof($socket)) {
            $chunk = fread($socket, 65_536);

            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($socket);

                if ($meta['timed_out']) {
                    throw new RuntimeException('Timed out reading from the conformance fixture server.');
                }

                continue;
            }

            $wire .= $chunk;

            if ($bodyStartsAt === null) {
                $separator = strpos($wire, "\r\n\r\n");

                if ($separator !== false) {
                    $bodyStartsAt = $separator + 4;
                }
            }

            if ($bodyStartsAt !== null && strlen($wire) > $bodyStartsAt) {
                $now = microtime(true);
                $firstBodyReadAt ??= $now;
                $lastBodyReadAt = $now;
            }
        }

        fclose($socket);

        if ($wire === '') {
            throw new RuntimeException('The conformance fixture server closed the connection without a response.');
        }

        return [$wire, $firstBodyReadAt === null || $lastBodyReadAt === null ? null : $lastBodyReadAt - $firstBodyReadAt];
    }

    /**
     * A socket write can be partial — a 1 MiB request body will not go
     * out in one fwrite() — so keep writing until every byte has, or say
     * so rather than let a truncated request masquerade as an adapter
     * failure.
     *
     * @param resource $socket
     */
    private static function writeAll($socket, string $data): void
    {
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $written = fwrite($socket, substr($data, $offset));

            if ($written === false || $written === 0) {
                $meta = stream_get_meta_data($socket);
                $reason = $meta['timed_out'] ? 'timed out' : 'the write failed';

                throw new RuntimeException("Could not send the full request to the conformance fixture server: {$reason} after {$offset} of {$length} bytes.");
            }

            $offset += $written;
        }
    }

    private function rawHttpRequest(WireRequest $request, ResponseSpec $response, string $id): string
    {
        $target = $request->path . ($request->queryString !== '' ? '?' . $request->queryString : '');

        // The client's own Host when it declared one, this driver's
        // listener otherwise. One Host header either way: two is a
        // malformed request, and a request that declares an authority is
        // exactly how the suite checks the adapter reads it from the
        // request rather than from wherever it happens to be listening.
        $host = self::declaredHost($request) ?? $this->hostPort;

        $lines = [
            "{$request->method} {$target} HTTP/1.1",
            "Host: {$host}",
            'Connection: close',
            "X-Conformance-Id: {$id}",
            'X-Conformance-Response: ' . base64_encode(json_encode($response->toArray(), JSON_THROW_ON_ERROR)),
        ];

        foreach ($request->headers as [$name, $value]) {
            if (strcasecmp($name, 'Host') === 0) {
                continue;
            }

            $lines[] = "{$name}: {$value}";
        }

        if ($request->cookies !== []) {
            $lines[] = 'Cookie: ' . implode('; ', $request->cookies);
        }

        // Only when the request didn't declare one itself: a supplied
        // Content-Length goes out as given, so the suite can check it
        // arrives as given.
        $declaresLength = array_any($request->headers, static fn (array $pair): bool => strcasecmp($pair[0], 'Content-Length') === 0);

        if (!$declaresLength && ($request->body !== '' || in_array($request->method, ['POST', 'PUT', 'PATCH'], true))) {
            $lines[] = 'Content-Length: ' . strlen($request->body);
        }

        return implode("\r\n", $lines) . "\r\n\r\n" . $request->body;
    }

    private static function declaredHost(WireRequest $request): ?string
    {
        foreach ($request->headers as [$name, $value]) {
            if (strcasecmp($name, 'Host') === 0) {
                return $value;
            }
        }

        return null;
    }

    private static function parseResponse(string $wire, ?float $bodyArrivalSpan): WireResponse
    {
        $separator = strpos($wire, "\r\n\r\n");

        if ($separator === false) {
            throw new RuntimeException('Malformed HTTP response from the conformance fixture server: ' . $wire);
        }

        $head = substr($wire, 0, $separator);
        $body = substr($wire, $separator + 4);
        $lines = explode("\r\n", $head);
        $statusLine = array_shift($lines);

        if (preg_match('~^HTTP/\d\.\d (\d{3})~', $statusLine, $matches) !== 1) {
            throw new RuntimeException("Malformed status line from the conformance fixture server: {$statusLine}");
        }

        $headers = [];
        $setCookies = [];
        $chunked = false;

        foreach ($lines as $line) {
            [$name, $value] = array_map(trim(...), explode(':', $line, 2) + [1 => '']);

            if (strcasecmp($name, 'Set-Cookie') === 0) {
                $setCookies[] = $value;

                continue;
            }

            if (strcasecmp($name, 'Transfer-Encoding') === 0 && strcasecmp($value, 'chunked') === 0) {
                $chunked = true;
            }

            $headers[] = [$name, $value];
        }

        return new WireResponse((int) $matches[1], $headers, $setCookies, $chunked ? self::dechunk($body) : $body, $bodyArrivalSpan);
    }

    /**
     * A flushed, length-less body goes out as HTTP/1.1 chunked transfer
     * coding; the suite wants the bytes the client would see after its
     * own decoding.
     */
    private static function dechunk(string $body): string
    {
        $out = '';
        $offset = 0;
        $length = strlen($body);

        while ($offset < $length) {
            $lineEnd = strpos($body, "\r\n", $offset);

            if ($lineEnd === false) {
                break;
            }

            $size = hexdec(trim(substr($body, $offset, $lineEnd - $offset)));

            if ($size === 0) {
                break;
            }

            $out .= substr($body, $lineEnd + 2, (int) $size);
            $offset = $lineEnd + 2 + (int) $size + 2;
        }

        return $out;
    }

    /**
     * Ready means the fixture answers through the whole path — not that
     * something accepted a TCP connection, which nginx does before the
     * PHP-FPM pool behind it is up (a 502 for the first real request).
     * Polls the fixture's own /__conformance/ready until it says 204.
     */
    private function waitForListener(): void
    {
        $deadline = microtime(true) + 10.0;

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client("tcp://{$this->hostPort}", $errno, $errstr, 0.5);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(20_000);
        }

        throw new RuntimeException("The conformance fixture server at {$this->hostPort} never started listening.");
    }

    private function waitForServerReady(): void
    {
        $deadline = microtime(true) + 30.0;
        $lastSeen = 'no connection';

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client("tcp://{$this->hostPort}", timeout: 1.0);

            if ($socket !== false) {
                stream_set_timeout($socket, 2);
                self::writeAll($socket, "GET /__conformance/ready HTTP/1.1\r\nHost: {$this->hostPort}\r\nConnection: close\r\n\r\n");
                $statusLine = (string) fgets($socket);
                fclose($socket);

                if (str_starts_with($statusLine, 'HTTP/1.1 204') || str_starts_with($statusLine, 'HTTP/1.0 204')) {
                    return;
                }

                $lastSeen = trim($statusLine) !== '' ? trim($statusLine) : 'an empty response';
            }

            usleep(100_000);
        }

        throw new RuntimeException("The conformance fixture at {$this->hostPort} never answered /__conformance/ready with 204 (last seen: {$lastSeen}).");
    }
}
