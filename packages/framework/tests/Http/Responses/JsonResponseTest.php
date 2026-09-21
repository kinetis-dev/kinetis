<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Responses;

use JsonException;
use JsonSerializable;
use Kinetis\Http\Responses\JsonResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JsonResponseTest extends TestCase
{
    public function test_encodes_data_with_a_custom_status_and_headers(): void
    {
        $response = JsonResponse::create(
            ['article' => ['id' => 42, 'published' => true]],
            201,
            ['Location' => '/articles/42'],
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('/articles/42', $response->getHeaderLine('Location'));
        self::assertSame(
            '{"article":{"id":42,"published":true}}',
            (string) $response->getBody(),
        );
    }

    public function test_the_fixed_content_type_replaces_any_caller_supplied_casing(): void
    {
        $response = JsonResponse::create([], headers: ['content-type' => 'text/plain']);

        self::assertSame(['application/json'], $response->getHeader('Content-Type'));
    }

    public function test_invalid_utf8_raises_json_exception(): void
    {
        $this->expectException(JsonException::class);

        JsonResponse::create(["bad" => "\xC3\x28"]);
    }

    public function test_an_exception_from_json_serializable_propagates_unchanged(): void
    {
        $failure = new RuntimeException('Serialization failed.');
        $value = new class ($failure) implements JsonSerializable {
            public function __construct(private RuntimeException $failure) {}

            public function jsonSerialize(): mixed
            {
                throw $this->failure;
            }
        };

        try {
            JsonResponse::create($value);
            self::fail('The JsonSerializable exception should propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }
}
