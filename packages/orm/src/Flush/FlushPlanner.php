<?php

declare(strict_types=1);

namespace Kinetis\Orm\Flush;

use Kinetis\Orm\EntityPlan;
use Kinetis\Orm\Exception\InvalidEntityStateException;
use SplMinHeap;

/**
 * @internal Turns one flush's inserts, updates and deletes into the ordered,
 *           immutable list of statements that writes them.
 *
 * A row is written after every row its foreign keys name and before every
 * row that names it:
 *
 * - the insert of a row awaiting insert precedes the insert or update that
 *   will point to it, and that pointer travels as a Reference until the
 *   insert reports its generated key;
 * - an update that moves or nulls a foreign key away from a row being
 *   deleted precedes that delete;
 * - the delete of a row precedes the delete of a row its foreign key names;
 * - an insert or update whose final foreign key names a row being deleted is
 *   refused here, before a transaction begins.
 *
 * A #[ManyToMany] link statement writes a join table rather than an entity
 * table: the DELETE of a link runs before the delete of its owning entity,
 * both endpoint inserts run before the INSERT of a link, and a link naming a
 * row this flush removes is refused here too.
 *
 * Where no dependency decides, the order is link deletes, entity deletes,
 * entity inserts, link inserts, then updates; entity statements by class and
 * identifier where it is known, link statements by join table and the
 * identifiers of their pair, and both by scheduling ordinal. Deleting first
 * frees the unique foreign-key slot an owned #[HasOne] replacement needs,
 * and one order per join table keeps concurrent flushes from taking its row
 * locks in opposite orders.
 *
 * Rows that reference each other in a loop have no such order. The planner
 * breaks one loop at a time by deferring a nullable foreign key: the insert
 * sends NULL and a fix-up writes the key once both rows exist, and a loop
 * among rows all being deleted is nullified before the deletes. A fix-up
 * matches no version and advances none — it completes an insert before the
 * row is externally visible, or precedes a delete. A loop no nullable
 * foreign key breaks is refused before SQL rather than through a
 * backend-specific deferred constraint.
 *
 * @phpstan-type Value null|bool|int|float|string
 * @phpstan-type PlanValues array<string, null|bool|int|float|string|Reference>
 * @phpstan-type InsertAction array{entity: object, plan: EntityPlan<object>, values: PlanValues}
 * @phpstan-type UpdateAction array{entity: object, plan: EntityPlan<object>, id: int|string, version: int|null, snapshot: array<string, Value>, values: PlanValues, changes: PlanValues}
 * @phpstan-type DeleteAction array{entity: object, plan: EntityPlan<object>, id: int|string, version: int|null, snapshot: array<string, Value>}
 * @phpstan-type LinkAction array{plan: EntityPlan<object>, property: string, table: string, values: PlanValues}
 * @phpstan-type Draft array{kind: 'insert'|'update'|'delete'|'fixup'|'link'|'unlink', entity: object|null, plan: EntityPlan<object>, property: string|null, table: string|null, id: int|string|Reference|null, version: int|null, sent: PlanValues, values: PlanValues|null, snapshot: array<string, Value>|null, ordinal: int}
 * @phpstan-type Edge array{from: int, to: int, node: int, property: string|null, deferrable: bool}
 * @psalm-type Value = null|bool|int|float|string
 * @psalm-type PlanValues = array<string, null|bool|int|float|string|Reference>
 * @psalm-type InsertAction = array{entity: object, plan: EntityPlan<object>, values: PlanValues}
 * @psalm-type UpdateAction = array{entity: object, plan: EntityPlan<object>, id: int|string, version: int|null, snapshot: array<string, Value>, values: PlanValues, changes: PlanValues}
 * @psalm-type DeleteAction = array{entity: object, plan: EntityPlan<object>, id: int|string, version: int|null, snapshot: array<string, Value>}
 * @psalm-type LinkAction = array{plan: EntityPlan<object>, property: string, table: string, values: PlanValues}
 * @psalm-type Draft = array{kind: 'insert'|'update'|'delete'|'fixup'|'link'|'unlink', entity: object|null, plan: EntityPlan<object>, property: string|null, table: string|null, id: int|string|Reference|null, version: int|null, sent: PlanValues, values: PlanValues|null, snapshot: array<string, Value>|null, ordinal: int}
 * @psalm-type Edge = array{from: int, to: int, node: int, property: string|null, deferrable: bool}
 */
final class FlushPlanner
{
    /** @var list<Draft> */
    private array $nodes = [];

