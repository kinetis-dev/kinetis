<?php

declare(strict_types=1);

namespace Kinetis\Neuron;

use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Kinetis\RevoltHttpClient\Http;
use Kinetis\RevoltHttpClient\HttpResponse as KinetisResponse;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;
use SensitiveParameter;

/**
 * Neuron's HTTP seam over {@see Http}: every wait suspends the calling
 * Fiber, and the origin pinning, the operation deadline and the byte
 * ceiling are the configured `Http`'s own.
 *
 * Immutable and free of request data, so one instance can serve every
 * request of a persistent worker. The provider built over it cannot.
 *
 * {@link https://kinetis.dev/docs/neuron.html} states the contract.
 */
final readonly class NeuronHttpClient implements HttpClientInterface
{
    private Http $http;

    /** $baseUrl with exactly one trailing slash: an absolute URI under it is sent relative to it. */
    private string $prefix;

    /**
     * $baseUrl is the provider's own base URI, as the provider writes it
     * (`https://api.openai.com/v1` for Neuron's OpenAI provider), and
     * becomes $http's base URL: every request reaches its origin and
     * nothing else.
     */
    public function __construct(Http $http, #[SensitiveParameter] string $baseUrl)
    {
        $this->http = $http->withBaseUrl($baseUrl);
        $this->prefix = rtrim($baseUrl, '/') . '/';
    }

    #[\Override]
    public function request(HttpRequest $request): HttpResponse
    {
        $response = $this->send($request);

        try {
            $converted = new HttpResponse($response->status(), $response->body(), $response->headers());
        } catch (HttpRequestException $failure) {
            throw self::networkError($request, $failure);
        }

        if ($converted->statusCode >= 400) {
            throw HttpException::statusError($request, $converted);
        }

        return $converted;
    }

    #[\Override]
    public function stream(HttpRequest $request): StreamInterface
    {
        $response = $this->send($request);

        try {
            $status = $response->status();

            // The error body is read whole, under the ceiling, so the
            // exception can carry it as Neuron's other clients do.
            if ($status >= 400) {
                throw HttpException::statusError(
                    $request,
                    new HttpResponse($status, $response->body(), $response->headers()),
                );
            }

            return new ResponseStream($response, $response->chunks(), $request);
        } catch (HttpRequestException $failure) {
            throw self::networkError($request, $failure);
        }
    }

    /** The message is {@see Http}'s own, which names no path, header, credential or body. */
    private static function networkError(HttpRequest $request, HttpRequestException $failure): HttpException
    {
        return HttpException::networkError($request, $failure->getMessage(), $failure);
    }

    /** Dispatches once; {@see Http} never re-sends a POST, and nothing here adds a retry. */
    private function send(HttpRequest $request): KinetisResponse
    {
        if ($request->isMultipart()) {
            throw HttpException::networkError($request, 'Multipart request bodies are not supported.');
        }

        $options = ['headers' => $request->headers];

        if (is_array($request->body)) {
            $options['json'] = $request->body;
        } elseif ($request->body !== null) {
            $options['body'] = $request->body;
        }

        if ($request->timeout !== null) {
            $options['timeout'] = $request->timeout;
        }

        try {
            return $this->http->send($request->method->value, $this->target($request->uri), $options);
        } catch (HttpRequestException $failure) {
            throw self::networkError($request, $failure);
        }
    }

    /**
     * Neuron's providers send absolute URIs under their base URI. One under
     * the configured base goes out relative to it; any other absolute URI,
     * the base URI itself included, is passed on unchanged, and
     * {@see Http} refuses it before anything is sent.
     */
    private function target(#[SensitiveParameter] string $uri): string
    {
        return str_starts_with($uri, $this->prefix) ? substr($uri, strlen($this->prefix)) : $uri;
    }
}
