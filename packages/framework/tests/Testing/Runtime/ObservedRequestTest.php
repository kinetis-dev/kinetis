<?php

declare(strict_types=1);

namespace Kinetis\Tests\Testing\Runtime;

use Kinetis\Testing\Runtime\MalformedObservationException;
use Kinetis\Testing\Runtime\ObservedRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An observation is written by a fixture in another process and read
 * back here, and every request-side conformance assertion is made
 * against what comes out of that read. A coerced field or a leniently
 * decoded body would report a broken fixture as a passing conformance
 * run, so the read is exact.
 */
final class ObservedRequestTest extends TestCase
{
    private static function observation(): ObservedRequest
    {
        return new ObservedRequest(
            'POST',
            '/users/42',
            'tag=a',
            ['tag' => 'a'],
            ['Host' => ['kinetis.test'], 123 => ['ok']],
            ['session' => 'abc'],
            '203.0.113.7',
            ['name' => 'Alon'],
            [['field' => 'docs.0', 'filename' => 'a.txt', 'mediaType' => 'text/plain', 'error' => UPLOAD_ERR_OK, 'contents' => "\xFF\x00binary"]],
            "\xFF\x00body",
            'https',
            'kinetis.test',
            8443,
            '1.1',
            '/users/42?tag=a',
        );
    }

    public function test_an_observation_survives_the_round_trip_unchanged(): void
    {
        self::assertEquals(self::observation(), ObservedRequest::fromArray(self::observation()->toArray()));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function requiredFields(): iterable
    {
        foreach ([
            'method', 'path', 'query', 'queryParams', 'headers', 'cookieParams',
            'remoteAddr', 'parsedBody', 'uploadedFiles', 'body', 'scheme', 'host',
            'port', 'protocolVersion', 'requestTarget',
        ] as $field) {
            yield $field => [$field];
        }
    }

    #[DataProvider('requiredFields')]
    public function test_every_field_is_required(string $field): void
    {
        $data = self::observation()->toArray();
        unset($data[$field]);

        $this->expectException(MalformedObservationException::class);
        $this->expectExceptionMessage("missing its \"{$field}\" field");

        ObservedRequest::fromArray($data);
    }

    /**
     * @return iterable<string, array{0: string, 1: mixed}>
     */
    public static function wrongTypes(): iterable
    {
        yield 'method as a number' => ['method', 42];
        yield 'port as a string' => ['port', '8443'];
        yield 'headers as a list of strings' => ['headers', ['Host' => 'kinetis.test']];
        yield 'cookie params as a list' => ['cookieParams', ['abc']];
        yield 'query params as a string' => ['queryParams', 'tag=a'];
        yield 'parsed body as a string' => ['parsedBody', 'name=Alon'];
        yield 'uploaded files as a map' => ['uploadedFiles', ['docs' => []]];
        yield 'remote address as an int' => ['remoteAddr', 7];
        yield 'body as a number' => ['body', 200];
    }

    #[DataProvider('wrongTypes')]
    public function test_a_field_of_the_wrong_type_is_refused_rather_than_cast(string $field, mixed $value): void
    {
        $data = self::observation()->toArray();
        $data[$field] = $value;

        $this->expectException(MalformedObservationException::class);

        ObservedRequest::fromArray($data);
    }

    /**
     * Decoded as empty bytes, a body that did not survive the boundary
     * is indistinguishable from a request that had none at all — and
     * the suite would then assert, confidently, against the wrong thing.
     */
    public function test_a_body_that_is_not_valid_base64_is_refused_rather_than_decoded_as_empty(): void
    {
        $data = self::observation()->toArray();
        $data['body'] = 'not base64 !!!';

        $this->expectException(MalformedObservationException::class);
        $this->expectExceptionMessage('not valid base64');

        ObservedRequest::fromArray($data);
    }

    public function test_an_uploaded_file_missing_its_error_code_is_refused(): void
    {
        $data = self::observation()->toArray();
        unset($data['uploadedFiles'][0]['error']);

        $this->expectException(MalformedObservationException::class);

        ObservedRequest::fromArray($data);
    }

    public function test_uploaded_file_contents_that_are_not_valid_base64_are_refused(): void
    {
        $data = self::observation()->toArray();
        $data['uploadedFiles'][0]['contents'] = 'not base64 !!!';

        $this->expectException(MalformedObservationException::class);

        ObservedRequest::fromArray($data);
    }

    /**
     * A purely-numeric header name is an int key on every adapter, and
     * `header()` still finds it by its real name — the honest outcome
     * the suite pins rather than papering over.
     */
    public function test_a_numeric_header_name_survives_the_round_trip_and_stays_findable(): void
    {
        $decoded = ObservedRequest::fromArray(self::observation()->toArray());

        self::assertSame(['ok'], $decoded->header('123'));
        self::assertSame(['kinetis.test'], $decoded->header('host'));
        self::assertSame([], $decoded->header('X-Absent'));
    }
}
