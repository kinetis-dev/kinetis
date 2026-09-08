<?php

declare(strict_types=1);

namespace Kinetis\Search;

/**
 * One write inside a {@see SearchClient::bulk()} request, as the action
 * line and optional document line both engines read. Building those
 * lines here rather than in each adapter is what makes a bulk batch
 * engine-neutral: an application names the operation, and nothing about
 * the on-the-wire NDJSON shape reaches it.
 *
 * An update carries a partial document and merges it into what the id
 * already holds; a create fails when the id already exists, where an
 * index replaces it.
 *
 * Every document here carries at least one field, for the reason
 * {@see SearchClient::index()} gives: a write of nothing is not an
 * operation either engine offers, and an update that would change
 * nothing is a call to leave out of the batch rather than one to send.
 */
final readonly class BulkOperation
{
    private const string INDEX = 'index';

    private const string CREATE = 'create';

    private const string UPDATE = 'update';

    private const string DELETE = 'delete';

    /**
     * @param non-empty-array<string, mixed>|null $document
     */
    private function __construct(
        private string $action,
        private string $index,
        private ?string $id,
        private ?array $document,
    ) {
    }

    /**
     * @param non-empty-array<string, mixed> $document
     */
    public static function index(string $index, ?string $id, array $document): self
    {
        return new self(self::INDEX, $index, $id, $document);
    }

    /**
     * @param non-empty-array<string, mixed> $document
     */
    public static function create(string $index, ?string $id, array $document): self
    {
        return new self(self::CREATE, $index, $id, $document);
    }

    /**
     * @param non-empty-array<string, mixed> $partialDocument
     */
    public static function update(string $index, string $id, array $partialDocument): self
    {
        return new self(self::UPDATE, $index, $id, $partialDocument);
    }

    public static function delete(string $index, string $id): self
    {
        return new self(self::DELETE, $index, $id, null);
    }

    /**
     * The whole bulk body for $operations, in order. Both engines' bulk
     * endpoints take it as a list of arrays and serialize the NDJSON
     * themselves, so this is the only place either adapter assembles
     * one.
     *
     * @param non-empty-array<self> $operations
     * @return list<array<string, mixed>>
     */
    public static function body(array $operations): array
    {
        $body = [];

        foreach ($operations as $operation) {
            foreach ($operation->lines() as $line) {
                $body[] = $line;
            }
        }

        return $body;
    }

    /**
     * The one or two body lines this operation contributes.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(): array
    {
        $target = ['_index' => $this->index];

        if ($this->id !== null) {
            $target['_id'] = $this->id;
        }

        $action = [[$this->action => $target]];

        if ($this->action === self::DELETE) {
            return $action;
        }

        // Only delete() omits a document, and it returned above.
        assert($this->document !== null);

        return $this->action === self::UPDATE
            ? [...$action, ['doc' => $this->document]]
            : [...$action, $this->document];
    }
}
