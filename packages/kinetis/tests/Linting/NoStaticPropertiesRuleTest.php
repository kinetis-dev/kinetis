<?php

declare(strict_types=1);

namespace Kinetis\Tests\Linting;

use Kinetis\Linting\NoStaticPropertiesRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<NoStaticPropertiesRule>
 */
final class NoStaticPropertiesRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoStaticPropertiesRule();
    }

    public function test_flags_a_static_property(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/HasStaticProperty.php'], [
            [
                "Static properties hold state across every request a persistent worker handles until it "
                . "restarts — exactly the cross-request state bleed a fresh RequestScope per request exists "
                . "to prevent. Use AppScope for state that should genuinely persist for the worker's lifetime, "
                . "or RequestScope for state scoped to one request.",
                9,
            ],
        ]);
    }

    public function test_does_not_flag_a_static_method_or_instance_property(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/NoStaticProperty.php'], []);
    }
}
