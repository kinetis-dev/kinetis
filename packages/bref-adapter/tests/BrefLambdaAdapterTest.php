<?php

declare(strict_types=1);

namespace Kinetis\BrefAdapter\Tests;

use Kinetis\BrefAdapter\BrefLambdaAdapter;
use Kinetis\Http\StreamedResponse;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BrefLambdaAdapterTest extends TestCase
{
    public function test_is_persistent(): void
    {
        self::assertTrue((new BrefLambdaAdapter('127.0.0.1:9001'))->isPersistent());
    }

    public function test_converts_an_api_gateway_v2_event_into_a_psr7_request(): void
    {
        $event = [
            'rawPath' => '/users/42',
            'rawQueryString' => 'limit=5',
            'headers' => ['content-type' => 'application/json'],
            'queryStringParameters' => ['limit' => '5'],
            'requestContext' => ['http' => ['method' => 'GET']],
            'body' => '',
            'isBase64Encoded' => false,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertSame('GET', $request->getMethod());
        self::assertSame('/users/42', $request->getUri()->getPath());
        self::assertSame('limit=5', $request->getUri()->getQuery());
        self::assertSame('application/json', $request->getHeaderLine('content-type'));
        self::assertSame(['limit' => '5'], $request->getQueryParams());
    }

    public function test_decodes_a_base64_encoded_body(): void
    {
        $event = [
            'rawPath' => '/users',
            'requestContext' => ['http' => ['method' => 'POST']],
            'body' => base64_encode('{"name":"Alon"}'),
            'isBase64Encoded' => true,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertSame('{"name":"Alon"}', (string) $request->getBody());
    }

    public function test_converts_a_psr7_response_into_a_lambda_payload(): void
    {
        $response = new Response(201, ['Content-Type' => 'application/json'], '{"id":1}');

        $payload = BrefLambdaAdapter::responseToPayload($response);

        self::assertSame(201, $payload['statusCode']);
        self::assertSame('application/json', $payload['headers']['Content-Type']);
        self::assertSame('{"id":1}', $payload['body']);
        self::assertFalse($payload['isBase64Encoded']);
    }

    public function test_a_streamed_response_is_rejected_since_the_runtime_api_has_no_partial_delivery(): void
    {
        $response = new StreamedResponse(new Response(200), static function (): void {});

        $this->expectException(RuntimeException::class);

        BrefLambdaAdapter::responseToPayload($response);
    }

    public function test_a_multipart_body_is_parsed_into_fields_and_uploaded_files(): void
    {
        $boundary = 'KinetisTestBoundary';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"name\"\r\n\r\n"
            . "Alon\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"avatar\"; filename=\"avatar.png\"\r\n"
            . "Content-Type: image/png\r\n\r\n"
            . "fake image bytes\r\n"
            . "--{$boundary}--\r\n";

        $event = [
            'rawPath' => '/avatars',
            'headers' => ['content-type' => "multipart/form-data; boundary={$boundary}"],
            'requestContext' => ['http' => ['method' => 'POST']],
            'body' => $body,
            'isBase64Encoded' => false,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertSame(['name' => 'Alon'], $request->getParsedBody());

        $files = $request->getUploadedFiles();
        self::assertArrayHasKey('avatar', $files);
        self::assertSame('avatar.png', $files['avatar']->getClientFilename());
        self::assertSame('image/png', $files['avatar']->getClientMediaType());
        self::assertSame('fake image bytes', (string) $files['avatar']->getStream());
    }

    public function test_a_base64_encoded_multipart_body_is_decoded_before_parsing(): void
    {
        $boundary = 'KinetisTestBoundary';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"name\"\r\n\r\n"
            . "Alon\r\n"
            . "--{$boundary}--\r\n";

        $event = [
            'rawPath' => '/avatars',
            'headers' => ['content-type' => "multipart/form-data; boundary={$boundary}"],
            'requestContext' => ['http' => ['method' => 'POST']],
            'body' => base64_encode($body),
            'isBase64Encoded' => true,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertSame(['name' => 'Alon'], $request->getParsedBody());
    }

    public function test_a_url_encoded_body_is_parsed_via_parse_str(): void
    {
        $event = [
            'rawPath' => '/avatars',
            'headers' => ['content-type' => 'application/x-www-form-urlencoded'],
            'requestContext' => ['http' => ['method' => 'POST']],
            'body' => 'name=Url+Encoded&limit=5',
            'isBase64Encoded' => false,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertSame(['name' => 'Url Encoded', 'limit' => '5'], $request->getParsedBody());
        self::assertSame([], $request->getUploadedFiles());
    }

    public function test_a_json_body_is_left_unparsed(): void
    {
        $event = [
            'rawPath' => '/users',
            'headers' => ['content-type' => 'application/json'],
            'requestContext' => ['http' => ['method' => 'POST']],
            'body' => '{"name":"Alon"}',
            'isBase64Encoded' => false,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertNull($request->getParsedBody());
        self::assertSame('{"name":"Alon"}', (string) $request->getBody());
    }
}