    /** @var list<Edge> */
    private array $edges = [];

    /** @var array<int, int> spl_object_id of an entity awaiting insert => its node */
    private array $insertOf = [];

    /** @var array<class-string, array<int|string, int>> the node deleting each scheduled row */
    private array $deleteOf = [];

    private int $ordinal = 0;

    /**
     * @param list<InsertAction> $inserts in scheduling order
     * @param list<UpdateAction> $updates
     * @param list<DeleteAction> $deletes
     * @param list<LinkAction> $links
     * @param list<LinkAction> $unlinks
     */
    private function __construct(array $inserts, array $updates, array $deletes, array $links, array $unlinks)
    {
        foreach ($unlinks as $unlink) {
            $this->nodes[] = $this->linkNode('unlink', $unlink);
        }

        foreach ($deletes as $delete) {
            $this->deleteOf[$delete['plan']->class][$delete['id']] = count($this->nodes);
            $this->nodes[] = [
                'kind' => 'delete',
                'entity' => $delete['entity'],
                'plan' => $delete['plan'],
                'property' => null,
                'table' => null,
                'id' => $delete['id'],
                'version' => $delete['version'],
                'sent' => [],
                'values' => null,
                'snapshot' => $delete['snapshot'],
                'ordinal' => $this->ordinal++,
            ];
        }

        foreach ($inserts as $insert) {
            /** @var int|string|null $id an identifier property is typed int or string, and a generated one is null here */
            $id = $insert['values'][$insert['plan']->id];
            $this->insertOf[spl_object_id($insert['entity'])] = count($this->nodes);
            $this->nodes[] = [
                'kind' => 'insert',
                'entity' => $insert['entity'],
                'plan' => $insert['plan'],
                'property' => null,
                'table' => null,
                'id' => $id,
                'version' => null,
                'sent' => $insert['values'],
                'values' => $insert['values'],
                'snapshot' => null,
                'ordinal' => $this->ordinal++,
            ];
        }

        foreach ($links as $link) {
            $this->nodes[] = $this->linkNode('link', $link);
        }

        foreach ($updates as $update) {
            $this->nodes[] = [
                'kind' => 'update',
                'entity' => $update['entity'],
                'plan' => $update['plan'],
                'property' => null,
                'table' => null,
                'id' => $update['id'],
                'version' => $update['version'],
                'sent' => $update['changes'],
                'values' => $update['values'],
                'snapshot' => $update['snapshot'],
                'ordinal' => $this->ordinal++,
            ];
        }
    }

    /**
     * @param list<InsertAction> $inserts in scheduling order
     * @param list<UpdateAction> $updates
     * @param list<DeleteAction> $deletes
     * @param list<LinkAction> $links the join rows to insert
     * @param list<LinkAction> $unlinks the join rows to delete, each matching the columns it names
     * @return list<PlanNode>
     * @throws InvalidEntityStateException for a foreign key or a link naming
     *         a row this flush deletes, and for a reference loop no nullable
     *         foreign key breaks
     */
    public static function plan(array $inserts, array $updates, array $deletes, array $links, array $unlinks): array
    {
        $planner = new self($inserts, $updates, $deletes, $links, $unlinks);
        $planner->connect();
        $order = $planner->schedule();

        return array_map(static fn (int $node): PlanNode => $planner->node($node), $order);
    }

    /**
     * @param 'link'|'unlink' $kind
     * @param LinkAction $action
     * @return Draft
     */
    private function linkNode(string $kind, array $action): array
    {
        return [
            'kind' => $kind,
            'entity' => null,
            'plan' => $action['plan'],
            'property' => $action['property'],
            'table' => $action['table'],
            'id' => null,
            'version' => null,
            'sent' => $action['values'],
            'values' => null,
            'snapshot' => null,
            'ordinal' => $this->ordinal++,
        ];
    }

