<?php

declare(strict_types=1);

namespace Kinetis\Cache;

use Kinetis\Validation\Hydrator;

/**
 * Everything an MCP request (stdio via `bin/kinetis mcp:serve`, or a
 * consumer's own /mcp HTTP wiring) needs: tool/resource definitions, each
 * one's parameter-binding plan, and the validation plan for every DTO
 * reachable from an MCP tool specifically. Kept separate from
 * HttpCache/OpenApiCache for the same reason HttpCache is kept separate
 * from them — an HTTP request never needs to load this.
 *
 * @phpstan-import-type HydrationPlan from Hydrator
 */
final readonly class McpCache
{
    public function __construct(
        public int $formatVersion,
        /** @var list<array<string, mixed>> */
        public array $mcpTools,
        /** @var list<array<string, mixed>> */
        public array $mcpResources,
        /** @var array<string, list<array{name:string, isProgressReporter:bool, dtoClass:?string, scalarType:?string, hasDefault:bool, defaultValue:mixed}>> */
        public array $mcpBindingPlans,
        /** @var array<string, HydrationPlan> */
        public array $hydrationPlans,
        public string $compiledAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'formatVersion' => $this->formatVersion,
            'mcpTools' => $this->mcpTools,
            'mcpResources' => $this->mcpResources,
            'mcpBindingPlans' => $this->mcpBindingPlans,
            'hydrationPlans' => $this->hydrationPlans,
            'compiledAt' => $this->compiledAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array<string, mixed>> $mcpTools */
        $mcpTools = $data['mcpTools'];
        /** @var list<array<string, mixed>> $mcpResources */
        $mcpResources = $data['mcpResources'];
        /** @var array<string, list<array{name:string, isProgressReporter:bool, dtoClass:?string, scalarType:?string, hasDefault:bool, defaultValue:mixed}>> $mcpBindingPlans */
        $mcpBindingPlans = $data['mcpBindingPlans'];
        /** @var array<string, HydrationPlan> $hydrationPlans */
        $hydrationPlans = $data['hydrationPlans'];

        return new self(
            formatVersion: (int) $data['formatVersion'],
            mcpTools: $mcpTools,
            mcpResources: $mcpResources,
            mcpBindingPlans: $mcpBindingPlans,
            hydrationPlans: $hydrationPlans,
            compiledAt: (string) $data['compiledAt'],
        );
    }
}
