<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Form;

use Kinetis\Http\Form\Exception\UnparseableFormBodyException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

/**
 * The boundary this exception exists to hold: nothing a client sent may
 * travel inside it.
 *
 * A parser's own message is assembled from the input that failed — it
 * quotes header names, part names, charset labels and body fragments —
 * and every adapter logs what this carries. So the check is not "does
 * the current code pass a message through" but "can any value reach a
 * message, a chain, a stringification or a serialization at all", which
 * is what these assert, by inspecting the object rather than the call
 * sites.
 */
final class UnparseableFormBodyExceptionTest extends TestCase
{
    /**
     * @return iterable<string, array{0: UnparseableFormBodyException}>
     */
    public static function everyFailure(): iterable
    {
        yield 'no boundary' => [UnparseableFormBodyException::noMultipartBoundary()];
        yield 'ambiguous boundary' => [UnparseableFormBodyException::ambiguousMultipartBoundary()];
        yield 'no parts' => [UnparseableFormBodyException::noParts()];
        yield 'unreadable multipart' => [UnparseableFormBodyException::unreadableMultipart()];
        yield 'undecodable part' => [UnparseableFormBodyException::undecodablePart()];
        yield 'nested multipart' => [UnparseableFormBodyException::nestedMultipart()];
        yield 'ambiguous delimiter' => [UnparseableFormBodyException::ambiguousDelimiter()];
    }

    /**
     * Every constructor this class has, so a new one added without a
     * fixed message is caught here rather than in a log.
     */
    public function test_every_public_constructor_is_covered_by_this_suite(): void
    {
        $declared = [];

        foreach ((new ReflectionClass(UnparseableFormBodyException::class))->getMethods() as $method) {
            if ($method->isStatic() && $method->isPublic()) {
                $declared[] = $method->getName();
            }
        }

        sort($declared);

        self::assertSame(
            [
                'ambiguousDelimiter',
                'ambiguousMultipartBoundary',
                'nestedMultipart',
                'noMultipartBoundary',
                'noParts',
                'undecodablePart',
                'unreadableMultipart',
            ],
            $declared,
            'a new failure needs a fixed message and a case in everyFailure()',
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everyFailure')]
    public function test_a_failure_carries_a_fixed_message_a_category_and_no_cause(UnparseableFormBodyException $failure): void
    {
        self::assertNull($failure->getPrevious(), 'a cause carries the parser text one getPrevious() away');
        self::assertNotSame('', $failure->category);
        self::assertMatchesRegularExpression('/^[a-z-]+$/', $failure->category, 'a category is a fixed slug, never input');
        self::assertMatchesRegularExpression(
            '/^[A-Za-z0-9 ,.\/-]+$/',
            $failure->getMessage(),
            'a message is a fixed sentence: no quotes, no braces, nothing a value could have been interpolated into',
        );
    }

    /**
     * The sentinels a real parser message would contain. Checked against
     * everything an exception can be read through — the message, the
     * whole chain, `__toString()`, and a full property dump — because a
     * value that reached any of them would reach a log through some
     * caller.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('everyFailure')]
    public function test_no_reachable_representation_can_carry_client_input(UnparseableFormBodyException $failure): void
    {
        $sentinels = ['definitely-not-a-charset', 'X-Attacker', 'secret-body-fragment', 'boundary='];

        $surfaces = [
            'message' => self::wholeChain($failure),
            'string' => (string) $failure,
            'properties' => print_r($failure, true),
        ];

        foreach ($surfaces as $name => $surface) {
            foreach ($sentinels as $sentinel) {
                self::assertStringNotContainsString($sentinel, $surface, "a parser fragment reached the {$name}");
            }
        }
    }

    private static function wholeChain(Throwable $failure): string
    {
        $text = '';

        for ($current = $failure; $current !== null; $current = $current->getPrevious()) {
            $text .= $current->getMessage() . "\n";
        }

        return $text;
    }
}