    /**
     * One edge per foreign key and per link endpoint that decides order, and
     * the refusal for either naming a row this flush deletes. A reference to
     * a row awaiting insert whose identifier the application assigned becomes
     * that identifier here: only the edge is needed, not a deferred key.
     *
     * @throws InvalidEntityStateException
     */
    private function connect(): void
    {
        foreach ($this->nodes as $i => $node) {
            if ($node['kind'] === 'unlink') {
                $this->beforeOwnerDelete($i, $node);

                continue;
            }

            if ($node['kind'] === 'link') {
                $this->afterEndpoints($i, $node);

                continue;
            }

            $snapshot = $node['snapshot'];

            foreach ($node['plan']->relations() as $property => [$target, $nullable]) {
                /** @var int|string|null $old a relationship's database value is its target's identifier */
                $old = $snapshot === null ? null : $snapshot[$property];

                if ($node['kind'] === 'delete') {
                    $referenced = $old === null ? null : $this->deleteOf[$target][$old] ?? null;

                    if ($referenced !== null && $referenced !== $i) {
                        $this->edges[] = ['from' => $i, 'to' => $referenced, 'node' => $i, 'property' => $property, 'deferrable' => $nullable];
                    }

                    continue;
                }

                /** @var PlanValues $values a delete is the only node without them */
                $values = $node['values'];
                /** @var int|string|Reference|null $value a relationship's database value is its target's identifier */
                $value = $values[$property];

                if ($value instanceof Reference) {
                    // An aggregate removal can cancel the insert this key
                    // waits for, which leaves the referencing row unwritable.
                    $insert = $this->insertOf[$value->entity]
                        ?? throw InvalidEntityStateException::referencesRemovedRow($node['plan']->class, $property, $target);
                    $assigned = $this->nodes[$insert]['id'];

                    if ($assigned !== null) {
                        $this->nodes[$i]['values'][$property] = $assigned;

                        if (array_key_exists($property, $node['sent'])) {
                            $this->nodes[$i]['sent'][$property] = $assigned;
                        }
                    }

                    $this->edges[] = [
                        'from' => $insert,
                        'to' => $i,
                        'node' => $i,
                        'property' => $property,
                        'deferrable' => $nullable && $node['kind'] === 'insert',
                    ];
                } elseif ($value !== null && isset($this->deleteOf[$target][$value])) {
                    throw InvalidEntityStateException::referencesRemovedRow($node['plan']->class, $property, $target);
                }

                $moved = $old !== null && $old !== $value ? $this->deleteOf[$target][$old] ?? null : null;

                if ($moved !== null) {
                    $this->edges[] = ['from' => $i, 'to' => $moved, 'node' => $i, 'property' => $property, 'deferrable' => false];
                }
            }
        }
    }

    /**
     * The join rows an owner's DELETE leaves behind are deleted before it, so
     * the join table's foreign key to the owner never refuses that DELETE.
     * The owner's identifier is the first column a link statement names.
     *
     * @param Draft $node
     */
    private function beforeOwnerDelete(int $i, array $node): void
    {
        $owner = array_values($node['sent'])[0];
        $delete = is_int($owner) || is_string($owner) ? $this->deleteOf[$node['plan']->class][$owner] ?? null : null;

        if ($delete !== null) {
            $this->edges[] = ['from' => $i, 'to' => $delete, 'node' => $i, 'property' => null, 'deferrable' => false];
        }
    }

    /**
     * A link row exists only once both rows it names do, so each endpoint
     * awaiting insert runs first and hands the link its key. An endpoint this
     * flush removes — a row it deletes, or one whose insert an aggregate
     * removal cancelled — leaves the link unwritable.
     *
     * @param Draft $node
     * @throws InvalidEntityStateException
     */
    private function afterEndpoints(int $i, array $node): void
    {
        /** @var string $property a link node names the owning relationship whose table it writes */
        $property = $node['property'];
        $target = $node['plan']->target($property);
        $classes = [$node['plan']->class, $target];
        $end = 0;

        foreach ($node['sent'] as $column => $value) {
            $class = $classes[$end++];

            if ($value instanceof Reference) {
                $insert = $this->insertOf[$value->entity]
                    ?? throw InvalidEntityStateException::referencesRemovedRow($node['plan']->class, $property, $target);
                $assigned = $this->nodes[$insert]['id'];

                if ($assigned !== null) {
                    $this->nodes[$i]['sent'][$column] = $assigned;
                }

                $this->edges[] = ['from' => $insert, 'to' => $i, 'node' => $i, 'property' => null, 'deferrable' => false];

                continue;
            }

            if ((is_int($value) || is_string($value)) && isset($this->deleteOf[$class][$value])) {
                throw InvalidEntityStateException::referencesRemovedRow($node['plan']->class, $property, $target);
            }
        }
    }

