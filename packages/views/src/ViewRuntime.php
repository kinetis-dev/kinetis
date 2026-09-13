<?php

declare(strict_types=1);

namespace Kinetis\Views;

use Kinetis\Config\Config;
use Kinetis\Runtime\AppEnvironment;

/**
 * Worker-lifetime view-cache policy derived once during application bootstrap.
 */
final readonly class ViewRuntime
{
    private string $projectRoot;

    public function __construct(string $projectRoot, private AppEnvironment $environment)
    {
        $resolved = realpath($projectRoot);

        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException("Project root '{$projectRoot}' is not an existing directory.");
        }

        $this->projectRoot = $resolved === DIRECTORY_SEPARATOR
            ? DIRECTORY_SEPARATOR
            : rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    public static function fromConfig(string $projectRoot, Config $config): self
    {
        return new self($projectRoot, AppEnvironment::detect($config->get('APP_ENV')));
    }

    public function cachesTemplates(): bool
    {
        return $this->environment->isProduction();
    }

    public function cacheDirectory(string $engine): ViewCacheDirectory
    {
        return new ViewCacheDirectory($this->projectRoot, $engine);
    }
}
