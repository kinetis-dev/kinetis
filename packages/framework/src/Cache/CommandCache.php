<?php

declare(strict_types=1);

namespace Kinetis\Cache;

use Kinetis\Cache\Exception\ArtifactValidation;
use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;

/**
 * Every registered command's plain scalar fields. Unlike HttpCache/
 * HttpCache, there are no binding/hydration plans to carry here at all:
 * CommandDispatcher does zero reflection at dispatch time —
 * CommandRegistry::register() already validated each method's signature
 * at registration time, so the command list itself is the entire
 * artifact.
 */
final readonly class CommandCache
{
    private const array TOP_LEVEL_KEYS = ['commands'];

    private const array COMMAND_ENTRY_KEYS = [
        'name', 'description', 'controllerClass', 'controllerMethod', 'takesArguments', 'bootstrap',
    ];

    private const string ARTIFACT_COMPONENT = 'CommandCache command';

    public function __construct(
        /** @var list<array{name:string,description:string,controllerClass:string,controllerMethod:string,takesArguments:bool,bootstrap:bool}> */
        public array $commands,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'commands' => $this->commands,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @throws CacheArtifactExceptionInterface
     */
    public static function fromArray(array $data): self
    {
        ArtifactValidation::exactKeys($data, 'CommandCache', self::TOP_LEVEL_KEYS);

        $commands = ArtifactValidation::listOfArrays($data, 'CommandCache', 'commands');

        /** @var list<array{name:string,description:string,controllerClass:string,controllerMethod:string,takesArguments:bool,bootstrap:bool}> $commands */
        $commands = array_map(self::validateCommandEntry(...), $commands);

        return new self(commands: $commands);
    }

    /**
     * @param array<array-key, mixed> $entry
     * @return array{name:string,description:string,controllerClass:string,controllerMethod:string,takesArguments:bool,bootstrap:bool}
     */
    private static function validateCommandEntry(array $entry): array
    {
        ArtifactValidation::exactKeys($entry, self::ARTIFACT_COMPONENT, self::COMMAND_ENTRY_KEYS);

        return [
            'name' => ArtifactValidation::string($entry, self::ARTIFACT_COMPONENT, 'name'),
            'description' => ArtifactValidation::string($entry, self::ARTIFACT_COMPONENT, 'description'),
            'controllerClass' => ArtifactValidation::string($entry, self::ARTIFACT_COMPONENT, 'controllerClass'),
            'controllerMethod' => ArtifactValidation::string($entry, self::ARTIFACT_COMPONENT, 'controllerMethod'),
            'takesArguments' => ArtifactValidation::bool($entry, self::ARTIFACT_COMPONENT, 'takesArguments'),
            'bootstrap' => ArtifactValidation::bool($entry, self::ARTIFACT_COMPONENT, 'bootstrap'),
        ];
    }
}
