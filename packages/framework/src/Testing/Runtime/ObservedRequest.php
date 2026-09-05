<?php

declare(strict_types=1);

namespace Kinetis\Testing\Runtime;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * What the handler actually received — the PSR-7 request an adapter
 * built, flattened to plain data so it can be captured inside a fixture
 * process and read back in the test process (see the superglobals
 * driver), or filled directly from the live object by an in-process
 * driver. One definition of "observed", shared by both.
 *
 * Body and file contents are base64 in {@see toArray()} only so the
 * envelope survives JSON: the suite sends genuinely binary bodies, and
 * `json_encode()` rejects anything that isn't valid UTF-8.
 *
 * The last five fields are the request's identity — the scheme, host and
 * optional port of its URI, the protocol version it arrived on, and the
 * request target as sent. They are recorded separately from `path` and
 * `query` because an adapter can rebuild a plausible path and query
 * while getting the authority or the target wrong, and only comparing
 * them against what the client actually sent shows it.
 */
final readonly class ObservedRequest
{
    /**
     * @param array<array-key, mixed> $queryParams keys may be int — PHP
     *     coerces a numeric-string array key, on every adapter alike
     * @param array<array-key, array<string>> $headers
     * @param array<string, string> $cookieParams
     * @param array<string, mixed>|null $parsedBody
     * @param list<array{field: string, filename: ?string, mediaType: ?string, error: int, contents: string}> $uploadedFiles
     *     one entry per uploaded file, `field` the full dotted path it
     *     nests under (`docs.0`), so a duplicated or nested file name
     *     stays distinguishable after the JSON round trip. `error` is the
     *     PHP upload code: a file input the user left alone is submitted
     *     as an empty part and reported as `UPLOAD_ERR_NO_FILE` on every
     *     runtime, which is what upload validation written against PHP
     *     actually checks.
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $query,
        public array $queryParams,
        public array $headers,
        public array $cookieParams,
        public ?string $remoteAddr,
        public ?array $parsedBody,
        public array $uploadedFiles,
        public string $body,
        public string $scheme,
        public string $host,
        public ?int $port,
        public string $protocolVersion,
        public string $requestTarget,
    ) {}

    public static function fromServerRequest(ServerRequestInterface $request): self
    {
        $files = self::flattenUploadedFiles($request->getUploadedFiles(), '');

        $parsedBody = $request->getParsedBody();
        $remoteAddr = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        /** @var array<array-key, mixed> $queryParams */
        $queryParams = $request->getQueryParams();
        /** @var array<string, string> $cookieParams */
        $cookieParams = $request->getCookieParams();

        return new self(
            $request->getMethod(),
            $request->getUri()->getPath(),
            $request->getUri()->getQuery(),
            $queryParams,
            $request->getHeaders(),
            $cookieParams,
            is_string($remoteAddr) ? $remoteAddr : null,
            is_array($parsedBody) ? $parsedBody : null,
            $files,
            (string) $request->getBody(),
            $request->getUri()->getScheme(),
            $request->getUri()->getHost(),
            $request->getUri()->getPort(),
            $request->getProtocolVersion(),
            $request->getRequestTarget(),
        );
    }

    /**
     * `getUploadedFiles()` is a tree, not a list — `docs[]` twice nests
     * two files under one key, `user[avatar]` nests one under two — and
     * the suite has to be able to tell those apart to check that every
     * adapter builds the same tree. Each file is reported under the
     * dotted path it actually sits at.
     *
     * @param array<array-key, mixed> $files
     * @return list<array{field: string, filename: ?string, mediaType: ?string, error: int, contents: string}>
     */
    private static function flattenUploadedFiles(array $files, string $prefix): array
    {
        $flattened = [];

        foreach ($files as $key => $file) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($file)) {
                $flattened = [...$flattened, ...self::flattenUploadedFiles($file, $path)];

                continue;
            }

            if (!$file instanceof UploadedFileInterface) {
                continue;
            }

            $error = $file->getError();

            $flattened[] = [
                'field' => $path,
                'filename' => $file->getClientFilename(),
                'mediaType' => $file->getClientMediaType(),
                'error' => $error,
                // PSR-7 requires getStream() to throw for any file whose
                // error is not UPLOAD_ERR_OK, so there are no bytes to
                // read and asking for them would fail the observation
                // rather than record it.
                'contents' => $error === UPLOAD_ERR_OK ? (string) $file->getStream() : '',
            ];
        }

        return $flattened;
    }

    /**
     * Header lookup by name, case-insensitively — the PSR-7 map keeps
     * whatever case the adapter stored, which differs per environment.
     *
     * @return list<string>
     */
    public function header(string $name): array
    {
        foreach ($this->headers as $stored => $values) {
            // (string): a purely-numeric header name is an int key here
            // on every adapter — the JSON round trip and PHP's own array
            // key coercion both see to that — but the name is a string.
            if (strcasecmp((string) $stored, $name) === 0) {
                return array_values($values);
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'path' => $this->path,
            'query' => $this->query,
            'queryParams' => $this->queryParams,
            'headers' => $this->headers,
            'cookieParams' => $this->cookieParams,
            'remoteAddr' => $this->remoteAddr,
            'parsedBody' => $this->parsedBody,
            'uploadedFiles' => array_map(
                static fn (array $file): array => [
                    'field' => $file['field'],
                    'filename' => $file['filename'],
                    'mediaType' => $file['mediaType'],
                    'error' => $file['error'],
                    'contents' => base64_encode($file['contents']),
                ],
                $this->uploadedFiles,
            ),
            'body' => base64_encode($this->body),
            'scheme' => $this->scheme,
            'host' => $this->host,
            'port' => $this->port,
            'protocolVersion' => $this->protocolVersion,
            'requestTarget' => $this->requestTarget,
        ];
    }

    /**
     * The exact inverse of {@see toArray()}: every field required, every
     * type checked, every base64 payload decoded strictly.
     *
     * An observation crosses a process boundary — a fixture running under
     * a real SAPI writes it, the test process reads it back — and every
     * conformance assertion in the suite is made against what comes out
     * of here. A cast would turn a field that arrived as the wrong type
     * into a plausible value, and a lenient base64 decode would turn a
     * body that did not survive the boundary into an empty one, which is
     * indistinguishable from a request that had no body at all. Either
     * would report a broken fixture or driver as a passing conformance
     * run, which is worse than no run at all.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::requireString($data, 'method'),
            self::requireString($data, 'path'),
            self::requireString($data, 'query'),
            self::requireArray($data, 'queryParams'),
            self::requireHeaders($data),
            self::requireStringMap($data, 'cookieParams'),
            self::requireNullableString($data, 'remoteAddr'),
            self::requireNullableArray($data, 'parsedBody'),
            self::requireUploadedFiles($data),
            self::decode(self::requireString($data, 'body')),
            self::requireString($data, 'scheme'),
            self::requireString($data, 'host'),
            self::requireNullableInt($data, 'port'),
            self::requireString($data, 'protocolVersion'),
            self::requireString($data, 'requestTarget'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function field(array $data, string $field): mixed
    {
        return array_key_exists($field, $data) ? $data[$field] : throw MalformedObservationException::missingField($field);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requireString(array $data, string $field): string
    {
        $value = self::field($data, $field);

        return is_string($value) ? $value : throw MalformedObservationException::wrongType($field, 'a string');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requireNullableString(array $data, string $field): ?string
    {
        $value = self::field($data, $field);

        if ($value === null || is_string($value)) {
            return $value;
        }

        throw MalformedObservationException::wrongType($field, 'a string or null');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requireNullableInt(array $data, string $field): ?int
    {
        $value = self::field($data, $field);

        if ($value === null || is_int($value)) {
            return $value;
        }

        throw MalformedObservationException::wrongType($field, 'an integer or null');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function requireArray(array $data, string $field): array
    {
        $value = self::field($data, $field);

        return is_array($value) ? $value : throw MalformedObservationException::wrongType($field, 'an array');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private static function requireNullableArray(array $data, string $field): ?array
    {
        $value = self::field($data, $field);

        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw MalformedObservationException::wrongType($field, 'an array or null');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private static function requireStringMap(array $data, string $field): array
    {
        $value = self::requireArray($data, $field);

        foreach ($value as $key => $entry) {
            if (!is_string($key) || !is_string($entry)) {
                throw MalformedObservationException::wrongType($field, 'a map of strings to strings');
            }
        }

        /** @var array<string, string> $value */
        return $value;
    }

    /**
     * The PSR-7 header map, whose keys may be int — a purely-numeric
     * header name takes that route on every adapter — and whose values
     * are always lists of strings.
     *
     * @param array<string, mixed> $data
     * @return array<array-key, array<string>>
     */
    private static function requireHeaders(array $data): array
    {
        $headers = self::requireArray($data, 'headers');

        foreach ($headers as $values) {
            if (!is_array($values) || !array_is_list($values) || array_any($values, static fn (mixed $entry): bool => !is_string($entry))) {
                throw MalformedObservationException::wrongType('headers', 'a map of names to lists of strings');
            }
        }

        /** @var array<array-key, array<string>> $headers */
        return $headers;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{field: string, filename: ?string, mediaType: ?string, error: int, contents: string}>
     */
    private static function requireUploadedFiles(array $data): array
    {
        $files = self::requireArray($data, 'uploadedFiles');

        if (!array_is_list($files)) {
            throw MalformedObservationException::wrongType('uploadedFiles', 'a list of uploaded files');
        }

        $flattened = [];

        foreach ($files as $file) {
            if (!is_array($file)) {
                throw MalformedObservationException::wrongType('uploadedFiles', 'a list of uploaded files');
            }

            /** @var array<string, mixed> $file */
            $flattened[] = [
                'field' => self::requireString($file, 'field'),
                'filename' => self::requireNullableString($file, 'filename'),
                'mediaType' => self::requireNullableString($file, 'mediaType'),
                'error' => self::requireInt($file, 'error'),
                'contents' => self::decode(self::requireString($file, 'contents')),
            ];
        }

        return $flattened;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requireInt(array $data, string $field): int
    {
        $value = self::field($data, $field);

        return is_int($value) ? $value : throw MalformedObservationException::wrongType($field, 'an integer');
    }

    private static function decode(string $base64): string
    {
        $decoded = base64_decode($base64, strict: true);

        return $decoded === false ? throw MalformedObservationException::invalidBase64() : $decoded;
    }
}
