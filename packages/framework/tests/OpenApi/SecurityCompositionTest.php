<?php

declare(strict_types=1);

namespace Kinetis\Tests\OpenApi;

use Kinetis\OpenApi\Exception\OpenApiSecurityException;
use Kinetis\OpenApi\SecurityComposition;
use Kinetis\OpenApi\SecurityDescriberInterface;
use Kinetis\OpenApi\SecurityDescription;
use PHPUnit\Framework\TestCase;

/**
 * The rules a description is held to before a document carries it, and
 * the product two providers compose to. Each provider is an anonymous
 * class: a description is what is under test, not the middleware it
 * would belong to.
 */
final class SecurityCompositionTest extends TestCase
{
    public function test_providers_in_sequence_compose_to_the_product_of_their_alternatives(): void
    {
        $first = new class implements SecurityDescriberInterface {
            public static function openApiSecurity(): SecurityDescription
            {
                return new SecurityDescription(
                    ['a' => ['type' => 'http', 'scheme' => 'basic'], 'b' => ['type' => 'mutualTLS']],
                    [['a' => []], ['b' => []]],
                );
            }
        };

        $second = new class implements SecurityDescriberInterface {
            public static function openApiSecurity(): SecurityDescription
            {
                return SecurityDescription::scheme('c', ['type' => 'openIdConnect', 'openIdConnectUrl' => 'https://example.test']);
            }
        };

        self::assertSame(
            [['a' => [], 'c' => []], ['b' => [], 'c' => []]],
            new SecurityComposition()->compose([$first::class, $second::class]),
        );
    }

    public function test_a_numeric_string_scheme_name_serializes_as_an_object_key(): void
    {
        $provider = new class implements SecurityDescriberInterface {
            public static function openApiSecurity(): SecurityDescription
            {
                return SecurityDescription::scheme('0', ['type' => 'mutualTLS']);
            }
        };

        $requirements = new SecurityComposition()->compose([$provider::class]);

        self::assertSame('[{"0":[]}]', json_encode(SecurityComposition::publish($requirements), JSON_THROW_ON_ERROR));
    }

    public function test_alternatives_that_state_the_same_requirement_collapse_into_one(): void
    {
        $provider = new class implements SecurityDescriberInterface {
            public static function openApiSecurity(): SecurityDescription
            {
                return new SecurityDescription(
                    ['x' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X'], 'y' => ['type' => 'mutualTLS']],
                    [['x' => []], ['y' => []]],
                );
            }
        };

        // (x or y) and (x or y): four products, three distinct
        // requirements — "x and y" is produced twice, in either order.
        self::assertSame(
            [['x' => []], ['x' => [], 'y' => []], ['y' => []]],
            new SecurityComposition()->compose([$provider::class, $provider::class]),
        );
    }

    public function test_one_definition_written_in_a_different_member_order_is_the_same_scheme(): void
    {
        $first = new class implements SecurityDescriberInterface {
            public static function openApiSecurity(): SecurityDescription
            {
                return SecurityDescription::scheme('k', ['type' => 'apiKey', 'in' => 'header', 'name' => 'K']);
            }
        };

        $second = new class implements SecurityDescriberInterface {
            public static function openApiSecurity(): SecurityDescription
            {
                return SecurityDescription::scheme('k', ['name' => 'K', 'in' => 'header', 'type' => 'apiKey']);
            }
        };

        $composition = new SecurityComposition();
        $composition->compose([$first::class, $second::class]);

        // One scheme, published as the provider that declared it first
        // wrote it.
        self::assertSame(['k' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'K']], $composition->schemes());
    }

    public function test_a_scheme_without_a_name_is_refused(): void
    {
        $provider = new class implements SecurityDescriberInterface {
            public static function openApiSecurity(): SecurityDescription
            {
                return SecurityDescription::scheme('', ['type' => 'mutualTLS']);
            }
        };

        $this->expectException(OpenApiSecurityException::class);
        $this->expectExceptionMessage('empty name');

        new SecurityComposition()->compose([$provider::class]);
    }