    /**
     * The node order: every dependency first, and the stable tie-break where
     * none decides. A pass that cannot place every node breaks one loop and
     * runs again, so each pass either finishes or removes one edge.
     *
     * @return list<int>
     * @throws InvalidEntityStateException
     */
    private function schedule(): array
    {
        while (true) {
            [$byRank, $rank] = $this->ranks();
            $successors = [];
            $predecessors = [];
            $blocking = array_fill(0, count($this->nodes), 0);

            foreach ($this->edges as $edge) {
                $successors[$edge['from']][] = $edge['to'];
                $predecessors[$edge['to']][] = $edge['from'];
                $blocking[$edge['to']]++;
            }

            $ready = new SplMinHeap();

            foreach ($blocking as $node => $count) {
                if ($count === 0) {
                    $ready->insert($rank[$node]);
                }
            }

            $order = [];
            $placed = [];

            while (!$ready->isEmpty()) {
                /** @var int $next */
                $next = $ready->extract();
                $node = $byRank[$next];
                $order[] = $node;
                $placed[$node] = true;

                foreach ($successors[$node] ?? [] as $successor) {
                    if (--$blocking[$successor] === 0) {
                        $ready->insert($rank[$successor]);
                    }
                }
            }

            if (count($order) === count($this->nodes)) {
                return $order;
            }

            $this->defer($this->loop($placed, $predecessors, $rank), $rank);
        }
    }

    /**
     * One loop among the nodes no order could place. Every one of them still
     * has an unplaced node before it, so following those backwards reaches a
     * node twice, and the second visit closes the loop.
     *
     * @param array<int, true> $placed
     * @param array<int, list<int>> $predecessors
     * @param array<int, int> $rank
     * @return list<array{int, int}> the loop's edges, as from-to pairs
     */
    private function loop(array $placed, array $predecessors, array $rank): array
    {
        $unplaced = array_values(array_filter(array_keys($this->nodes), static fn (int $node): bool => !isset($placed[$node])));
        usort($unplaced, static fn (int $a, int $b): int => $rank[$a] <=> $rank[$b]);
        $before = [];
        $node = $unplaced[0];

        while (!isset($before[$node])) {
            $candidates = array_values(array_filter($predecessors[$node] ?? [], static fn (int $from): bool => !isset($placed[$from])));
            usort($candidates, static fn (int $a, int $b): int => $rank[$a] <=> $rank[$b]);
            $before[$node] = $candidates[0];
            $node = $candidates[0];
        }

        $loop = [];
        $current = $node;

        do {
            $loop[] = [$before[$current], $current];
            $current = $before[$current];
        } while ($current !== $node);

        return $loop;
    }

    /**
     * Defers the loop's first deferrable foreign key, in the order the plan
     * would otherwise take: an insert sends NULL and a fix-up writes the key
     * once both rows exist, and a delete's foreign key is nulled before
     * either row is deleted.
     *
     * @param list<array{int, int}> $loop
     * @param array<int, int> $rank
     * @throws InvalidEntityStateException when no foreign key on the loop is nullable
     */
    private function defer(array $loop, array $rank): void
    {
        // The foreign key of the node the plan would place first, so the
        // loop's rows keep the order they would take without it.
        $pairs = $loop;
        usort($pairs, static fn (array $a, array $b): int => $rank[$a[1]] <=> $rank[$b[1]] ?: $rank[$a[0]] <=> $rank[$b[0]]);
        $chosen = null;

        foreach ($pairs as [$from, $to]) {
            foreach ($this->edges as $i => $edge) {
                if ($edge['deferrable'] && $edge['from'] === $from && $edge['to'] === $to) {
                    $chosen = $i;

                    break 2;
                }
            }
        }

        if ($chosen === null) {
            throw InvalidEntityStateException::unbreakableCycle($this->names($loop));
        }

        $edge = $this->edges[$chosen];
        array_splice($this->edges, $chosen, 1);
        /** @var string $property an edge is deferrable only over a nullable foreign key */
        $property = $edge['property'];
        $node = $edge['node'];
        $draft = $this->nodes[$node];
        $fixup = count($this->nodes);

        if ($draft['kind'] === 'insert') {
            /** @var PlanValues $values an insert node always carries them */
            $values = $draft['values'];
            $this->nodes[$node]['sent'][$property] = null;
            /** @var object $entity an insert node always writes one */
            $entity = $draft['entity'];
            $this->nodes[] = $this->fixup($draft['plan'], $draft['id'] ?? new Reference(spl_object_id($entity)), [$property => $values[$property]]);
            $this->edges[] = ['from' => $node, 'to' => $fixup, 'node' => $fixup, 'property' => null, 'deferrable' => false];
            $this->edges[] = ['from' => $edge['from'], 'to' => $fixup, 'node' => $fixup, 'property' => null, 'deferrable' => false];

            return;
        }

        $this->nodes[] = $this->fixup($draft['plan'], $draft['id'], [$property => null]);
        $this->edges[] = ['from' => $fixup, 'to' => $node, 'node' => $fixup, 'property' => null, 'deferrable' => false];
        $this->edges[] = ['from' => $fixup, 'to' => $edge['to'], 'node' => $fixup, 'property' => null, 'deferrable' => false];
    }

