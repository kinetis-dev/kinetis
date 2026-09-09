<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http;

use Kinetis\Http\ProblemDetailsValidationExceptionRenderer;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class ProblemDetailsValidationExceptionRendererTest extends TestCase
{
    private function render(ValidationException $exception): string
    {
        return (string) new ProblemDetailsValidationExceptionRenderer()
            ->render($exception, new ServerRequest('POST', '/orders'))
            ->getBody();
    }

    public function test_it_answers_with_the_registered_422_problem_type(): void
    {
        $exception = ValidationException::fromViolations([new Violation(['name'], 'required', 'is required.')]);

        $response = new ProblemDetailsValidationExceptionRenderer()
            ->render($exception, new ServerRequest('POST', '/orders'));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        /** @var array<string, mixed> $document */
        $document = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('about:blank', $document['type']);
        self::assertSame('Unprocessable Content', $document['title']);
        self::assertSame(422, $document['status']);
        self::assertSame('The request data failed validation.', $document['detail']);
    }

    /**
     * The `errors` extension is ordered and segmented: an element's own
     * index is an int, a member name a string, and both stay
     * distinguishable — which a dotted key or a JSON Pointer built from
     * one could not promise.
     */
    public function test_the_errors_extension_carries_every_violation_in_order(): void
    {
        $exception = ValidationException::fromViolations([
            new Violation(['items', 0, 'quantity'], 'constraint', 'must be greater than 0.', ['constraint' => 'GreaterThan']),
            new Violation(['customerName'], 'required', 'is required.'),
        ]);

        self::assertSame(
            '{"type":"about:blank","title":"Unprocessable Content","status":422,'
            . '"detail":"The request data failed validation.","errors":['
            . '{"path":["items",0,"quantity"],"code":"constraint","message":"must be greater than 0.",'
            . '"parameters":{"constraint":"GreaterThan"}},'
            . '{"path":["customerName"],"code":"required","message":"is required.","parameters":{}}'
            . ']}',
            $this->render($exception),
        );
    }

    /**
     * A constraint quoting raw client bytes back can produce a message
     * that is not valid UTF-8. Substituting keeps the response this
     * class exists to produce; throwing would hand the request to the
     * terminal generic 500 instead, losing every field message with it.
     */
    public function test_an_invalid_utf8_message_is_substituted_rather_than_defeating_the_response(): void
    {
        $exception = ValidationException::fromViolations([
            new Violation(['name'], 'constraint', "must not contain \xB1\x31", ['given' => "\xB1\x31"]),
        ]);

        $body = $this->render($exception);

        /** @var array{errors: list<array{message: string, parameters: array<string, string>}>} $document */
        $document = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        self::assertStringContainsString('must not contain', $document['errors'][0]['message']);
        self::assertStringNotContainsString("\xB1", $body);
        self::assertArrayHasKey('given', $document['errors'][0]['parameters']);
    }

    /**
     * The renderer is app-scoped and shared by every request, so it
     * must hold nothing between calls.
     */
    public function test_it_retains_nothing_between_calls(): void
    {
        $renderer = new ProblemDetailsValidationExceptionRenderer();

        $first = $renderer->render(
            ValidationException::fromViolations([new Violation(['name'], 'required', 'is required.')]),
            new ServerRequest('POST', '/orders'),
        );
        $second = $renderer->render(
            ValidationException::fromViolations([new Violation(['email'], 'required', 'is required.')]),
            new ServerRequest('POST', '/users'),
        );

        /** @var array{errors: list<array{path: list<string>}>} $secondDocument */
        $secondDocument = json_decode((string) $second->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"path":["name"]', (string) $first->getBody());
        self::assertSame([['email']], array_column($secondDocument['errors'], 'path'));
    }
}
