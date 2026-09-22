<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Linting;

use Kinetis\Orm\Linting\EntityPropertyExtension;
use PHPStan\DependencyInjection\DirectExtensionsCollection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\TooWideTypehints\TooWidePropertyTypeRule;
use PHPStan\Rules\TooWideTypehints\TooWideTypeCheck;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<TooWidePropertyTypeRule>
 */
final class EntityPropertyTypeTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new TooWidePropertyTypeRule(
            new DirectExtensionsCollection([new EntityPropertyExtension()]),
            self::getContainer()->getByType(TooWideTypeCheck::class),
        );
    }

    public function test_a_mapped_property_keeps_the_type_the_mapping_requires(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/MappedEntity.php'], []);
    }

    public function test_a_static_property_on_an_entity_keeps_its_report(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/StaticGuardEntity.php'], [
            [
                'Static property Kinetis\Orm\Tests\Linting\Fixtures\StaticGuardEntity::$cache (int|null) is never '
                . 'assigned int so it can be removed from the property type.',
                17,
            ],
        ]);
    }

    public function test_an_ordinary_class_keeps_its_reports(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/UnmappedMirror.php'], [
            [
                'Property Kinetis\Orm\Tests\Linting\Fixtures\UnmappedMirror::$id (int|null) is never assigned int '
                . 'so it can be removed from the property type.',
                14,
            ],
        ]);
    }
}
