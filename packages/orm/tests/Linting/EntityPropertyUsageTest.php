<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Linting;

use Kinetis\Orm\Linting\EntityPropertyExtension;
use PHPStan\DependencyInjection\DirectExtensionsCollection;
use PHPStan\Rules\DeadCode\UnusedPrivatePropertyRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<UnusedPrivatePropertyRule>
 */
final class EntityPropertyUsageTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        // The tags and the uninitialized flag carry PHPStan's own defaults,
        // so only the extension decides the difference between the two
        // fixtures.
        return new UnusedPrivatePropertyRule(
            new DirectExtensionsCollection([new EntityPropertyExtension()]),
            [],
            [],
            false,
        );
    }

    public function test_the_orm_reads_every_mapped_property(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/MappedEntity.php'], []);
    }

    public function test_a_static_property_on_an_entity_keeps_its_report(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/StaticGuardEntity.php'], [
            [
                'Static property Kinetis\Orm\Tests\Linting\Fixtures\StaticGuardEntity::$cache is never read, only '
                . 'written.',
                17,
                'See: https://phpstan.org/developing-extensions/always-read-written-properties',
            ],
        ]);
    }

    public function test_an_ordinary_class_keeps_its_reports(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/UnmappedMirror.php'], [
            [
                'Property Kinetis\Orm\Tests\Linting\Fixtures\UnmappedMirror::$id is never read, only written.',
                14,
                'See: https://phpstan.org/developing-extensions/always-read-written-properties',
            ],
            [
                'Property Kinetis\Orm\Tests\Linting\Fixtures\UnmappedMirror::$version is never read, only written.',
                16,
                'See: https://phpstan.org/developing-extensions/always-read-written-properties',
            ],
            [
                'Property Kinetis\Orm\Tests\Linting\Fixtures\UnmappedMirror::$body is never read, only written.',
                18,
                'See: https://phpstan.org/developing-extensions/always-read-written-properties',
            ],
            [
                'Property Kinetis\Orm\Tests\Linting\Fixtures\UnmappedMirror::$ticket is never read, only written.',
                20,
                'See: https://phpstan.org/developing-extensions/always-read-written-properties',
            ],
            [
                'Property Kinetis\Orm\Tests\Linting\Fixtures\UnmappedMirror::$author is never read, only written.',
                22,
                'See: https://phpstan.org/developing-extensions/always-read-written-properties',
            ],
        ]);
    }
}