    /**
     * @param EntityPlan<object> $plan
     * @param PlanValues $sent
     * @return Draft
     */
    private function fixup(EntityPlan $plan, int|string|Reference|null $id, array $sent): array
    {
        return [
            'kind' => 'fixup',
            'entity' => null,
            'plan' => $plan,
            'property' => null,
            'table' => null,
            'id' => $id,
            'version' => null,
            'sent' => $sent,
            'values' => null,
            'snapshot' => null,
            'ordinal' => $this->ordinal++,
        ];
    }

    /**
     * The classes and properties the loop runs through, for its refusal. An
     * identifier is never among them: it can be a secret.
     *
     * @param list<array{int, int}> $loop
     * @return list<string>
     */
    private function names(array $loop): array
    {
        $names = [];

        foreach ($loop as [$from, $to]) {
            foreach ($this->edges as $edge) {
                if ($edge['from'] === $from && $edge['to'] === $to && $edge['property'] !== null) {
                    $names[] = "{$this->nodes[$edge['node']]['plan']->class}::\${$edge['property']}";

                    break;
                }
            }
        }

        return $names;
    }

    /**
     * Every node by the order it takes where no dependency decides, and each
     * node's place in it.
     *
     * @return array{list<int>, array<int, int>}
     */
    private function ranks(): array
    {
        $byRank = array_keys($this->nodes);
        usort($byRank, fn (int $a, int $b): int => self::compare($this->nodes[$a], $this->nodes[$b]));
        $rank = [];

        foreach ($byRank as $place => $node) {
            $rank[$node] = $place;
        }

        return [$byRank, $rank];
    }

    private function node(int $node): PlanNode
    {
        $draft = $this->nodes[$node];

        return new PlanNode(
            $draft['kind'],
            $draft['plan'],
            $draft['entity'],
            $draft['id'],
            $draft['version'],
            $draft['sent'],
            $draft['values'],
            $draft['table'],
        );
    }

    /**
     * @param Draft $a
     * @param Draft $b
     */
    private static function compare(array $a, array $b): int
    {
        return self::category($a['kind']) <=> self::category($b['kind'])
            ?: strcmp($a['table'] ?? $a['plan']->class, $b['table'] ?? $b['plan']->class)
            ?: self::pair($a, $b)
            ?: self::known($a['id']) <=> self::known($b['id'])
            ?: self::identifier($a['id'], $b['id'])
            ?: $a['ordinal'] <=> $b['ordinal'];
    }

    private static function category(string $kind): int
    {
        return match ($kind) {
            'unlink' => 0,
            'delete' => 1,
            'insert' => 2,
            'link' => 3,
            default => 4,
        };
    }

    /**
     * The identifiers one join table's rows are ordered by: the owner's
     * first, the target's second, and none for an entity statement, whose
     * category never meets a link statement's here.
     *
     * @param Draft $a
     * @param Draft $b
     */
    private static function pair(array $a, array $b): int
    {
        if ($a['table'] === null) {
            return 0;
        }

        $first = array_values($a['sent']);
        $second = array_values($b['sent']);

        return self::known($first[0]) <=> self::known($second[0])
            ?: self::identifier($first[0], $second[0])
            ?: self::known($first[1] ?? null) <=> self::known($second[1] ?? null)
            ?: self::identifier($first[1] ?? null, $second[1] ?? null);
    }

    private static function known(null|bool|int|float|string|Reference $id): int
    {
        return $id === null || $id instanceof Reference ? 1 : 0;
    }

    /**
     * One class's identifiers share a type: an int compares numerically, a
     * string by its bytes. An identifier this flush has yet to generate
     * compares equal, leaving the scheduling ordinal to decide.
     */
    private static function identifier(
        null|bool|int|float|string|Reference $a,
        null|bool|int|float|string|Reference $b,
    ): int {
        if ($a === null || $b === null || $a instanceof Reference || $b instanceof Reference) {
            return 0;
        }

        return is_int($a) && is_int($b) ? $a <=> $b : strcmp((string) $a, (string) $b);
    }
}
