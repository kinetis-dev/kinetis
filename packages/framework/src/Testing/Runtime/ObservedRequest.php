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
 */
final readonly class ObservedRequest
{
    /**
     * @param array<array-key, mixed> $queryParams keys may be int — PHP
     *     coerces a numeric-string array key, on every adapter alike
     * @param array<array-key, array<string>> $headers
     * @param array<string, string> $cookieParams
     * @param array<string, mixed>|null $parsedBody
     * @param list<array{field: string, filename: ?string, mediaType: ?string, contents: string}> $uploadedFiles
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
    ) {}

    public static function fromServerRequest(ServerRequestInterface $request): self
    {
        $files = [];

        foreach ($request->getUploadedFiles() as $field => $file) {
            if (!$file instanceof UploadedFileInterface) {
                continue;
            }

            $files[] = [
                'field' => (string) $field,
                'filename' => $file->getClientFilename(),
                'mediaType' => $file->getClientMediaType(),
                'contents' => (string) $file->getStream(),
            ];
        }

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
        );
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
                    'contents' => base64_encode($file['contents']),
                ],
                $this->uploadedFiles,
            ),
            'body' => base64_encode($this->body),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array{field: string, filename: ?string, mediaType: ?string, contents: string}> $files */
        $files = array_map(
            static fn (array $file): array => [
                'field' => (string) $file['field'],
                'filename' => is_string($file['filename']) ? $file['filename'] : null,
                'mediaType' => is_string($file['mediaType']) ? $file['mediaType'] : null,
                'contents' => (string) base64_decode((string) $file['contents'], strict: true),
            ],
            (array) $data['uploadedFiles'],
        );

        /** @var array<array-key, mixed> $queryParams */
        $queryParams = $data['queryParams'];
        /** @var array<array-key, array<string>> $headers */
        $headers = $data['headers'];
        /** @var array<string, string> $cookieParams */
        $cookieParams = $data['cookieParams'];
        /** @var array<string, mixed>|null $parsedBody */
        $parsedBody = $data['parsedBody'];
        $remoteAddr = $data['remoteAddr'];

        return new self(
            (string) $data['method'],
            (string) $data['path'],
            (string) $data['query'],
            $queryParams,
            $headers,
            $cookieParams,
            is_string($remoteAddr) ? $remoteAddr : null,
            $parsedBody,
            $files,
            (string) base64_decode((string) $data['body'], strict: true),
        );
    }
}
