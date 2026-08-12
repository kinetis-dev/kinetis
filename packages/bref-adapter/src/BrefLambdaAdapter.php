<?php

declare(strict_types=1);

namespace Kinetis\BrefAdapter;

use Kinetis\BrefAdapter\Exception\BrefAdapterException;
use Kinetis\Runtime\Exception\RuntimeUnavailableException;
use Kinetis\Runtime\RuntimeAdapterInterface;
use Kinetis\Runtime\StreamableResponseInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Riverline\MultiPartParser\StreamedPart;
use Throwable;

/**
 * Bridges AWS Lambda's Runtime API to the Kernel, so the same application
 * code that runs under FrankenPHP or FPM also runs behind API Gateway
 * without changes. Polls .../runtime/invocation/next for the next event,
 * converts it to PSR-7, and posts the Kernel's response back as the
 * function's return payload. Supports the API Gateway HTTP API (payload
 * format 2.0) event shape used by Bref-style Lambda functions; ALB/REST
 * API (1.0) payloads aren't handled yet.
 *
 * Talks to the Runtime API with plain stream-context HTTP rather than
 * ext-curl or the bref/bref package: it's a synchronous request/response
 * loop with no need for anything heavier, and every extra dependency here
 * is more surface for a framework that's supposed to stay runtime-agnostic
 * at its core.
 *
 * Lives in its own package, not kinetis/kinetis core, specifically because of
 * the multipart/form-data handling below: a Lambda event's body arrives as
 * one in-memory string with no live php://input stream behind it, so PHP
 * 8.4's request_parse_body() (what FrankenPhpAdapter/FpmAdapter use for the
 * same problem in core) can't help here — it's stream-bound. Parsing an
 * arbitrary multipart string needs riverline/multipart-parser, and pulling
 * that into every Kinetis install just for a deployment target most
 * consumers don't use isn't worth it.
 */
final class BrefLambdaAdapter implements RuntimeAdapterInterface
{
    private const RUNTIME_HEADER_PREFIX = 'lambda-runtime-aws-request-id:';

    public function __construct(
        private readonly string $runtimeApi,
    ) {}

    /**
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    #[\Override]
    public function run(callable $handler): void
    {
        // Intentionally infinite: this loop *is* the Lambda execution
        // model — the runtime freezes/kills the process between
        // invocations rather than this method ever returning normally.
        // @phpstan-ignore-next-line while.alwaysTrue
        while (true) {
            [$requestId, $event] = $this->nextInvocation();

            try {
                $response = $handler(self::requestFromEvent($event));
                $this->postResponse($requestId, self::responseToPayload($response));
            } catch (Throwable $e) {
                $this->postError($requestId, $e);
            }
        }
    }

    #[\Override]
    public function isPersistent(): bool
    {
        return true;
    }

    /**
     * @param array<string,mixed> $event
     */
    public static function requestFromEvent(array $event): ServerRequestInterface
    {
        $factory = new Psr17Factory();

        $method = is_string($event['requestContext']['http']['method'] ?? null)
            ? $event['requestContext']['http']['method']
            : 'GET';
        $path = is_string($event['rawPath'] ?? null) ? $event['rawPath'] : '/';
        $query = is_string($event['rawQueryString'] ?? null) ? $event['rawQueryString'] : '';

        $request = $factory->createServerRequest($method, $path . ($query !== '' ? "?{$query}" : ''));

        /** @var array<string,string> $headers */
        $headers = is_array($event['headers'] ?? null) ? $event['headers'] : [];

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        /** @var array<string,string> $queryParams */
        $queryParams = is_array($event['queryStringParameters'] ?? null) ? $event['queryStringParameters'] : [];
        $request = $request->withQueryParams($queryParams);

        $body = is_string($event['body'] ?? null) ? $event['body'] : '';

        if (($event['isBase64Encoded'] ?? false) === true) {
            $body = base64_decode($body) ?: '';
        }

        $request = $request->withBody($factory->createStream($body));

        $contentType = $request->getHeaderLine('Content-Type');

        if (self::isFormEncoded($contentType)) {
            [$parsedBody, $uploadedFiles] = self::isMultipart($contentType)
                ? self::parseMultipart($contentType, $body)
                : self::parseUrlEncoded($body);

            $request = $request->withParsedBody($parsedBody)->withUploadedFiles($uploadedFiles);
        }

        return $request;
    }

