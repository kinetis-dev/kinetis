<?php

declare(strict_types=1);

namespace Kinetis\BrefAdapter\Tests;

use Kinetis\BrefAdapter\BrefLambdaAdapter;
use Kinetis\BrefAdapter\Exception\MalformedRequestBodyException;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * What only a Lambda event can exhibit, with no cross-adapter contract
 * to hold it to. Everything an adapter shares with the others — request
 * line, headers, cookies, the client address, form/JSON/binary bodies,
 * response headers and cookies, streaming, the parse-failure 400 — lives
 * in the runtime conformance suite, run against this adapter by
 * {@see LambdaConformanceTest}, which also holds the Lambda-only
 * malformed-base64 input to that suite's 400 contract.
 */
final class BrefLambdaAdapterTest extends TestCase
{
    public function test_is_persistent(): void
    {
        self::assertTrue((new BrefLambdaAdapter('127.0.0.1:9001'))->isPersistent());
    }

    /**
     * API Gateway may base64-encode any body, text included — not only
     * the binary ones the conformance suite sends that way.
     */
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

    public function test_a_missing_source_ip_leaves_remote_addr_unset(): void
    {
        $event = [
            'rawPath' => '/',
            'requestContext' => ['http' => ['method' => 'GET']],
            'body' => '',
            'isBase64Encoded' => false,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertArrayNotHasKey('REMOTE_ADDR', $request->getServerParams());
    }

    // --- Strict base64 decoding: invalid input is reported, and a
    // genuinely decoded "" or "0" is never confused with either. ---

    public function test_a_malformed_base64_body_throws_instead_of_becoming_an_empty_body(): void
    {
        $event = [
            'rawPath' => '/users',
            'requestContext' => ['http' => ['method' => 'POST']],
            'body' => 'not valid base64 !!! ***',
            'isBase64Encoded' => true,
        ];

        $this->expectException(MalformedRequestBodyException::class);
        BrefLambdaAdapter::requestFromEvent($event);
    }

    public function test_a_base64_body_that_decodes_to_the_literal_zero_is_not_treated_as_empty(): void
    {
        $event = [
            'rawPath' => '/users',
            'requestContext' => ['http' => ['method' => 'POST']],
            'body' => base64_encode('0'),
            'isBase64Encoded' => true,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertSame('0', (string) $request->getBody());
    }

    public function test_a_base64_body_that_decodes_to_an_empty_string_round_trips_correctly(): void
    {
        $event = [
            'rawPath' => '/users',
            'requestContext' => ['http' => ['method' => 'POST']],
            'body' => base64_encode(''),
            'isBase64Encoded' => true,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertSame('', (string) $request->getBody());
    }

    /**
     * The payload-level detail the conformance suite can't see from the
     * wire: a body that is already valid UTF-8 is sent as-is, with the
     * flag off, rather than needlessly base64-encoded.
     */
    public function test_a_valid_utf8_response_body_is_not_base64_encoded(): void
    {
        $response = new Response(200, [], 'café ☕');

        $payload = BrefLambdaAdapter::responseToPayload($response);

        self::assertFalse($payload['isBase64Encoded']);
        self::assertSame('café ☕', $payload['body']);
    }
}
