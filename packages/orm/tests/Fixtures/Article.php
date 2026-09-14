<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Column;
use Kinetis\Orm\Attributes\Entity;
use LogicException;

/**
 * Every mapped type and visibility, a trait property, a column override,
 * and a constructor that must never run.
 */
#[Entity(table: 'articles')]
final class Article
{
    use HasSlug;

    private int $id;

    protected string $title;

    public ?string $summary;

    public ArticleStatus $status;

    public ?Priority $priority;

    public bool $featured;

    public float $rating;

    #[Column(name: 'author')]
    public int $authorId;

    public function __construct()
    {
        throw new LogicException('Hydration invoked an entity constructor.');
    }

    public function id(): int
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    /**
     * A complete row for this entity, with $overrides replacing columns.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function row(array $overrides = []): array
    {
        return [
            'id' => 1,
            'title' => 'First',
            'summary' => null,
            'status' => 'published',
            'priority' => null,
            'featured' => 0,
            'rating' => '4.5',
            'author' => 7,
            'slug' => 'first',
            ...$overrides,
        ];
    }
}
