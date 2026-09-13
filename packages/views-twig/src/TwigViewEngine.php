<?php

declare(strict_types=1);

namespace Kinetis\ViewsTwig;

use Kinetis\Views\AssetUrl;
use Kinetis\Views\Exception\ViewRenderException;
use Kinetis\Views\ViewDirectory;
use Kinetis\Views\ViewEngineInterface;
use Kinetis\Views\ViewName;
use Throwable;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final readonly class TwigViewEngine implements ViewEngineInterface
{
    private ViewDirectory $directory;
    private Environment $twig;

    /** @param array<string, mixed> $options */
    public function __construct(string $root, AssetUrl $asset = new AssetUrl(), array $options = [])
    {
        $this->directory = new ViewDirectory($root);
        $this->twig = new Environment(new FilesystemLoader($this->directory->root()), $options);
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
}
