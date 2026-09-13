<?php

declare(strict_types=1);

namespace Kinetis\ViewsLatte;

use Kinetis\Views\AssetUrl;
use Kinetis\Views\Exception\ViewRenderException;
use Kinetis\Views\ViewDirectory;
use Kinetis\Views\ViewEngineInterface;
use Kinetis\Views\ViewName;
use Latte\Engine;
use Throwable;

final readonly class LatteViewEngine implements ViewEngineInterface
{
    private ViewDirectory $directory;
    private Engine $latte;

    public function __construct(
        string $root,
        AssetUrl $asset = new AssetUrl(),
        ?string $cacheDirectory = null,
        bool $autoRefresh = true,
    ) {
        $this->directory = new ViewDirectory($root);
        $this->latte = new Engine();
        $this->latte->setAutoRefresh($autoRefresh);

        if ($cacheDirectory !== null) {
            $this->latte->setCacheDirectory($cacheDirectory);
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
}
