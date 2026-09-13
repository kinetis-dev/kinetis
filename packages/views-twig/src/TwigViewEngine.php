<?php

declare(strict_types=1);

namespace Kinetis\ViewsTwig;

use Kinetis\Views\AssetUrl;
use Kinetis\Views\Exception\ViewRenderException;
use Kinetis\Views\ViewDirectory;
use Kinetis\Views\ViewEngineInterface;
use Kinetis\Views\ViewName;
use Kinetis\Views\ViewCacheDirectory;
use Kinetis\Views\ViewRuntime;
use Throwable;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final readonly class TwigViewEngine implements ViewEngineInterface
{
    private ViewDirectory $directory;
    private Environment $twig;
    private ViewCacheDirectory $cacheDirectory;
    private bool $cacheEnabled;

    /** @param array<string, mixed> $options Engine options other than cache and auto_reload. */
    public function __construct(
        string $root,
        ViewRuntime $runtime,
        AssetUrl $asset = new AssetUrl(),
        array $options = [],
    ) {
        if (array_key_exists('cache', $options) || array_key_exists('auto_reload', $options)) {
            throw new \InvalidArgumentException('Twig cache and auto_reload are controlled by ViewRuntime.');
        }

        $this->directory = new ViewDirectory($root);
        $this->cacheDirectory = $runtime->cacheDirectory('twig');
        $this->cacheEnabled = $runtime->cachesTemplates();

        if ($this->cacheEnabled) {
            $this->cacheDirectory->ensureExists();
        }

        $options['cache'] = $this->cacheEnabled ? $this->cacheDirectory->path() : false;
        $options['auto_reload'] = !$this->cacheEnabled;
        $rootPath = $this->directory->root();
        $this->twig = new Environment(new FilesystemLoader($rootPath, $rootPath), $options);
        $this->twig->addFunction(new TwigFunction('asset', $asset));
    }

    public function engine(): Environment
    {
        return $this->twig;
    }

    #[\Override]
    public function render(ViewName $view, array $data): string
    {
        $this->directory->resolve($view, 'twig');

        try {
            return $this->twig->render($view->file('twig'), $data);
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
        $templates = $this->directory->templates('twig');

        foreach (array_keys($templates) as $view) {
            $this->twig->load((new ViewName($view))->file('twig'));
        }

        return count($templates);
    }

    #[\Override]
    public function clearCache(): int
    {
        return $this->cacheDirectory->clear();
    }
}
