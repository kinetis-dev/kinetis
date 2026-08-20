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
 * Drives SuperglobalsBridge — the conversion FpmAdapter and
 * FrankenPhpAdapter share — through a real `php -S` process serving
 * Fixtures/conformance-front-controller.php. A real server is not a
 * preference here: `php://input` cannot be fed from inside the test
 * process, and `request_parse_body()`'s failure modes only reproduce in
 * a genuine SAPI request context. What this proves is the bridge under
 * the CLI server's superglobal population; FrankenPHP's own loop stays
 * covered by the real-container smoke test.
 *
 * Requests go out as hand-written HTTP/1.1 over a socket rather than
 * through the `http://` stream wrapper, so a repeated header and a
 * binary body reach the server byte-exact, and repeated `Set-Cookie`
 * lines come back as separate lines.
 */
final class SuperglobalsDriver implements RuntimeAdapterDriver
{
    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    private string $stateDir = '';

    public function start(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/kinetis-conformance-' . bin2hex(random_bytes(8));
        mkdir($this->stateDir);
        $this->port = FreePort::reserve();

        $server = proc_open(
            [
                'php',
                // Low enough that the 5 KB body unparseableFormRequest()
                // sends trips request_parse_body() — the only way the
                // bridge's own parse-failure path can be reached — and
                // high enough that the suite's ordinary multipart bodies
                // (a few hundred bytes) parse normally. The SAPI drops
                // $_POST silently past post_max_size, with no exception.
                '-d', 'post_max_size=2K',
                '-d', 'upload_max_filesize=1K',
                '-S', "127.0.0.1:{$this->port}",
                __DIR__ . '/Fixtures/conformance-front-controller.php',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [...getenv(), 'KINETIS_CONFORMANCE_STATE_DIR' => $this->stateDir],
        );

        if ($server === false) {
            throw new RuntimeException('Could not start the conformance fixture server.');
        }

        $this->server = $server;
        $this->waitForServerReady();
    }

    public function stop(): void
    {
        if ($this->server !== null) {
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }

        foreach (glob($this->stateDir . '/*') ?: [] as $file) {
            unlink($file);
        }

        if ($this->stateDir !== '' && is_dir($this->stateDir)) {
            rmdir($this->stateDir);
        }
    }

    #[\Override]
    public function dispatch(WireRequest $request, ResponseSpec $response): Outcome
    {
        $id = bin2hex(random_bytes(16));
        $raw = $this->rawHttpRequest($request, $response, $id);

        $socket = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $errstr, 5.0);

        if ($socket === false) {
            throw new RuntimeException("Could not connect to the conformance fixture server: {$errstr} ({$errno})");
        }

        fwrite($socket, $raw);
        $wire = stream_get_contents($socket);
        fclose($socket);

        if ($wire === false || $wire === '') {
            throw new RuntimeException('The conformance fixture server closed the connection without a response.');
        }

        $observedFile = "{$this->stateDir}/{$id}.json";
        $observed = null;

        if (is_file($observedFile)) {
            /** @var array<string, mixed> $data */
            $data = json_decode((string) file_get_contents($observedFile), true, flags: JSON_THROW_ON_ERROR);
            $observed = ObservedRequest::fromArray($data);
            unlink($observedFile);
        }

        return new Outcome($observed, self::parseResponse($wire));
    }

    #[\Override]
    public function expectedClientIp(): string
    {
        // A real TCP connection from this process to the fixture server.
        return '127.0.0.1';
    }

    #[\Override]
    public function supportsStreaming(): bool
    {
        return true;
    }

    #[\Override]
    public function unparseableFormRequest(): WireRequest
    {
        // Far past the fixture server's post_max_size=2K. PUT, not POST:
        // the SAPI handles an oversized POST body itself (an empty $_POST
        // and a warning, no exception), whereas PUT reaches
        // request_parse_body(), the one call in the bridge that can throw.
        return new WireRequest(
            'PUT',
            '/',
            headers: [['Content-Type', 'multipart/form-data; boundary=----XYZ']],
            body: '------XYZ' . str_repeat('A', 5_000) . '------XYZ--',
        );
    }

    private function rawHttpRequest(WireRequest $request, ResponseSpec $response, string $id): string
    {
        $target = $request->path . ($request->queryString !== '' ? '?' . $request->queryString : '');
        $lines = [
            "{$request->method} {$target} HTTP/1.1",
            "Host: 127.0.0.1:{$this->port}",
            'Connection: close',
            "X-Conformance-Id: {$id}",
            'X-Conformance-Response: ' . base64_encode(json_encode($response->toArray(), JSON_THROW_ON_ERROR)),
        ];

        foreach ($request->headers as [$name, $value]) {
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

    private static function parseResponse(string $wire): WireResponse
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

        return new WireResponse((int) $matches[1], $headers, $setCookies, $chunked ? self::dechunk($body) : $body);
    }

    /**
     * The built-in server streams a flushed, length-less body as HTTP/1.1
     * chunked transfer coding; the suite wants the bytes the client would
     * see after its own decoding.
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

    private function waitForServerReady(): void
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client("tcp://127.0.0.1:{$this->port}", timeout: 0.1);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(20_000);
        }

        throw new RuntimeException("The conformance fixture server at 127.0.0.1:{$this->port} never started accepting connections.");
    }
}
