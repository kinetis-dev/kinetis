<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
