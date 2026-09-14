<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

trait HasSlug
{
    private string $slug;

    public function slug(): string
    {
        return $this->slug;
    }
}
