<?php

declare(strict_types=1);

namespace Kinetis\ViewsPhp;

use Kinetis\Views\AssetUrl;
use Kinetis\Views\Exception\ViewRenderException;
use Kinetis\Views\ViewDirectory;
use Kinetis\Views\ViewEngineInterface;
use Kinetis\Views\ViewName;
use Throwable;

final readonly class PhpViewEngine implements ViewEngineInterface
{
    private ViewDirectory $directory;

    public function __construct(string $root, private AssetUrl $asset = new AssetUrl())
    {
        $this->directory = new ViewDirectory($root);
    }

    #[\Override]
    public function render(ViewName $view, array $data): string
    {
        $path = $this->directory->resolve($view, 'php');
        $level = ob_get_level();
        ob_start();

        try {
            self::includeTemplate($path, $data, $this->asset);

            if (ob_get_level() !== $level + 1) {
                throw new \RuntimeException('The template changed the rendering output-buffer depth.');
            }

            $rendered = ob_get_clean();

            if ($rendered === false) {
                throw new \RuntimeException('The template closed its rendering output buffer.');
            }

            return $rendered;
        } catch (Throwable $exception) {
            while (ob_get_level() > $level) {
                if (!@ob_end_clean()) {
                    break;
                }
            }

            throw ViewRenderException::from($view->value, $exception);
        }
    }

    #[\Override]
    public function warmCache(): int
    {
        return 0;
    }

    #[\Override]
    public function clearCache(): int
    {
        return 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function includeTemplate(string $path, array $data, AssetUrl $asset): void
    {
        (static function (): void { // @phpstan-ignore arguments.count (unnamed arguments keep every non-reserved data key available to the template)
            extract((array) func_get_arg(1), EXTR_OVERWRITE);
            /** @var AssetUrl $asset */
            $asset = func_get_arg(2);
            // require, not require_once: persistent workers must execute the
            // template for every render instead of only the first request.
            require (string) func_get_arg(0); // NOSONAR
        })($path, $data, $asset);
    }
}
