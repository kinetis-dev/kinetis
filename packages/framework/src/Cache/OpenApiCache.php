<?php

declare(strict_types=1);

namespace Kinetis\Cache;

/**
 * Just the generated OpenAPI 3.1 document — kept in its own file, loaded
 * lazily by Kernel only the instant a request actually hits /openapi.json
 * (see Kernel's $cacheStore), since this is often the bulkiest of the
 * three artifacts (verbose JSON-schema-shaped data per route/DTO) and the
 * least frequently requested in real production traffic.
 */
final readonly class OpenApiCache
{
    public function __construct(
        public int $formatVersion,
        /** @var array<string, mixed> */
        public array $openApi,
        public string $compiledAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'formatVersion' => $this->formatVersion,
            'openApi' => $this->openApi,
            'compiledAt' => $this->compiledAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $openApi */
        $openApi = $data['openApi'];

        return new self(
            formatVersion: (int) $data['formatVersion'],
            openApi: $openApi,
            compiledAt: (string) $data['compiledAt'],
        );
    }
}