    public function test_a_scheme_type_outside_openapi_31_is_refused(): void
    {
        $provider = new class implements SecurityDescriberInterface {
            public static function openApiSecurity(): SecurityDescription
            {
                return SecurityDescription::scheme('legacy', ['type' => 'basic']);
            }
        };

        $this->expectException(OpenApiSecurityException::class);
        $this->expectExceptionMessage('"basic"');

        new SecurityComposition()->compose([$provider::class]);
    }

    public function test_a_scheme_definition_that_is_not_an_array_is_refused(): void
    {
        $provider = new class implements SecurityDescriberInterface {
            public static function openApiSecurity(): SecurityDescription
            {
                // A provider can return anything; the declared shapes
                // are PHPDoc. Unchecked, this is a fatal rather than a
                // refusal naming the provider.
                return new SecurityDescription(['bearer' => 'http'], [['bearer' => []]]);
            }
        };

        $this->expectException(OpenApiSecurityException::class);
        $this->expectExceptionMessage('"bearer" as something other than an array');

        new SecurityComposition()->compose([$provider::class]);
    }

    public function test_requirements_that_are_not_a_list_are_refused(): void
    {
        $provider = new class implements SecurityDescriberInterface {
            public static function openApiSecurity(): SecurityDescription
            {
                return new SecurityDescription(
                    ['keyed' => ['type' => 'mutualTLS']],
                    ['first' => ['keyed' => []]],
                );
            }
        };

        $this->expectException(OpenApiSecurityException::class);
        $this->expectExceptionMessage('list of requirement objects');

        new SecurityComposition()->compose([$provider::class]);
    }

    public function test_a_requirement_that_is_not_an_array_is_refused(): void
    {
        $provider = new class implements SecurityDescriberInterface {
            public static function openApiSecurity(): SecurityDescription
            {
                return new SecurityDescription(
                    ['listed' => ['type' => 'mutualTLS']],
                    [['listed' => []], 'anonymous'],
                );
            }
        };

        $this->expectException(OpenApiSecurityException::class);
        $this->expectExceptionMessage('list of requirement objects');

        new SecurityComposition()->compose([$provider::class]);
    }

    public function test_a_requirement_naming_a_scheme_the_description_does_not_declare_is_refused(): void
    {
        $provider = new class implements SecurityDescriberInterface {
            public static function openApiSecurity(): SecurityDescription
            {
                return new SecurityDescription(
                    ['declared' => ['type' => 'mutualTLS']],
                    [['elsewhere' => []]],
                );
            }
        };

        $this->expectException(OpenApiSecurityException::class);
        $this->expectExceptionMessage('"elsewhere"');

        new SecurityComposition()->compose([$provider::class]);
    }

    public function test_a_requirement_holding_anything_but_scope_strings_is_refused(): void
    {
        $provider = new class implements SecurityDescriberInterface {
            public static function openApiSecurity(): SecurityDescription
            {
                return new SecurityDescription(
                    ['scoped' => ['type' => 'mutualTLS']],
                    [['scoped' => ['read', 7]]],
                );
            }
        };

        $this->expectException(OpenApiSecurityException::class);
        $this->expectExceptionMessage('list of scope strings');

        new SecurityComposition()->compose([$provider::class]);
    }

    public function test_a_description_with_no_requirement_at_all_is_refused(): void
    {
        $provider = new class implements SecurityDescriberInterface {
            public static function openApiSecurity(): SecurityDescription
            {
                return new SecurityDescription(['unused' => ['type' => 'mutualTLS']], []);
            }
        };

        $this->expectException(OpenApiSecurityException::class);
        $this->expectExceptionMessage('no security requirement');

        new SecurityComposition()->compose([$provider::class]);
    }

    public function test_a_class_that_is_not_a_describer_is_refused(): void
    {
        $this->expectException(OpenApiSecurityException::class);
        $this->expectExceptionMessage(self::class);

        new SecurityComposition()->compose([self::class]);
    }
}
