<?php

declare(strict_types=1);

namespace Kinetis\Cache;

/**
 * Every registered command's plain scalar fields. Unlike HttpCache/
 * McpCache, there are no binding/hydration plans to carry here at all:
 * CommandDispatcher does zero reflection at dispatch time —
 * CommandRegistry::register() already validated each method's signature
 * at registration time, so the command list itself is the entire
 * artifact.
 */
final readonly class CommandCache
{
    public function __construct(
        public int $formatVersion,
        /** @var list<array{name:string,description:string,controllerClass:string,controllerMethod:string,takesArguments:bool,bootstrap:bool}> */
        public array $commands,
        public string $compiledAt,
        /** @var list<class-string> */
        public array $packageBootstraps = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'formatVersion' => $this->formatVersion,
            'commands' => $this->commands,
            'packageBootstraps' => $this->packageBootstraps,
            'compiledAt' => $this->compiledAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array{name:string,description:string,controllerClass:string,controllerMethod:string,takesArguments:bool,bootstrap:bool}> $commands */
        $commands = $data['commands'];

        /** @var list<class-string> $packageBootstraps */
        $packageBootstraps = $data['packageBootstraps'];

        return new self(
            formatVersion: (int) $data['formatVersion'],
            commands: $commands,
            compiledAt: (string) $data['compiledAt'],
            packageBootstraps: $packageBootstraps,
        );
    }
}
