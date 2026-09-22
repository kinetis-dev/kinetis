<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Linting;

use Kinetis\Orm\Linting\GeneratedIdentifierPropertyExtension;
use PHPStan\DependencyInjection\DirectExtensionsCollection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\TooWideTypehints\TooWidePropertyTypeRule;
use PHPStan\Rules\TooWideTypehints\TooWideTypeCheck;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<TooWidePropertyTypeRule>
 */
final class GeneratedIdentifierPropertyExtensionTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new TooWidePropertyTypeRule(
            new DirectExtensionsCollection([new GeneratedIdentifierPropertyExtension()]),
            self::getContainer()->getByType(TooWideTypeCheck::class),
        );
    }

    public function test_a_generated_identifier_keeps_its_required_nullable_int(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/GeneratedIdentifierEntity.php'], []);
    }

    public function test_an_assigned_identifier_is_still_reported(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/AssignedIdentifierEntity.php'], [
            [
                'Property Kinetis\Orm\Tests\Linting\Fixtures\AssignedIdentifierEntity::$id (int|null) is never '
                . 'assigned int so it can be removed from the property type.',
                14,
            ],
        ]);
    }
}
