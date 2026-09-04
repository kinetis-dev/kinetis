<?php

declare(strict_types=1);

namespace Kinetis\Tools\Tests;

/**
 * A minimal manifest that passes the schema, for the tests that commit
 * one into a scratch repository. Historical manifests are held to the
 * same rules as the one on disk, so a fixture has to be a real manifest
 * rather than the two fields a comparison happens to read.
 */
final class ManifestFixture
{
    /** @param array<string, string> $versions package key => version */
    public static function json(array $versions = ['demo' => '1.0.0']): string
    {
        $packages = [];

        foreach ($versions as $key => $version) {
            $packages[$key] = [
                'name' => "kinetis/{$key}",
                'description' => "the {$key} package",
                'namespace' => 'Kinetis\\Demo\\',
                'version' => $version,
            ];
        }

        return json_encode(
            ['defaults' => self::defaults(), 'packages' => $packages],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'type' => 'library',
            'license' => 'MIT',
            'authors' => [['name' => 'Test']],
            'minimumStability' => 'stable',
            'preferStable' => true,
            'phpVersion' => '^8.4',
            'requireDev' => ['phpunit/phpunit' => '^12.5'],
        ];
    }
}
