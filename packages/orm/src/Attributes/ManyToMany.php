<?php

declare(strict_types=1);

namespace Kinetis\Orm\Attributes;

use Attribute;

/**
 * Marks an array property holding the $target entities a join table links
 * this entity to. The owning side names the $table and both of its
 * foreign-key columns and is the only side that writes join rows; the
 * inverse side names the owning property with $mappedBy and reads the same
 * table backwards.
 *
 * The join table needs a foreign key to each entity table and a unique
 * constraint over the column pair, and nothing inspects the schema. A
 * collection holding one target twice is refused before SQL, and two objects
 * for one row never reach one together, since an EntityManager holds one
 * object per identity and refuses a second. The unique constraint is what
 * refuses the pair a flush cannot see: one a concurrent writer added, one
 * the table already held, or one a separate unit of work created. An owner
 * carrying #[Version] locks its whole membership through that version, so
 * two writers replacing it conflict rather than merge. A link carrying
 * payload, ordering or a lifecycle of its own is an ordinary entity with two
 * #[BelongsTo] properties instead.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class ManyToMany
{
    /**
     * @param class-string $target the element class, which the array type cannot name
     * @param string|null $table the join table, on the owning side only
     * @param string|null $joinColumn the join-table column holding this entity's identifier, on the owning side only
     * @param string|null $inverseJoinColumn the join-table column holding a target's identifier, on the owning side only
     * @param string|null $mappedBy the owning #[ManyToMany] property of $target, on the inverse side only
     */
    public function __construct(
        public string $target,
        public ?string $table = null,
        public ?string $joinColumn = null,
        public ?string $inverseJoinColumn = null,
        public ?string $mappedBy = null,
    ) {}
}
