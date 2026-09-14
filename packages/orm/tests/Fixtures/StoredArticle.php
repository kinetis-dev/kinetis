<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Column;
use Kinetis\Orm\Attributes\Entity;

/** Every mapped type, read back from a real table. */
#[Entity(table: 'kin_orm_articles')]
final class StoredArticle
{
    public int $id;

    public string $title;

    public ?string $summary;

    public ArticleStatus $status;

    public ?Priority $priority;

    public bool $featured;

    public float $rating;

    #[Column(name: 'author_id')]
    public int $authorId;
}