    /**
     * @return array{statusCode:int,headers:array<string,string>,body:string,isBase64Encoded:bool}
     */
    public static function responseToPayload(ResponseInterface $response): array
    {
        // The Lambda Runtime API's poll/respond contract is strictly one
        // invocation → one response payload. Lambda response streaming
        // needs Function URLs with InvokeMode: RESPONSE_STREAM — a
        // different invocation model this next/response-polling adapter
        // doesn't implement.
        if ($response instanceof StreamableResponseInterface) {
            throw BrefAdapterException::streamingNotSupported();
        }

        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return [
            'statusCode' => $response->getStatusCode(),
            'headers' => $headers,
            'body' => (string) $response->getBody(),
            'isBase64Encoded' => false,
        ];
    }

    private static function isFormEncoded(string $contentType): bool
    {
        return self::isMultipart($contentType)
            || str_starts_with($contentType, 'application/x-www-form-urlencoded');
    }

    private static function isMultipart(string $contentType): bool
    {
        return str_starts_with($contentType, 'multipart/form-data');
    }

    /**
     * riverline/multipart-parser expects a stream carrying the Content-Type
     * header (for boundary detection) followed by a blank line, then the
     * body — the shape of one raw HTTP part — so the header stripped off by
     * API Gateway is prepended back on before parsing.
     *
     * @return array{0:array<string,string>,1:array<string,UploadedFile>}
     */
    private static function parseMultipart(string $contentType, string $body): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw BrefAdapterException::couldNotOpenTempStream();
        }

        fwrite($stream, "Content-Type: {$contentType}\r\n\r\n" . $body);
        rewind($stream);

        $fields = [];
        $files = [];

        foreach ((new StreamedPart($stream))->getParts() as $part) {
            $name = $part->getName();

            if ($name === null) {
                continue;
            }

            if ($part->isFile()) {
                $contents = $part->getBody();
                $files[$name] = new UploadedFile(
                    Stream::create($contents),
                    strlen($contents),
                    UPLOAD_ERR_OK,
                    $part->getFileName(),
                    $part->getMimeType(),
                );

                continue;
            }

            $fields[$name] = $part->getBody();
        }

        return [$fields, $files];
    }

    /**
     * @return array{0:array<string,string>,1:array<never,never>}
     */
    private static function parseUrlEncoded(string $body): array
    {
        parse_str($body, $fields);

        /** @var array<string,string> $fields */
        return [$fields, []];
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function nextInvocation(): array
    {
        $url = "http://{$this->runtimeApi}/2018-06-01/runtime/invocation/next";
        $responseHeaders = [];
        $body = $this->request($url, 'GET', null, $responseHeaders);

        $requestId = null;

        foreach ($responseHeaders as $header) {
            if (str_starts_with(strtolower($header), self::RUNTIME_HEADER_PREFIX)) {
                $requestId = trim(substr($header, strlen(self::RUNTIME_HEADER_PREFIX)));
            }
        }

        if ($requestId === null) {
            throw RuntimeUnavailableException::missingEnvironmentVariable(self::class, 'Lambda-Runtime-Aws-Request-Id');
        }

        $decoded = json_decode($body, associative: true);

        return [$requestId, is_array($decoded) ? $decoded : []];
    }

    /**
     * @param array{statusCode:int,headers:array<string,string>,body:string,isBase64Encoded:bool} $payload
     */
    private function postResponse(string $requestId, array $payload): void
    {
        $url = "http://{$this->runtimeApi}/2018-06-01/runtime/invocation/{$requestId}/response";
        $this->request($url, 'POST', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function postError(string $requestId, Throwable $e): void
    {
        $url = "http://{$this->runtimeApi}/2018-06-01/runtime/invocation/{$requestId}/error";
        $this->request($url, 'POST', json_encode([
            'errorMessage' => $e->getMessage(),
            'errorType' => $e::class,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string> $responseHeaders
     */
    private function request(string $url, string $method, ?string $body, array &$responseHeaders = []): string
    {
        $http_response_header = null;

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $body !== null ? 'Content-Type: application/json' : '',
                'content' => $body ?? '',
                'ignore_errors' => true,
            ],
        ]);

        $result = file_get_contents($url, context: $context);

        // PHPStan sees the literal `null` assigned above and doesn't know
        // file_get_contents() populates this magic variable as a side
        // effect, so it (wrongly) considers the ?? here redundant.
        // @phpstan-ignore-next-line nullCoalesce.variable
        $responseHeaders = $http_response_header ?? [];

        return $result !== false ? $result : '';
    }
}
