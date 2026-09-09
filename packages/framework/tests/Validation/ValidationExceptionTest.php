<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation;

use InvalidArgumentException;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use PHPUnit\Framework\TestCase;

final class ValidationExceptionTest extends TestCase
{
    public function test_it_carries_its_violations_in_the_order_they_were_raised(): void
    {
        $name = new Violation(['name'], 'required', 'is required.');
        $email = new Violation(['email'], 'email', 'must be a valid email address.');

        $exception = ValidationException::fromViolations([$name, $email]);

        self::assertSame([$name, $email], $exception->violations);
        self::assertSame('Validation failed.', $exception->getMessage());
    }

    public function test_an_empty_list_is_not_a_failure(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A validation failure needs a non-empty list of violations.');

        ValidationException::fromViolations([]);
    }

    public function test_a_keyed_map_of_violations_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A validation failure needs a non-empty list of violations.');

        ValidationException::fromViolations(['name' => new Violation(['name'], 'required', 'is required.')]);
    }

    public function test_something_that_is_not_a_violation_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A validation failure holds Violation instances, string given.');

        ValidationException::fromViolations(['name is required']);
    }

    public function test_grouped_projects_segmented_paths_to_dotted_keys(): void
    {
        $exception = ValidationException::fromViolations([
            new Violation(['shippingAddress', 'street'], 'required', 'is required.'),
            new Violation(['items', 1, 'quantity'], 'greater_than', 'must be greater than 0.'),
        ]);

        self::assertSame([
            'shippingAddress.street' => ['is required.'],
            'items.1.quantity' => ['must be greater than 0.'],
        ], $exception->grouped());
    }

    public function test_grouped_collects_every_message_raised_against_one_path(): void
    {
        $exception = ValidationException::fromViolations([
            new Violation(['name'], 'min_length', 'must be at least 3 characters.'),
            new Violation(['email'], 'email', 'must be a valid email address.'),
            new Violation(['name'], 'regex', 'must match the expected format.'),
        ]);

        self::assertSame([
            'name' => ['must be at least 3 characters.', 'must match the expected format.'],
            'email' => ['must be a valid email address.'],
        ], $exception->grouped());
    }

    /**
     * Joining no segments produces no text at all, and an empty map key
     * is not a field name a form consumer can act on, so the root gets
     * JSONPath's own spelling instead.
     */
    public function test_grouped_spells_the_root_path_as_a_dollar_sign(): void
    {
        $exception = ValidationException::fromViolations([
            new Violation([], 'incomplete_update', 'must supply at least one field.'),
            new Violation(['name'], 'required', 'is required.'),
        ]);

        self::assertSame([
            '$' => ['must supply at least one field.'],
            'name' => ['is required.'],
        ], $exception->grouped());
    }

    /**
     * grouped() is a convenience, not the wire shape: what a renderer
     * that needs codes, parameters, or an unambiguous path reads is the
     * violations themselves — which is what a dotted key cannot give
     * back, since a member name may contain a dot of its own.
     */
    public function test_grouped_is_lossy_where_a_member_name_contains_a_dot(): void
    {
        $exception = ValidationException::fromViolations([
            new Violation(['meta', 'user.name'], 'required', 'is required.'),
        ]);

        self::assertSame(['meta.user.name' => ['is required.']], $exception->grouped());
        self::assertSame(['meta', 'user.name'], $exception->violations[0]->path);
    }
}
