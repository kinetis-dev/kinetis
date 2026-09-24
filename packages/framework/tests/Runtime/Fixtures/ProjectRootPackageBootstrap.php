<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime\Fixtures;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\Runtime\ProjectRoot;

/**
 * Writes the path of the ProjectRoot it resolves to MARKER inside that
 * root, so a test can read what a package bootstrap saw even when the
 * AppScope it ran on is internal to a command.
 */
final class ProjectRootPackageBootstrap implements PackageBootstrapInterface
{
    public const string MARKER = 'package-bootstrap-root.txt';

    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        $root = $app->get(ProjectRoot::class)->path;

        file_put_contents($root . '/' . self::MARKER, $root);
    }
}
