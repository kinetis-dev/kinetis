<?php

declare(strict_types=1);

namespace Kinetis\ViewsLatte;

use Kinetis\Views\AssetUrl;
use Kinetis\Views\Exception\ViewRenderException;
use Kinetis\Views\ViewDirectory;
use Kinetis\Views\ViewEngineInterface;
use Kinetis\Views\ViewName;
use Kinetis\Views\ViewCacheDirectory;
use Kinetis\Views\ViewRuntime;
use Latte\Engine;
use Throwable;

final readonly class LatteViewEngine implements ViewEngineInterface
{
    private ViewDirectory $directory;
    private Engine $latte;
    private ViewCacheDirectory $cacheDirectory;
    private bool $cacheEnabled;

    public function __construct(
        string $root,
        ViewRuntime $runtime,
        AssetUrl $asset = new AssetUrl(),
    ) {
        $this->directory = new ViewDirectory($root);
        $this->cacheDirectory = $runtime->cacheDirectory('latte');
        $this->cacheEnabled = $runtime->cachesTemplates();
        $this->latte = new Engine();
        $this->latte->setAutoRefresh(!$this->cacheEnabled);

        if ($this->cacheEnabled) {
            $this->cacheDirectory->ensureExists();
            $this->latte->setCacheDirectory($this->cacheDirectory->path());
        }

        $this->latte->addFunction('asset', $asset);
    }

    public function engine(): Engine
    {
        return $this->latte;
    }

    #[\Override]
    public function render(ViewName $view, array $data): string
    {
        $path = $this->directory->resolve($view, 'latte');

        try {
            return $this->latte->renderToString($path, $data);
        } catch (Throwable $exception) {
            throw ViewRenderException::from($view->value, $exception);
        }
    }

    #[\Override]
    public function warmCache(): int
    {
        if (!$this->cacheEnabled) {
            return 0;
        }

        $this->cacheDirectory->clear();
        $this->cacheDirectory->ensureExists();
        $templates = $this->directory->templates('latte');

        foreach ($templates as $path) {
            $this->latte->warmupCache($path);
        }

        return count($templates);
    }

    #[\Override]
    public function clearCache(): int
    {
        return $this->cacheDirectory->clear();
    }
}
