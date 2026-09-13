<?php

declare(strict_types=1);

namespace Kinetis\Views;

interface ViewEngineInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function render(ViewName $view, array $data): string;
}
